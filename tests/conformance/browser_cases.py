"""Proves what a browser was handed: a start attached to a pseudo-terminal, with a recorder
standing in for the platform's browser opener, follows the exact link a real browser would
receive instead of trusting only the printed line.
"""

import os
import shutil
import time
from http.cookiejar import CookieJar
from urllib.request import HTTPCookieProcessor, build_opener, urlopen

import harness

ADMIN_USER = "browser-admin"
ADMIN_PASSWORD = "Browser.test.password.2026"
LINK_PREFIX = "  Login:     "
URL_PREFIX = "  URL:       "


def _recorder_env(directory):
    """Put a recorder posing as this platform's browser opener first on PATH, so a start
    carries it down to entrypoint.go's exec.Command."""
    script, recorded = harness.install_recorder(directory)
    env = dict(os.environ, PATH=f"{script.parent}{os.pathsep}{os.environ.get('PATH', '')}")
    return env, recorded


class BrowserOpenCases(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        # One install serves every case that restarts on it: a start restarts the same Site data.
        cls.installed = cls.class_dir / "installed-data"
        shutil.rmtree(cls.installed, ignore_errors=True)
        env, recorded = _recorder_env(harness.fresh_dir(cls.class_dir / "install-recorder"))
        site = harness.Site(harness.BINARY, harness.fresh_dir(cls.class_dir / "install"))
        try:
            site.start(cls.installed, "--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD, env=env)
        finally:
            site.stop()
        # A start with no terminal opens no browser: the rest of the suite relies on it.
        if recorded.exists():
            raise AssertionError("a headless start with no terminal opened a browser")

    def setUp(self):
        super().setUp()
        self.data = self.installed

    def _own_data(self):
        """Points the case at a copy of the installed Site data, for a case that changes it."""
        self.data = self.case_dir / "data"
        shutil.rmtree(self.data, ignore_errors=True)
        shutil.copytree(self.installed, self.data, symlinks=True)

    def test_later_start_opens_browser_on_dashboard(self):
        env, recorded = _recorder_env(self.case_dir / "recorder")
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

    def test_the_browser_holds_no_lease(self):
        # A browser the server starts can outlive the server, and stop waits for the lease.
        if harness.current_platform() != harness.LINUX:
            self.skipTest("/proc lists a process's descriptors on Linux alone")
        env, recorded = _recorder_env(self.case_dir / "recorder")
        descriptors = self.case_dir / "recorder" / "descriptors"
        script = self.case_dir / "recorder" / harness.opener_name()
        script.write_text(f'#!/bin/sh\nfor fd in /proc/$$/fd/*; do readlink "$fd"; done > "{descriptors}"\n'
                          f'echo "$1" >> "{recorded}"\n')

        restart = harness.Site(harness.BINARY, self.case_dir / "restart")
        restart.start(self.data, attach_pty=True, env=env)
        try:
            harness.wait_for_recorded_url(recorded, harness.WAITS["browser_open"].seconds)
            held = [line for line in descriptors.read_text().splitlines() if line.endswith("serving.lock")]
            self.assertEqual(held, [], "the browser opener holds the Serving lease")
        finally:
            restart.stop()

    def test_no_browser_start_opens_nothing(self):
        env, recorded = _recorder_env(self.case_dir / "recorder")
        restart = harness.Site(harness.BINARY, self.case_dir / "restart")
        restart.start(self.data, "--no-browser", attach_pty=True, env=env)
        try:
            link = harness.wait_for_line(restart.log_path, 0, LINK_PREFIX, harness.WAITS["start"].seconds)
            self.assertIn("?destination=/admin/dashboard", link)
            # The positive case's budget, so a slow regression is still caught.
            time.sleep(harness.WAITS["browser_open"].seconds)
            self.assertFalse(recorded.exists(),
                              f"the recorder was called although --no-browser was given: inspect {recorded}")
        finally:
            restart.stop()

    def test_interactive_adoption_start_opens_browser_on_dashboard(self):
        # Site data holding settings but no completion marker opens the browser
        # for the adoption step it still owes.
        env, recorded = _recorder_env(self.case_dir / "recorder")
        self._own_data()
        (self.data / "site-installed").unlink()

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

    def test_a_failed_mint_opens_the_url_and_leaks_no_link(self):
        # A blocked administrator leaves no link, so the browser opens the site's URL.
        env, recorded = _recorder_env(self.case_dir / "recorder")
        self._own_data()
        # The copied settings.php names the shared database until a start on the copy rewrites it.
        rehome = harness.Site(harness.BINARY, self.case_dir / "rehome")
        rehome.start(self.data, env=env)
        rehome.stop()
        blocked = harness.run_drush(harness.BINARY, self.case_dir, self.data, "php:eval",
                                  r'\Drupal\user\Entity\User::load(1)->block()->save();')
        self.assertEqual(blocked.returncode, 0, blocked.stderr)

        restart = harness.Site(harness.BINARY, self.case_dir / "restart")
        restart.start(self.data, attach_pty=True, env=env)
        try:
            url = harness.wait_for_line(restart.log_path, 0, URL_PREFIX, harness.WAITS["start"].seconds)
            text = restart.log_path.read_text(errors="replace")
            self.assertIn(harness.LOGIN_COMMAND, text, "the summary does not print the command that mints a link")
            self.assertNotIn(harness.LINK_PATH, text, "the start printed a login link despite a failed mint")
            self.assertNotIn("No login link", text, "the start reports the blocked administrator")
            self.assertNotIn("blocked", text, "the start names the administrator's account state")
            self.assertIn(" user:login --name=NAME ", text, "the login command names no account to fill in")
            opened = harness.wait_for_recorded_url(recorded, harness.WAITS["browser_open"].seconds)
            self.assertEqual(recorded.read_text().splitlines(), [url], "the browser opened another page than the URL")
            self.assertEqual(opened, url)

            # /proc/<pid>/environ exists only on Linux, though the fact it proves (a failed mint
            # leaves no login link in the serving process's environment) is not platform-specific.
            if harness.current_platform() == harness.LINUX:
                with open(f"/proc/{restart.process.pid}/environ", "rb") as handle:
                    server_environment = handle.read().split(b"\0")
                self.assertIn(f"DRUPACK_RUNTIME_OPEN={url}".encode(), server_environment)
                self.assertFalse([entry for entry in server_environment if b"/user/reset/" in entry],
                                 "the serving process's environment carries a login link although the mint failed")
        finally:
            restart.stop()

    def test_a_start_on_every_interface_prints_the_link_and_opens_nothing(self):
        # 0.0.0.0 accepts other computers over plain HTTP, so the link reaches the terminal
        # alone, even with a person at it.
        env, recorded = _recorder_env(self.case_dir / "recorder")
        restart = harness.Site(harness.BINARY, self.case_dir / "restart")
        restart.start(self.data, bind="0.0.0.0", attach_pty=True, env=env)
        try:
            link = harness.wait_for_line(restart.log_path, 0, LINK_PREFIX, harness.WAITS["start"].seconds)
            self.assertIn("?destination=/admin/dashboard", link)
            self.assertIn("No browser opens:", restart.log_path.read_text(errors="replace"))
            time.sleep(harness.WAITS["browser_open"].seconds)
            self.assertFalse(recorded.exists(),
                             f"the recorder was called for a listener on every interface: inspect {recorded}")
        finally:
            restart.stop()

    def test_an_ipv6_host_prints_an_address_that_answers(self):
        # The wildcard listener also answers on 127.0.0.1, where the harness waits for it.
        data = self.case_dir / "data"
        site = harness.Site(harness.BINARY, self.case_dir / "ipv6")
        site.start(data, "--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD,
                   "--host", "::1", bind="[::]")
        try:
            url = harness.wait_for_line(site.log_path, 0, URL_PREFIX, harness.WAITS["start"].seconds)
            self.assertEqual(url, f"http://[::1]:{site.port}/")
            with urlopen(url + "user/login", timeout=harness.WAITS["http_request"].seconds) as response:
                self.assertEqual(response.status, 200)
            link = harness.login_link(harness.BINARY, self.case_dir, data)
            self.assertTrue(link.startswith(url), link)
            opener = build_opener(HTTPCookieProcessor(CookieJar()))
            with opener.open(link, timeout=harness.WAITS["http_request"].seconds) as response:
                self.assertIn("/admin/dashboard", response.geturl())
        finally:
            site.stop()
