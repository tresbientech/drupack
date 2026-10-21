"""A loopback start serves HTTPS when the machine trusts a local root, and HTTP otherwise.

Every case points DRUPACK_TLS_DIR at its own folder, so the user's real root never takes
part. Go's verifier on Linux reads SSL_CERT_FILE as the whole system trust store, which
stands in for an installed root without touching this machine's store.
"""

import os
import shutil
import socket
import ssl
from pathlib import Path
from urllib.request import Request, urlopen

import harness

# The root `trust` writes: ECDSA P-256 with a critical name constraint for loopback names
# and drupack.site.
ROOT_CONFIG = """[req]
distinguished_name=dn
x509_extensions=ext
prompt=no
[dn]
CN=Drupack test root
[ext]
basicConstraints=critical,CA:true
keyUsage=critical,keyCertSign,cRLSign
nameConstraints=critical,permitted;DNS:localhost,permitted;DNS:drupack.site,permitted;IP:127.0.0.0/255.0.0.0,permitted;IP:::1/ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff
"""


def write_root(directory):
    """Writes root.pem and root.key into directory and returns root.pem's path."""
    directory.mkdir(parents=True, exist_ok=True)
    config = directory / "root.cnf"
    config.write_text(ROOT_CONFIG)
    harness.run(
        ["openssl", "req", "-x509", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:P-256", "-nodes",
         "-keyout", str(directory / "root.key"), "-out", str(directory / "root.pem"),
         "-days", "30", "-config", str(config)],
        check=True, capture_output=True,
    )
    return directory / "root.pem"


def trusted_environment(tls_dir, bundle):
    """The environment of a start whose local root lives in tls_dir and whose system trust
    store is bundle alone. Go's verifier on Linux reads SSL_CERT_FILE as the whole store."""
    env = dict(os.environ, DRUPACK_TLS_DIR=str(tls_dir), SSL_CERT_FILE=str(bundle))
    env.pop("SSL_CERT_DIR", None)
    # A real mkcert would come first, so only a case's own stub may be found.
    env["PATH"] = os.pathsep.join(
        folder for folder in env["PATH"].split(os.pathsep) if not (Path(folder) / "mkcert").exists()
    )
    return env


# Stands in for the reader's mkcert: reports CAROOT and signs a certificate for the names it
# receives, valid for DAYS days, and logs every call. It names openssl by path:
# trusted_environment drops the folder of a real mkcert, which can hold openssl too.
MKCERT_STUB = """#!/bin/sh
echo "$@" >> {calls}
if [ "$1" = -CAROOT ]; then echo {caroot}; exit 0; fi
{openssl} req -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout "$4" -out "$4.csr" \\
    -subj /CN=localhost -addext "subjectAltName=DNS:localhost,IP:127.0.0.1,IP:::1,DNS:*.drupack.site" 2>/dev/null
{openssl} x509 -req -in "$4.csr" -CA {caroot}/rootCA.pem -CAkey {caroot}/rootCA-key.pem -CAcreateserial \\
    -days {days} -copy_extensions copy -out "$2" 2>/dev/null
"""


def write_mkcert(directory, days):
    """Writes a CAROOT and a stub mkcert into directory; returns the stub's folder and its call log."""
    caroot = directory / "caroot"
    caroot.mkdir(parents=True)
    harness.run(
        ["openssl", "req", "-x509", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:P-256", "-nodes",
         "-keyout", str(caroot / "rootCA-key.pem"), "-out", str(caroot / "rootCA.pem"), "-days", "30",
         "-subj", "/CN=mkcert test root", "-addext", "basicConstraints=critical,CA:true",
         "-addext", "keyUsage=critical,keyCertSign,cRLSign"],
        check=True, capture_output=True,
    )
    bin_dir = directory / "bin"
    bin_dir.mkdir()
    calls = directory / "calls"
    stub = bin_dir / "mkcert"
    stub.write_text(MKCERT_STUB.format(calls=calls, caroot=caroot, days=days, openssl=shutil.which("openssl")))
    stub.chmod(0o755)
    return bin_dir, calls


