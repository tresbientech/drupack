"""SITE stop: ending a site a start serves in the foreground and freeing its lease and port,
what it says when nothing runs, a stop that unpacks nothing, and a server the previous
release started.
"""

import os
import socket
import time
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


# Takes the lease as the previous release's PHP does, signals argv[2] once it holds it, and
# releases it by exiting once argv[3] exists or argv[4] seconds pass.
_FLOCK_HOLDER = """<?php
$handle = fopen($argv[1], 'c+');
flock($handle, LOCK_EX);
file_put_contents($argv[2], '');
$deadline = microtime(true) + (float) $argv[4];
while (microtime(true) < $deadline && !file_exists($argv[3])) {
    usleep(250000);
}
"""


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


class WindowsStopCases(harness.ConformanceCase):
    PLATFORMS = (harness.WINDOWS,)

    def test_stop_reports_a_server_when_php_flock_holds_the_lease(self):
        data = self.case_dir / "data"
        data.mkdir()
        script = self.case_dir / "holder.php"
        script.write_text(_FLOCK_HOLDER)
        ack = self.case_dir / "lock-acquired"
        release = self.case_dir / "release-lock"
        holder = harness.popen([str(harness.BINARY), "php-cli", str(script), str(data / "serving.lock"),
                                str(ack), str(release), str(harness.WAITS["lock_hold"].seconds)],
                               cwd=self.case_dir)
        self.addCleanup(holder.wait, harness.WAITS["stop"].seconds)
        self.addCleanup(release.touch)
        deadline = time.monotonic() + harness.WAITS["lock_ack"].seconds
        while time.monotonic() < deadline and not ack.exists():
            time.sleep(0.05)
        self.assertTrue(ack.exists(), "the PHP holder did not take serving.lock")
        # No stop record exists, so the stop reports a start still preparing.
        result = _stop(data, cwd=self.case_dir)
        self.assertNotIn("is not running", result.stdout, "the lease probe read a PHP flock holder as no server")
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn("start is still preparing this Site data", result.stderr)


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
