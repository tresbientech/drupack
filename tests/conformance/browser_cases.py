"""Proves what a browser was handed: a start attached to a pseudo-terminal, with a recorder
standing in for the platform's browser opener, follows the exact link a real browser would
receive instead of trusting only the printed line.
"""

import os
import time
from http.cookiejar import CookieJar
from urllib.request import HTTPCookieProcessor, build_opener

import harness

ADMIN_USER = "browser-admin"
ADMIN_PASSWORD = "Browser.test.password.2026"
LINK_PREFIX = "  Login:     "


def _recorder_env(directory):
    """Put a recorder posing as this platform's browser opener first on PATH, so a start
    under it carries the override all the way down to entrypoint.go's own exec.Command.
    """
    script, recorded = harness.install_recorder(directory)
    env = dict(os.environ, PATH=f"{script.parent}{os.pathsep}{os.environ.get('PATH', '')}")
    return env, recorded


class BrowserOpenCases(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)
        self.data = self.case_dir / "data"

    def _install(self, env, recorded):
        # Headless, like every other case's first start: proves a start with no terminal
        # opens no browser, the precondition the rest of the suite relies on.
        site = harness.Site(harness.BINARY, self.case_dir / "install")
        site.start(self.data, "--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD, env=env)
        site.stop()
        self.assertFalse(recorded.exists(), "a headless start with no terminal opened a browser")

    def test_later_start_opens_browser_on_dashboard(self):
        env, recorded = _recorder_env(self.case_dir / "recorder")
        self._install(env, recorded)

        restart = harness.Site(harness.BINARY, self.case_dir / "restart")
        restart.start(self.data, attach_pty=True, env=env)
        try:
            link = harness.wait_for_line(restart.log_path, 0, LINK_PREFIX, harness.WAITS["start"].seconds)
            self.assertIn("?destination=/admin/dashboard", link)

            recorded_url = harness.wait_for_recorded_url(recorded, harness.WAITS["browser_open"].seconds)
            self.assertEqual(recorded_url, link, "the recorder received a different URL than the printed link")

            opener = build_opener(HTTPCookieProcessor(CookieJar()))
            with opener.open(link, timeout=harness.WAITS["http_request"].seconds) as response:
                landing = response.geturl()
            self.assertIn("/admin/dashboard", landing, landing)
        finally:
            restart.stop()

    def test_no_browser_start_opens_nothing(self):
        env, recorded = _recorder_env(self.case_dir / "recorder")
        self._install(env, recorded)

        restart = harness.Site(harness.BINARY, self.case_dir / "restart")
        restart.start(self.data, "--no-browser", attach_pty=True, env=env)
        try:
            link = harness.wait_for_line(restart.log_path, 0, LINK_PREFIX, harness.WAITS["start"].seconds)
            self.assertIn("?destination=/admin/dashboard", link)
            # Bounded by the same budget the positive case gives a real browser-open, so a
            # slow regression is still caught instead of a check that races ahead of it.
            time.sleep(harness.WAITS["browser_open"].seconds)
            self.assertFalse(recorded.exists(),
                              f"the recorder was called although --no-browser was given: inspect {recorded}")
        finally:
            restart.stop()

    def test_interactive_adoption_start_opens_browser_on_dashboard(self):
        # Site data holding settings but no completion marker already took the
        # browser-opening branch before this phase: remainingSteps() returns ['adopt'], a
        # non-empty list, so the step requirement this phase drops was already satisfied for
        # it. No case had run a start under a terminal to prove it until this one.
        env, recorded = _recorder_env(self.case_dir / "recorder")
        self._install(env, recorded)
        # missing_ok: a rerun into the same results directory can find this already gone,
        # if the last run's own assertions failed before adoption recreated it.
        (self.data / "site-installed").unlink(missing_ok=True)

        restart = harness.Site(harness.BINARY, self.case_dir / "restart")
        restart.start(self.data, attach_pty=True, env=env)
        try:
            link = harness.wait_for_line(restart.log_path, 0, LINK_PREFIX, harness.WAITS["start"].seconds)
            self.assertIn("?destination=/admin/dashboard", link)
            recorded_url = harness.wait_for_recorded_url(recorded, harness.WAITS["browser_open"].seconds)
            self.assertEqual(recorded_url, link)
            self.assertTrue((self.data / "site-installed").exists(),
                             "the adoption start recorded no completion marker")
        finally:
            restart.stop()

    def test_a_failed_mint_opens_no_browser_and_leaks_no_link(self):
        # A blocked administrator leaves the start with no link to open, so the terminal it
        # runs under, which would otherwise qualify it to open one, must not matter here.
        env, recorded = _recorder_env(self.case_dir / "recorder")
        self._install(env, recorded)
        blocked = harness.run_dr(harness.BINARY, self.case_dir, self.data, "php:eval",
                                  r'\Drupal\user\Entity\User::load(1)->block()->save();')
        self.assertEqual(blocked.returncode, 0, blocked.stderr)

        restart = harness.Site(harness.BINARY, self.case_dir / "restart")
        restart.start(self.data, attach_pty=True, env=env)
        try:
            text = restart.log_path.read_text(errors="replace")
            self.assertNotIn(LINK_PREFIX, text, "the readiness block printed a login link despite a failed mint")
            # Bounded by the same budget the positive case gives a real browser-open, so a
            # regression that opens one late is still caught instead of a check that races
            # ahead of it.
            time.sleep(harness.WAITS["browser_open"].seconds)
            self.assertFalse(recorded.exists(),
                              f"the recorder was called although the mint failed: inspect {recorded}")

            # /proc/<pid>/environ exists only on Linux; the fact it proves here, that a
            # failed mint leaves no login link in the serving process's own environment,
            # is not itself platform-specific, only this way of checking it.
            if harness.current_platform() == harness.LINUX:
                with open(f"/proc/{restart.process.pid}/environ", "rb") as handle:
                    server_environment = handle.read()
                self.assertNotIn(b"DRUPACK_RUNTIME_OPEN", server_environment,
                                  "the serving process's environment carries a login link "
                                  "although the mint failed")
        finally:
            restart.stop()
