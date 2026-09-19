#!/usr/bin/env python3
"""Exercise a seeded embedded Drupal site without network access."""

import json
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from http.cookiejar import CookieJar
from urllib.error import HTTPError, URLError
from urllib.request import HTTPCookieProcessor, Request, build_opener, urlopen


BINARY = Path(sys.argv.pop(1)).resolve()
RESULTS = Path(sys.argv.pop(1)).resolve()
PORT = 7225
ORIGIN = f"http://localhost:{PORT}"
ADMIN_USER = "drupack-test-admin"
ADMIN_PASSWORD = "Offline.test.administrator.2026!"
CREDENTIALS = ("--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD)
LINK_PREFIX = "  Login:     "
READY_LINE = "Drupal is ready."


def fetch(path):
    request = Request(ORIGIN + path)
    with urlopen(request, timeout=30) as response:
        return response.status, response.headers, response.read().decode(errors="replace")


def http(path):
    return fetch(path)[2]


def wait_until(check, timeout=120):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            if check():
                return
        except (URLError, ConnectionError):
            pass
        time.sleep(0.25)
    raise AssertionError(f"Timed out after {timeout}s")


# Reads one line of a running server's output, from the offset the case started at.
def wait_for_line(log, offset, prefix, timeout=180):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        for line in log.read_text(errors="replace")[offset:].splitlines():
            if line.startswith(prefix):
                return line[len(prefix):].strip()
        time.sleep(0.25)
    raise AssertionError(f"No line starting with {prefix!r} in {log}")


class SeededSite(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        RESULTS.mkdir(parents=True, exist_ok=True)
        cls.work = Path(tempfile.mkdtemp(prefix="site-", dir=RESULTS))
        cls.binary = cls.work / "drupack"
        shutil.copyfile(BINARY, cls.binary)
        cls.binary.chmod(0o700)
        cls.server_log = open(RESULTS / "server.log", "w")
        cls.server = None

    @classmethod
    def tearDownClass(cls):
        cls.stop()
        cls.server_log.close()

    @classmethod
    def start(cls, data, *options):
        cls.ready_offset = (RESULTS / "server.log").stat().st_size
        cls.server = subprocess.Popen([
            str(cls.binary), "--data-dir", str(data), *options,
        ], cwd=cls.work, stdout=cls.server_log, stderr=subprocess.STDOUT,
           start_new_session=True)
        # A server that never reports ready still holds the port, and every later case would
        # then answer from it rather than from its own site.
        try:
            wait_until(cls.ready, timeout=180)
        except AssertionError:
            cls.stop()
            raise

    # The port accepts before the site can answer, and the runtime's own first request
    # is still running then. Stopping a server mid-request makes it wait out that
    # request, so a case that starts on the port alone can hang its own teardown.
    @classmethod
    def ready(cls):
        if cls.server.poll() is not None:
            raise AssertionError(f"Runtime exited: inspect {RESULTS / 'server.log'}")
        return READY_LINE in (RESULTS / "server.log").read_text(errors="replace")[cls.ready_offset:]

    @classmethod
    def stop(cls):
        if cls.server is not None and cls.server.poll() is None:
            os.killpg(cls.server.pid, signal.SIGTERM)
            try:
                # The runtime forces its own exit after 10s, so reaching this is a defect
                # rather than a slow machine. Killing it keeps one case from stalling the run.
                cls.server.wait(timeout=30)
            except subprocess.TimeoutExpired:
                os.killpg(cls.server.pid, signal.SIGKILL)
                cls.server.wait()
                raise AssertionError(f"Server ignored SIGTERM: inspect {RESULTS / 'server.log'}")

    def run_dr(self, data, *command):
        return subprocess.run([
            str(self.binary), "dr", "--data-dir", str(data), *command,
        ], cwd=self.work, capture_output=True, text=True, timeout=120)

    def test_extensions(self):
        with tempfile.NamedTemporaryFile(mode="w", suffix=".php", dir=self.work) as probe:
            probe.write("<?php echo json_encode([get_loaded_extensions(), PDO::getAvailableDrivers()]);")
            probe.flush()
            result = subprocess.run([str(self.binary), "php-cli", probe.name], cwd=self.work,
                                    capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        extensions, drivers = json.loads(result.stdout)
        self.assertIn("pdo_pgsql", {extension.lower() for extension in extensions})
        self.assertTrue({"mysql", "pgsql", "sqlite"} <= set(drivers), drivers)

    def test_startup_ignores_working_directory_script(self):
        script = self.work / "launch.php"
        script.write_text("<?php fwrite(STDERR, 'working directory script executed'); exit(42);")
        data = self.work / "hostile-directory"
        try:
            self.start(data, *CREDENTIALS)
            self.assertEqual(fetch("/user/login")[0], 200)
            bootstrap = self.run_dr(data, "status", "--field=bootstrap")
            self.assertEqual(bootstrap.returncode, 0, bootstrap.stderr)
            self.assertEqual(bootstrap.stdout.strip(), "Successful")
        finally:
            try:
                self.stop()
            finally:
                script.unlink()

    def test_seeded_sqlite_site_and_drush(self):
        data = self.work / "data"
        self.start(data, *CREDENTIALS)
        try:
            # Latency guard: catches a cron-triggered stall without waiting out the full 240s limit.
            requested = time.monotonic()
            homepage = http("/")
            elapsed = time.monotonic() - requested
            self.assertLess(elapsed, 20, "the first full request waited on cron")
            self.assertNotIn("core/install.php", homepage)
            self.assertNotIn("Choose language", homepage)
            self.assertTrue((data / "site.sqlite").is_file())
            self.assertTrue((data / "settings.php").is_file())
            self.assertTrue((data / "files").is_dir())
            status = self.run_dr(data, "status", "--format=json")
            self.assertEqual(status.returncode, 0, status.stderr)
            self.assertIn("drupal-version", status.stdout)
            mcp_tools = self.run_dr(data, "mcp-tools:client-config")
            self.assertEqual(mcp_tools.returncode, 0, mcp_tools.stderr)
            self.assertIn("mcp", mcp_tools.stdout.lower())
            # Deterministic check: the Seed site never enables these modules, regardless of timing.
            enabled = self.run_dr(data, "pm:list", "--status=enabled", "--format=json")
            self.assertEqual(enabled.returncode, 0, enabled.stderr)
            modules = json.loads(enabled.stdout)
            self.assertNotIn("automatic_updates", modules)
            self.assertNotIn("package_manager", modules)
        finally:
            self.stop()
        self.start(data, *CREDENTIALS)
        try:
            self.assertNotIn("core/install.php", http("/"))
        finally:
            self.stop()

    def test_first_start_without_credentials(self):
        # No options and no terminal: the start installs a site on the default port and hands
        # its reader a way in. Following the printed link must log a browser in as admin.
        data = self.work / "no-credentials"
        log = RESULTS / "server.log"
        offset = log.stat().st_size
        self.start(data)
        try:
            link = wait_for_line(log, offset, LINK_PREFIX)
            self.assertTrue(link.startswith(ORIGIN + "/user/reset/1/"), link)
            # A browser reaches the link only once the site has answered, so follow the
            # product's own order instead of racing the first cold request.
            wait_for_line(log, offset, READY_LINE)
            opener = build_opener(HTTPCookieProcessor(CookieJar()))
            with opener.open(link, timeout=30) as response:
                landing = response.geturl()
                body = response.read().decode(errors="replace")
            self.assertIn("/user/1/edit", landing)
            self.assertIn('value="admin"', body)
            # The same page refuses an anonymous request, so the link supplied the session.
            with self.assertRaises(HTTPError) as error:
                http("/user/1/edit")
            self.assertEqual(error.exception.code, 403)
        finally:
            self.stop()

    def test_protected_files(self):
        data = self.work / "protected"
        self.start(data, *CREDENTIALS)
        try:
            for path in ["/site.sqlite", "/private/probe.txt", "/sites/default/settings.php"]:
                with self.subTest(path=path):
                    with self.assertRaises(HTTPError) as error:
                        http(path)
                    self.assertIn(error.exception.code, [403, 404])
        finally:
            self.stop()

    def test_public_storage_blocks_php_execution(self):
        # Writable public storage must never execute PHP: FrankenPHP still
        # runs a script when a PATH_INFO suffix follows it, so the barrier
        # has to catch that case, an uppercase extension, and encoded paths,
        # not only a filename ending exactly in ".php". Win32 also strips
        # trailing dots and spaces from a path component, so a name ending
        # in one of those still opens the ".php" file underneath on the
        # Windows build. Fixtures are written by the test itself so no
        # executable probe ships in the repository.
        data = self.work / "php-barrier"
        self.start(data, *CREDENTIALS)
        try:
            files = data / "files"
            (files / "probe.php").write_text("<?php echo 'executed';")
            (files / "PROBE.PHP").write_text("<?php echo 'executed';")
            (files / "ordinary.txt").write_text("not executable")
            blocked = [
                "/sites/default/files/probe.php",
                "/sites/default/files/probe.php/path-info",
                "/sites/default/files/PROBE.PHP",
                "/sites/default/files/probe%2ephp/path-info",
                "/sites/default/files/probe.php.",
                "/sites/default/files/probe.php%20",
                "/sites/default/files/probe.php%2fpath-info",
            ]
            for path in blocked:
                with self.subTest(path=path):
                    try:
                        status, _, body = fetch(path)
                    except HTTPError as error:
                        self.assertIn(error.code, [403, 404])
                    else:
                        # A 200 here is a bug regardless of body: either the
                        # script executed, or the barrier missed it and
                        # file_server leaked the source instead. Show both
                        # so the two failure modes are not confused.
                        self.fail(f"expected {path} to be blocked, got {status}: {body!r}")
            self.assertEqual(http("/sites/default/files/ordinary.txt"), "not executable")
        finally:
            self.stop()

    def test_public_storage_cache_headers(self):
        # An upload can be replaced at the same URL, so it must revalidate
        # rather than serve a year-old cached copy. A versioned application
        # asset's URL changes with its content, so it can cache immutably.
        data = self.work / "cache-headers"
        self.start(data, *CREDENTIALS)
        try:
            (data / "files" / "upload.png").write_bytes(b"not-a-real-png")
            _, headers, _ = fetch("/sites/default/files/upload.png")
            self.assertEqual(headers.get("Cache-Control"), "max-age=0,must-revalidate")
            _, headers, _ = fetch("/core/misc/drupal.js")
            self.assertEqual(headers.get("Cache-Control"), "max-age=31536000,public,immutable")
            homepage = http("/")
            candidates = re.findall(r'/sites/default/files/styles/[^"\'\s]+', homepage)
            # A responsive-image srcset also carries an unresolved "{width}"
            # template entry; skip it in favor of a concrete derivative URL.
            derivative = next((url for url in candidates if "%7B" not in url), None)
            self.assertIsNotNone(derivative, "expected an image style derivative on the homepage")
            # The first request generates the derivative; Drupal serves that response
            # itself. The file exists on disk from the second request onward. Caddy's
            # own cache headers apply only to that second request.
            status, _, _ = fetch(derivative)
            self.assertEqual(status, 200)
            status, headers, _ = fetch(derivative)
            self.assertEqual(status, 200)
            self.assertEqual(headers.get("Cache-Control"), "max-age=31536000,public,immutable")
        finally:
            self.stop()


if __name__ == "__main__":
    unittest.main(verbosity=2)