def fetch_by_name(name, port, root):
    """The status of GET /user/login from the listener on 127.0.0.1 addressed as name, with
    the certificate verified for name against root alone."""
    context = ssl.create_default_context(cafile=str(root))
    with socket.create_connection(("127.0.0.1", port), timeout=harness.WAITS["http_request"].seconds) as raw:
        with context.wrap_socket(raw, server_hostname=name) as connection:
            connection.sendall(f"GET /user/login HTTP/1.1\r\nHost: {name}:{port}\r\nConnection: close\r\n\r\n".encode())
            return connection.recv(64).split(b" ")[1]


def site_host():
    """The Site host a start names: the site's subdomain, otherwise its name with dots as dashes."""
    label = harness.SITE.get("subdomain") or harness.SITE["name"].replace(".", "-")
    return f"{label}.drupack.site"


class TlsSources(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX,)
    TOOLS = ("openssl",)

    @classmethod
    def setUpClass(cls):
        cls.data = cls.class_dir / "data"
        cls.root = write_root(cls.class_dir / "tls")
        # One install, which every case starts again under its own trust.
        site = harness.Site(harness.BINARY, cls.class_dir / "install")
        site.start(cls.data, env=cls.environment(cls.class_dir / "empty-tls", []))
        site.stop()

    @classmethod
    def environment(cls, tls_dir, trusted):
        """trusted lists the roots the system trust store holds. An empty list trusts
        nothing, so the machine's own roots never count."""
        bundle = tls_dir.parent / f"system-{len(trusted)}-{abs(hash(tuple(map(str, trusted))))}.pem"
        bundle.parent.mkdir(parents=True, exist_ok=True)
        bundle.write_text("".join(Path(root).read_text() for root in trusted))
        return trusted_environment(tls_dir, bundle)

    def start(self, root, trusted, mkcert=None, name="site", bind="127.0.0.1", answer="none", options=()):
        tls_dir = self.root.parent if root else self.case_dir / "tls"
        system = [self.root] if trusted else []
        served = self.root if trusted and bind == "127.0.0.1" else None
        if mkcert is not None:
            served = mkcert.parent / "caroot" / "rootCA.pem"
            # `mkcert -install` puts its root in the system store.
            system.append(served)
        env = self.environment(tls_dir, system)
        env["DRUPACK_DNS_ANSWER"] = answer
        if mkcert is not None:
            env["PATH"] = f"{mkcert}{os.pathsep}{env['PATH']}"
        site = harness.Site(harness.BINARY, self.case_dir / name, root=served)
        site.start(self.data, *options, bind=bind, env=env)
        self.addCleanup(site.stop)
        return site

    def test_a_name_that_resolves_to_the_listener_names_the_url(self):
        alias = site_host()
        site = self.start(root=False, trusted=False, answer="127.0.0.1")
        log = site.log_path.read_text(errors="replace")
        self.assertIn(f"URL:       http://{alias}:{site.port}/", log)
        self.assertNotIn("did not resolve", log)
        request = Request(f"http://127.0.0.1:{site.port}/user/login", headers={"Host": f"{alias}:{site.port}"})
        with urlopen(request, timeout=harness.WAITS["http_request"].seconds) as response:
            self.assertEqual(response.status, 200)

    def test_a_trusted_root_serves_https_on_the_name(self):
        alias = site_host()
        site = self.start(root=True, trusted=True, answer="127.0.0.1")
        self.assertIn(f"URL:       https://{alias}:{site.port}/", site.log_path.read_text(errors="replace"))
        self.assertEqual(fetch_by_name(alias, site.port, self.root), b"200")

    def test_mkcert_serves_https_on_the_name(self):
        alias = site_host()
        stub, _ = write_mkcert(self.case_dir / "mkcert", 800)
        site = self.start(root=False, trusted=False, mkcert=stub, answer="127.0.0.1")
        self.assertIn(f"URL:       https://{alias}:{site.port}/", site.log_path.read_text(errors="replace"))
        self.assertEqual(fetch_by_name(alias, site.port, stub.parent / "caroot" / "rootCA.pem"), b"200")

    def test_drush_addresses_the_name_its_last_start_served(self):
        alias = site_host()
        site = self.start(root=False, trusted=False, answer="127.0.0.1")
        site.stop()
        login = harness.run_drush(harness.BINARY, self.case_dir, self.data, "user:login")
        self.assertEqual(login.returncode, 0, login.stderr)
        self.assertTrue(login.stdout.strip().startswith(f"http://{alias}:{site.port}/user/reset/"), login.stdout)

    def test_host_localhost_keeps_localhost_when_the_name_resolves(self):
        site = self.start(root=False, trusted=False, answer="127.0.0.1", options=("--host", "localhost"))
        log = site.log_path.read_text(errors="replace")
        self.assertIn(f"URL:       http://localhost:{site.port}/", log)
        self.assertNotIn("did not resolve", log)

    def test_a_name_the_resolver_filters_falls_back_to_localhost(self):
        site = self.start(root=False, trusted=False, answer="0.0.0.0")
        log = site.log_path.read_text(errors="replace")
        self.assertIn(f"URL:       http://localhost:{site.port}/", log)
        self.assertIn("did not resolve to 127.0.0.1 (answered 0.0.0.0), so the URL uses localhost.", log)

    def test_mkcert_serves_https(self):
        stub, calls = write_mkcert(self.case_dir / "mkcert", 800)
        site = self.start(root=False, trusted=False, mkcert=stub)
        self.assertEqual(site.fetch("/user/login")[0], 200)
        self.assertIn(f"URL:       https://localhost:{site.port}/", site.log_path.read_text(errors="replace"))
        ran = calls.read_text()
        self.assertIn("-cert-file", ran)
        # Drupack never asks mkcert to change the machine's trust.
        self.assertNotIn("-install", ran)

    def test_mkcert_comes_before_a_trusted_root(self):
        stub, _ = write_mkcert(self.case_dir / "mkcert", 800)
        # The harness verifies against mkcert's root, so a site serving the Drupack root fails to start here.
        site = self.start(root=True, trusted=True, mkcert=stub)
        self.assertEqual(site.fetch("/user/login")[0], 200)

    def test_an_mkcert_certificate_near_expiry_is_written_again(self):
        stub, calls = write_mkcert(self.case_dir / "mkcert", 10)
        tls_dir = self.case_dir / "tls"
        for name in ("first", "second"):
            site = self.start(root=False, trusted=False, mkcert=stub, name=name)
            site.stop()
        self.assertEqual(calls.read_text().count("-cert-file"), 2)
        self.assertTrue((tls_dir / "mkcert" / "localhost.pem").exists())

    def test_a_trusted_root_serves_https(self):
        site = self.start(root=True, trusted=True)
        status, _, _ = site.fetch("/user/login")
        self.assertEqual(status, 200)
        log = site.log_path.read_text(errors="replace")
        self.assertIn(f"URL:       https://localhost:{site.port}/", log)
        self.assertIn(f"Login:     https://localhost:{site.port}/user/reset/", log)
        drush = harness.run_drush(harness.BINARY, self.case_dir, self.data,
                                  "php:eval", "echo getenv('DRUSH_OPTIONS_URI');")
        self.assertEqual(drush.stdout.strip(), f"https://localhost:{site.port}/", drush.stderr)

    def test_a_new_root_on_the_same_site_data_serves_https_from_it(self):
        # The first start leaves Caddy's intermediate for the class root in Site data.
        self.start(root=True, trusted=True).stop()
        root = write_root(self.case_dir / "tls")
        site = harness.Site(harness.BINARY, self.case_dir / "new", root=root)
        site.start(self.data, env=self.environment(root.parent, [root]))
        self.addCleanup(site.stop)
        status, _, _ = site.fetch("/user/login")
        self.assertEqual(status, 200)

    def test_the_served_certificate_verifies_for_localhost(self):
        site = self.start(root=True, trusted=True)
        served = harness.run(
            ["openssl", "s_client", "-connect", f"127.0.0.1:{site.port}", "-servername", "localhost",
             "-CAfile", str(self.root), "-verify_return_error"],
            capture_output=True, text=True, timeout=30,
        )
        self.assertIn("Verify return code: 0 (ok)", served.stdout, served.stderr)

    def test_no_root_serves_http_and_names_trust(self):
        site = self.start(root=False, trusted=False)
        status, _, _ = site.fetch("/user/login")
        self.assertEqual(status, 200)
        log = site.log_path.read_text(errors="replace")
        self.assertIn(f"URL:       http://localhost:{site.port}/", log)
        self.assertIn('HTTPS: run "', log)

    def test_a_non_loopback_listener_serves_http(self):
        site = self.start(root=True, trusted=True, bind="0.0.0.0")
        self.assertEqual(site.fetch("/user/login")[0], 200)
        log = site.log_path.read_text(errors="replace")
        self.assertIn(f"URL:       http://localhost:{site.port}/", log)
        self.assertNotIn('HTTPS: run "', log)

    def test_an_untrusted_mkcert_root_falls_through_to_http(self):
        stub, _ = write_mkcert(self.case_dir / "mkcert", 800)
        env = self.environment(self.case_dir / "tls", [])
        env["PATH"] = f"{stub}{os.pathsep}{env['PATH']}"
        site = harness.Site(harness.BINARY, self.case_dir / "site")
        site.start(self.data, env=env)
        self.addCleanup(site.stop)
        self.assertIn(f"URL:       http://localhost:{site.port}/", site.log_path.read_text(errors="replace"))

    def test_a_root_the_machine_does_not_trust_serves_http(self):
        site = self.start(root=True, trusted=False)
        status, _, _ = site.fetch("/user/login")
        self.assertEqual(status, 200)
        self.assertIn(f"URL:       http://localhost:{site.port}/", site.log_path.read_text(errors="replace"))



