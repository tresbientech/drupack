#!/usr/bin/env python3
"""Exercise a seeded embedded Drupal site without network access."""

import json
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


BINARY = Path(sys.argv.pop(1)).resolve()
RESULTS = Path(sys.argv.pop(1)).resolve()
ORIGIN = "http://localhost:8080"
ADMIN_USER = "drupack-test-admin"
ADMIN_PASSWORD = "Offline.test.administrator.2026!"


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
    def start(cls, data):
        cls.server = subprocess.Popen([
            str(cls.binary), "--data-dir", str(data), "--admin-user", ADMIN_USER,
            "--admin-password", ADMIN_PASSWORD,
        ], cwd=cls.work, stdout=cls.server_log, stderr=subprocess.STDOUT,
           start_new_session=True)
        wait_until(cls.ready)

    @classmethod
    def ready(cls):
        if cls.server.poll() is not None:
            raise AssertionError(f"Runtime exited: inspect {RESULTS / 'server.log'}")
        with socket.create_connection(("127.0.0.1", 8080), timeout=1):
            return True

    @classmethod
    def stop(cls):
        if cls.server is not None and cls.server.poll() is None:
            os.killpg(cls.server.pid, signal.SIGTERM)
            cls.server.wait(timeout=60)

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

    def test_seeded_sqlite_site_and_drush(self):
        data = self.work / "data"
        self.start(data)
        try:
            homepage = http("/")
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
        finally:
            self.stop()
        self.start(data)
        try:
            self.assertNotIn("core/install.php", http("/"))
        finally:
            self.stop()

    def test_first_start_needs_administrator_credentials(self):
        data = self.work / "missing-admin"
        result = subprocess.run([str(self.binary), "--data-dir", str(data)], cwd=self.work,
                                capture_output=True, text=True, timeout=30)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Missing Drupal administrator credentials", result.stderr)
        self.assertFalse(data.exists())

    def test_protected_files(self):
        data = self.work / "protected"
        self.start(data)
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
        # not only a filename ending exactly in ".php". Fixtures are written
        # by the test itself so no executable probe ships in the repository.
        data = self.work / "php-barrier"
        self.start(data)
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
            ]
            for path in blocked:
                with self.subTest(path=path):
                    with self.assertRaises(HTTPError) as error:
                        http(path)
                    self.assertIn(error.exception.code, [403, 404])
            self.assertEqual(http("/sites/default/files/ordinary.txt"), "not executable")
        finally:
            self.stop()

    def test_public_storage_cache_headers(self):
        # An upload can be replaced at the same URL, so it must revalidate
        # rather than serve a year-old cached copy. A versioned application
        # asset's URL changes with its content, so it can cache immutably.
        data = self.work / "cache-headers"
        self.start(data)
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
            status, _, _ = fetch(derivative)
            self.assertEqual(status, 200)
        finally:
            self.stop()


if __name__ == "__main__":
    unittest.main(verbosity=2)
