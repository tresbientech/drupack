"""Ported from tests/network.sh: the default listener refusing another container, an explicit
listener serving Drupal to one while protecting private paths, and a Site data path holding a
space. Every case here is Linux only, matching where the old file ran in CI, and needs Docker
for its own site container, a client container, and the internal network between them.

The old file's third argument, an installed-data fixture no caller passed, is dropped: every
case here runs a fresh first start instead.
"""

import os
import subprocess
import time

import harness

ADMIN_USER = "network-admin"
ADMIN_PASSWORD = "Network.test.administrator.2026!"
SPACE_ADMIN_USER = "space-admin"
SPACE_ADMIN_PASSWORD = "Network.space.test.2026!"
# Printed once Drupal's own poller confirms bootstrap; initialization_cases.py and
# site_cases.py assert the same line from a host-run start's own log.

# debian, pinned the way launcher_cases.py pins its own copy of the same image:
# docker buildx imagetools inspect debian --format '{{.Manifest.Digest}}'
DEBIAN_IMAGE = "debian@sha256:88200866dfff7ea7f5cbcb6ec7c8a701889efe6fe859fe64d6990e4b07ea4171"

# python:3.13-slim, pinned the way offline_cases.py pins its own copy of the same image, the
# multi-platform index digest so the arm64 runner resolves it too:
# docker buildx imagetools inspect python:3.13-slim --format '{{.Manifest.Digest}}'
CLIENT_IMAGE = "python:3.13-slim@sha256:8d9d0b8bcf6506481eae4907c18f5e3e7902e629f5f6d684f9e7c32e85e3ddf0"

# The client probes the site's default port, which the start below serves on.
LOOPBACK_PROBE = """
import socket, sys

try:
    connection = socket.create_connection(("site", int(sys.argv[1])), timeout=3)
except OSError:
    print("PASS: default listener rejects access from another container")
else:
    connection.close()
    raise AssertionError("Default listener accepted remote access")
"""

LISTENER_PROBE = """
from http.cookiejar import CookieJar
import urllib.error
import urllib.request

opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(CookieJar()))
request = urllib.request.Request("http://site:8080/", headers={"User-Agent": "DrupackNetworkTest"})
with opener.open(request, timeout=60) as response:
    assert response.status == 200
    assert "/core/install.php" not in response.url
for path in ["/sites/default/settings.php", "/site.sqlite", "/private/secret.txt", "/sites/default/files/php/test.php"]:
    try:
        opener.open("http://site:8080" + path, timeout=10)
    except urllib.error.HTTPError as error:
        assert error.code in (403, 404), (path, error.code)
    else:
        raise AssertionError("Private path accessible: " + path)
print("PASS: explicit listener serves Drupal to another container and protects private paths")
"""

SPACE_PROBE = """
import urllib.request

with urllib.request.urlopen("http://space:8080/user/login", timeout=30) as response:
    assert response.status == 200
print("PASS: a Site data path with a space serves the login page")
"""


def _run_client(network, script, log_path, *args):
    """Run script with args inside the client image on network; return its exit code, log its output."""
    command = [
        "docker", "run", "--rm", "-i", "--network", network,
        "--entrypoint", "/usr/local/bin/python3", CLIENT_IMAGE, "-", *args,
    ]
    with open(log_path, "w") as handle:
        result = subprocess.run(
            command, input=script, stdout=handle, stderr=subprocess.STDOUT, text=True,
            timeout=harness.WAITS["network_client"].seconds,
        )
    return result.returncode


def _start_site_container(name, network, alias, data_src, data_dst, args, log_path):
    """docker run -d the site under name on network, aliased as alias; wait for its ready line
    in its own log, since it runs cut off from the host network this suite polls elsewhere.
    """
    subprocess.run(
        ["docker", "run", "-d", "--name", name, "--network", network, "--network-alias", alias,
         "--user", f"{os.getuid()}:{os.getgid()}", "--workdir", "/site",
         "-e", "DRUPACK_CACHE_DIR=/cache",
         "--mount", f"type=bind,src={harness.BINARY},dst=/artifact/drupack,readonly",
         "--mount", f"type=bind,src={data_src},dst={data_dst}",
         "--mount", f"type=bind,src={os.environ['DRUPACK_CACHE_DIR']},dst=/cache",
         DEBIAN_IMAGE, "/artifact/drupack", *args],
        check=True, capture_output=True, text=True, timeout=harness.WAITS["network_container"].seconds,
    )
    deadline = time.monotonic() + harness.WAITS["start"].seconds
    while time.monotonic() < deadline:
        logs = subprocess.run(
            ["docker", "logs", name], stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            text=True, timeout=harness.WAITS["docker_admin"].seconds,
        ).stdout
        log_path.write_text(logs)
        if harness.ready_line() in logs:
            return
        time.sleep(1)
    raise AssertionError(
        f"{name} did not print {harness.ready_line()!r} within {harness.WAITS['start'].seconds}s: inspect {log_path}"
    )