# python:3.13-slim, pinned like network_cases.py. Its ca-certificates package
# gives truststore update-ca-certificates, and it has no sudo, so truststore runs as root.
TRUST_IMAGE = "python:3.13-slim@sha256:8d9d0b8bcf6506481eae4907c18f5e3e7902e629f5f6d684f9e7c32e85e3ddf0"

# Runs inside the container. Each section opens with a marker line the case splits on.
TRUST_SCRIPT = """set -e
b=/site/binary
section() { echo "=== $1"; }
section install; $b trust
section mode; stat -c %a /tls/root.key
section root; cat /tls/root.pem
section installed; cat /etc/ssl/certs/ca-certificates.crt
section again; $b trust
section root-again; cat /tls/root.pem
section uninstall; $b trust --uninstall
section removed; cat /etc/ssl/certs/ca-certificates.crt
section files; ls -A /tls
"""


def sections(output):
    """Splits TRUST_SCRIPT's output into its sections by name."""
    found = {}
    name = None
    for line in output.splitlines():
        if line.startswith("=== "):
            name = line[4:]
            found[name] = []
        elif name is not None:
            found[name].append(line)
    return {key: "\n".join(lines) for key, lines in found.items()}


def pem_body(pem):
    return "".join(line for line in pem.splitlines() if not line.startswith("-----"))


