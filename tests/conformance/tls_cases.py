"""A loopback start serves HTTPS when the machine trusts a local root, and HTTP otherwise.

Every case points DRUPACK_TLS_DIR at its own folder, so the user's real root never takes
part. Go's verifier on Linux reads SSL_CERT_FILE as the whole system trust store, which
stands in for an installed root without touching this machine's store.
"""

import os

import harness

# The root `trust` writes: ECDSA P-256 with a critical name constraint for loopback names.
ROOT_CONFIG = """[req]
distinguished_name=dn
x509_extensions=ext
prompt=no
[dn]
CN=Drupack test root
[ext]
basicConstraints=critical,CA:true
keyUsage=critical,keyCertSign,cRLSign
nameConstraints=critical,permitted;DNS:localhost,permitted;IP:127.0.0.0/255.0.0.0,permitted;IP:::1/ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff
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


class TlsSources(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX,)
    TOOLS = ("openssl",)

    @classmethod
    def setUpClass(cls):
        cls.data = cls.class_dir / "data"
        cls.root = write_root(cls.class_dir / "tls")
        # One install, which every case starts again under its own trust.
        site = harness.Site(harness.BINARY, cls.class_dir / "install")
        site.start(cls.data, env=cls.environment(cls.class_dir / "empty-tls", None))
        site.stop()

    @classmethod
    def environment(cls, tls_dir, trusted):
        env = dict(os.environ, DRUPACK_TLS_DIR=str(tls_dir))
        # An empty bundle trusts nothing, so the machine's own roots never count.
        bundle = cls.class_dir / "empty.pem"
        bundle.touch()
        env["SSL_CERT_FILE"] = str(trusted if trusted is not None else bundle)
        env.pop("SSL_CERT_DIR", None)
        return env

    def start(self, root, trusted):
        tls_dir = self.root.parent if root else self.case_dir / "tls"
        site = harness.Site(harness.BINARY, self.case_dir / "site", root=self.root if trusted else None)
        site.start(self.data, env=self.environment(tls_dir, self.root if trusted else None))
        self.addCleanup(site.stop)
        return site

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

    def test_a_certificate_names_only_loopback_hosts(self):
        site = self.start(root=True, trusted=True)
        served = harness.run(
            ["openssl", "s_client", "-connect", f"127.0.0.1:{site.port}", "-servername", "localhost",
             "-CAfile", str(self.root), "-verify_return_error"],
            input="", capture_output=True, text=True, timeout=30,
        )
        self.assertIn("Verify return code: 0 (ok)", served.stdout, served.stderr)

    def test_no_root_serves_http(self):
        site = self.start(root=False, trusted=False)
        status, _, _ = site.fetch("/user/login")
        self.assertEqual(status, 200)
        self.assertIn(f"URL:       http://localhost:{site.port}/", site.log_path.read_text(errors="replace"))

    def test_a_root_the_machine_does_not_trust_serves_http(self):
        site = self.start(root=True, trusted=False)
        status, _, _ = site.fetch("/user/login")
        self.assertEqual(status, 200)
        self.assertIn(f"URL:       http://localhost:{site.port}/", site.log_path.read_text(errors="replace"))

    def test_the_root_refuses_another_site(self):
        leaf = self.case_dir / "example"
        leaf.mkdir()
        harness.run(
            ["openssl", "req", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:P-256", "-nodes",
             "-keyout", str(leaf / "key.pem"), "-out", str(leaf / "csr.pem"), "-subj", "/CN=example.com",
             "-addext", "subjectAltName=DNS:example.com"],
            check=True, capture_output=True,
        )
        harness.run(
            ["openssl", "x509", "-req", "-in", str(leaf / "csr.pem"), "-CA", str(self.root),
             "-CAkey", str(self.root.parent / "root.key"), "-CAcreateserial", "-days", "1",
             "-copy_extensions", "copy", "-out", str(leaf / "cert.pem")],
            check=True, capture_output=True,
        )
        verified = harness.run(
            ["openssl", "verify", "-CAfile", str(self.root), str(leaf / "cert.pem")],
            capture_output=True, text=True,
        )
        self.assertNotEqual(verified.returncode, 0, verified.stdout)
        self.assertIn("permitted subtree violation", verified.stdout + verified.stderr)