def _remove_site_container(name, log_path):
    """Capture the container's final log, then remove it; either command may fail harmlessly
    if the container was never created, so neither is checked.
    """
    with open(log_path, "w") as handle:
        subprocess.run(["docker", "logs", name], stdout=handle, stderr=subprocess.STDOUT,
                        timeout=harness.WAITS["docker_admin"].seconds)
    subprocess.run(["docker", "rm", "-f", name], capture_output=True, timeout=harness.WAITS["docker_admin"].seconds)


class NetworkListener(harness.ConformanceCase):
    """Marked linux and docker: the site runs in its own container on an internal network,
    reached only by a second, client container, never by the host.
    """

    PLATFORMS = (harness.LINUX,)
    TOOLS = ("docker",)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)
        cls.network = f"drupack-network-{os.getpid()}"
        # The only setUpClass step: a failure here creates nothing to remove, unlike
        # server_database_cases.py's readiness probe after its container already exists.
        subprocess.run(["docker", "network", "create", "--internal", cls.network],
                        check=True, capture_output=True, timeout=harness.WAITS["docker_admin"].seconds)

    @classmethod
    def tearDownClass(cls):
        subprocess.run(["docker", "network", "rm", cls.network], capture_output=True,
                        timeout=harness.WAITS["docker_admin"].seconds)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def test_default_listener_refuses_another_container(self):
        data = harness.fresh_dir(self.case_dir / "data")
        name = f"drupack-network-site-{os.getpid()}"
        log_path = self.case_dir / "site.log"
        args = ["--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD]
        try:
            _start_site_container(name, self.network, "site", data, "/site/data", args, log_path)
            code = _run_client(self.network, LOOPBACK_PROBE, self.case_dir / "client.log", str(harness.SITE["port"]))
            self.assertEqual(code, 0, f"inspect {self.case_dir / 'client.log'}")
        finally:
            _remove_site_container(name, log_path)

    def test_explicit_listener_serves_the_site_and_protects_private_paths(self):
        data = harness.fresh_dir(self.case_dir / "data")
        name = f"drupack-network-listen-{os.getpid()}"
        log_path = self.case_dir / "site.log"
        args = ["--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD,
                "--listen", "0.0.0.0:8080", "--host", "site"]
        try:
            _start_site_container(name, self.network, "site", data, "/site/data", args, log_path)
            (data / "files" / "php").mkdir(parents=True, exist_ok=True)
            (data / "private" / "secret.txt").write_text("private-network-sentinel")
            (data / "files" / "php" / "test.php").write_text('<?php echo "php-network-sentinel";')
            code = _run_client(self.network, LISTENER_PROBE, self.case_dir / "client.log")
            self.assertEqual(code, 0, f"inspect {self.case_dir / 'client.log'}")
        finally:
            _remove_site_container(name, log_path)

    def test_a_site_data_path_with_a_space_serves_the_login_page(self):
        space_root = harness.fresh_dir(self.case_dir / "space-root")
        name = f"drupack-network-space-{os.getpid()}"
        log_path = self.case_dir / "site.log"
        args = ["--admin-user", SPACE_ADMIN_USER, "--admin-password", SPACE_ADMIN_PASSWORD,
                "--data-dir", "/site/data with space", "--listen", "0.0.0.0:8080", "--host", "space"]
        try:
            _start_site_container(name, self.network, "space", space_root, "/site", args, log_path)
            code = _run_client(self.network, SPACE_PROBE, self.case_dir / "client.log")
            self.assertEqual(code, 0, f"inspect {self.case_dir / 'client.log'}")
            caddy_log = space_root / "data with space" / "logs" / "caddy.log"
            self.assertTrue(
                caddy_log.is_file() and caddy_log.stat().st_size > 0,
                "a Site data path with a space wrote no log file",
            )
        finally:
            _remove_site_container(name, log_path)