class TrustCommand(harness.ConformanceCase):
    """`trust` writes a system trust store, so it runs as root in a throwaway container."""

    PLATFORMS = (harness.LINUX,)
    TOOLS = ("docker", "openssl")
    RECIPE = False

    def test_trust_installs_reuses_and_removes_the_root(self):
        result = harness.run(
            ["docker", "run", "--rm", "-i", "--user", "0", "-e", "DRUPACK_TLS_DIR=/tls",
             "-v", f"{harness.BINARY}:/site/binary:ro", "--entrypoint", "/bin/sh", TRUST_IMAGE, "-s"],
            # harness.run closes standard input unless told otherwise; the script arrives on it.
            stdin=None, input=TRUST_SCRIPT, capture_output=True, text=True, timeout=300,
        )
        (self.case_dir / "trust.log").write_text(result.stdout + result.stderr)
        self.assertEqual(result.returncode, 0, f"inspect {self.case_dir / 'trust.log'}")
        found = sections(result.stdout)
        self.assertIn("System trust store: installed.", found["install"])
        self.assertIn("Firefox (NSS): skipped", found["install"])
        self.assertEqual(found["mode"].strip(), "600")
        root = pem_body(found["root"])
        self.assertIn(root, pem_body(found["installed"]))
        self.assertEqual(found["root-again"], found["root"], "a second trust made a new root")
        self.assertNotIn("Created the local root", found["again"])
        self.assertIn("System trust store: removed.", found["uninstall"])
        self.assertNotIn(root, pem_body(found["removed"]))
        self.assertEqual(found["files"].strip(), "")

        written = self.case_dir / "root.pem"
        written.write_text(found["root"] + "\n")
        constraints = harness.run(["openssl", "x509", "-in", str(written), "-noout", "-ext", "nameConstraints"],
                                  capture_output=True, text=True, check=True).stdout
        self.assertIn("critical", constraints)
        for subtree in ("DNS:localhost", "DNS:drupack.site", "IP:127.0.0.0/255.0.0.0", "IP:0:0:0:0:0:0:0:1/FFFF:FFFF:FFFF:FFFF:FFFF:FFFF:FFFF:FFFF"):
            self.assertIn(subtree, constraints)


