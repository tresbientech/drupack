"""SITE stop: ending a site a start serves in the foreground, what it says when nothing runs,
the stop channel's own token check and loopback binding, and a server the previous release
started.
"""

import os
import socket
import unittest
from pathlib import Path

import harness

ADMIN_USER = "stop-admin"
ADMIN_PASSWORD = "Stop.test.password.2026"


def _stop(data, *options, cwd):
    return harness.run(
        [str(harness.BINARY), "stop", "--data-dir", str(data), *options],
        cwd=cwd, capture_output=True, text=True, timeout=harness.WAITS["stop"].seconds + 30,
    )


def _refuses(host, port):
    try:
        socket.create_connection((host, port), timeout=harness.WAITS["port_closed"].seconds).close()
    except OSError:
        return True
    return False


class StopCases(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

    def setUp(self):
        super().setUp()
        self.data = self.case_dir / "data"

    def _serve(self, *options, **keywords):
        site = harness.Site(harness.BINARY, self.case_dir / "serving")
        site.start(self.data, "--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD, *options, **keywords)
        self.addCleanup(site.stop)
        return site

    def test_stop_ends_a_running_site_frees_the_lease_and_closes_the_port(self):
        site = self._serve()
        result = _stop(self.data, cwd=self.case_dir)
        self.assertEqual(result.returncode, 0, f"stop exited non-zero: {result.stderr}")
        self.assertIn("stopped", result.stdout)
        site.process.wait(timeout=harness.WAITS["stop"].seconds)
        self.assertTrue(_refuses("127.0.0.1", site.port), "the site's port still accepts connections")
        again = _stop(self.data, cwd=self.case_dir)
        self.assertEqual(again.returncode, 0, f"the lease stayed held: {again.stderr}")
        self.assertIn("is not running", again.stdout)

    def test_stop_unpacks_nothing(self):
        # The launcher creates the cache root owner-only, and refuses one any other user can read.
        cache = harness.reserved_dir(self.case_dir / "cache")
        result = harness.run([str(harness.BINARY), "stop", "--data-dir", str(self.case_dir / "never-made")],
                             cwd=self.case_dir, env={**os.environ, "DRUPACK_CACHE_DIR": str(cache)},
                             capture_output=True, text=True, timeout=harness.WAITS["refusal"].seconds)
        self.assertEqual(result.returncode, 0, f"stop exited non-zero: {result.stderr}")
        self.assertIn("is not running", result.stdout)
        self.assertEqual(sorted(path.name for path in cache.iterdir()), [], "stop unpacked into the cache")


class PreviousReleaseStop(harness.ConformanceCase):
    """DRUPACK_TEST_PREVIOUS names the executable of the site's previous release."""

    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        previous = os.environ.get("DRUPACK_TEST_PREVIOUS")
        if not previous:
            raise unittest.SkipTest("DRUPACK_TEST_PREVIOUS names no previous release")
        cls.previous = Path(previous).resolve()

    def test_stop_ends_a_server_the_previous_release_started(self):
        data = self.case_dir / "data"
        earlier = harness.Site(self.previous, self.case_dir / "previous")
        earlier.start(data, "--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD)
        self.addCleanup(earlier.stop)
        result = _stop(data, cwd=self.case_dir)
        self.assertEqual(result.returncode, 0, f"stop exited non-zero: {result.stderr}")
        self.assertIn("stopped", result.stdout)
        earlier.process.wait(timeout=harness.WAITS["stop"].seconds)
        self.assertTrue(_refuses("127.0.0.1", earlier.port), "the previous release's port still accepts connections")