class SystemTrust(harness.ConformanceCase):
    """`trust` on the machine running the suite: macOS's System keychain or the Windows
    Root store. A start serves HTTPS only when the system's own verifier accepts the
    root, so each start's URL reports what the store holds. Both systems ask a person to
    confirm each change, so this case runs by hand, never on a CI runner."""

    PLATFORMS = (harness.MACOS, harness.WINDOWS)
    SYSTEM_TRUST = True

    def trust(self, env, *arguments):
        # The system's confirmation prompt waits here for the person running the case.
        result = harness.run([str(harness.BINARY), "trust", *arguments], env=env,
                             capture_output=True, text=True, timeout=120)
        log = self.case_dir / f"trust{''.join(arguments)}.log"
        log.write_text(result.stdout + result.stderr)
        self.assertEqual(result.returncode, 0, f"inspect {log}")
        return result.stdout

    def start(self, tls_dir, name, root=None):
        site = harness.Site(harness.BINARY, self.case_dir / name, root=root)
        site.start(self.class_dir / "data", env=dict(os.environ, DRUPACK_TLS_DIR=str(tls_dir)))
        log = site.log_path.read_text(errors="replace")
        site.stop()
        return site.port, log

    def test_trust_serves_https_until_uninstalled(self):
        tls_dir = self.case_dir / "tls"
        self.assertIn("System trust store: installed.", self.trust(dict(os.environ, DRUPACK_TLS_DIR=str(tls_dir))))
        port, log = self.start(tls_dir, "trusted", root=tls_dir / "root.pem")
        self.assertIn(f"URL:       https://localhost:{port}/", log)

        kept = self.case_dir / "kept"
        shutil.copytree(tls_dir, kept)
        self.trust(dict(os.environ, DRUPACK_TLS_DIR=str(tls_dir)), "--uninstall")
        self.assertFalse((tls_dir / "root.pem").exists())
        # The same root, now absent from the store, no longer qualifies.
        port, log = self.start(kept, "removed")
        self.assertIn(f"URL:       http://localhost:{port}/", log)
