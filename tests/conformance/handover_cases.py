"""A start whose address is already taken: the handover to its own running site, and the
refusals when it cannot hand over. The site's own server answers the identity route, so these
cases prove what a second start does without ever binding the default port.
"""

import os
import socket

import harness

ADMIN_USER = "handover-admin"
ADMIN_PASSWORD = "Handover.test.password.2026"


def _console_env(directory):
    """The environment a Windows file manager's double-click produces, with a recorder posing
    as the browser opener first on PATH. DRUPACK_RUNTIME_CONSOLE_OWNED is the launcher's own
    way to say a person is watching, and it needs no terminal of its own.
    """
    script, recorded = harness.install_recorder(directory)
    env = dict(
        os.environ,
        PATH=f"{script.parent}{os.pathsep}{os.environ.get('PATH', '')}",
        DRUPACK_RUNTIME_CONSOLE_OWNED="1",
    )
    return env, recorded


class HandoverCases(harness.ConformanceCase):
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

    def test_a_start_whose_site_already_serves_opens_the_browser(self):
        env, recorded = _console_env(self.case_dir / "recorder")
        site = harness.Site(harness.BINARY, self.case_dir / "serving")
        site.start(self.data, "--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD)
        try:
            second = harness.run(
                [str(harness.BINARY), "--data-dir", str(self.data),
                 "--listen", f"127.0.0.1:{site.port}"],
                cwd=self.case_dir, capture_output=True, text=True,
                timeout=harness.WAITS["start"].seconds, env=env,
            )
            self.assertEqual(second.returncode, 0, f"the handover exited non-zero: {second.stderr}")
            self.assertIn("already serving this Site data", second.stdout)

            opened = harness.wait_for_recorded_url(recorded, harness.WAITS["browser_open"].seconds)
            self.assertIn("/user/reset/1/", opened)
            self.assertIn("destination=/admin/dashboard", opened)
            self.assertEqual(site.fetch("/user/login")[0], 200, "the running site stopped answering")
        finally:
            site.stop()


class TakenPortCases(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)
        self.data = self.case_dir / "data"

    def test_a_port_another_program_holds_stops_the_start(self):
        port = harness.pick_port()
        holder = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        holder.bind(("127.0.0.1", port))
        holder.listen(1)
        try:
            refused = harness.run(
                [str(harness.BINARY), "--data-dir", str(self.data), "--listen", f"127.0.0.1:{port}"],
                cwd=self.case_dir, capture_output=True, text=True,
                timeout=harness.WAITS["refusal"].seconds,
            )
        finally:
            holder.close()
        self.assertNotEqual(refused.returncode, 0, "a start on a held port served anyway")
        self.assertIn(str(port), refused.stderr)
        self.assertFalse(
            (self.data / "listener").exists(),
            "a refused start recorded a listener it never served on",
        )

    def test_a_different_site_on_the_port_stops_the_start(self):
        site = harness.Site(harness.BINARY, self.case_dir / "serving")
        site.start(self.data, "--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD)
        try:
            other = self.case_dir / "other-data"
            # A person is present, so the token alone keeps this from becoming a handover:
            # the running server answers the identity route for different Site data.
            refused = harness.run(
                [str(harness.BINARY), "--data-dir", str(other),
                 "--listen", f"127.0.0.1:{site.port}"],
                cwd=self.case_dir, capture_output=True, text=True,
                timeout=harness.WAITS["refusal"].seconds,
                env=dict(os.environ, DRUPACK_RUNTIME_CONSOLE_OWNED="1"),
            )
            self.assertNotEqual(refused.returncode, 0, "a start served over another site's port")
            self.assertIn(str(site.port), refused.stderr)
            self.assertFalse(
                (other / "listener").exists(),
                "a refused start recorded a listener it never served on",
            )
        finally:
            site.stop()

    def test_a_second_start_with_no_terminal_stops(self):
        site = harness.Site(harness.BINARY, self.case_dir / "serving")
        site.start(self.data, "--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD)
        try:
            second = harness.run(
                [str(harness.BINARY), "--data-dir", str(self.data),
                 "--listen", f"127.0.0.1:{site.port}"],
                cwd=self.case_dir, capture_output=True, text=True,
                timeout=harness.WAITS["refusal"].seconds,
            )
            self.assertNotEqual(second.returncode, 0, "a second start with no terminal served anyway")
            self.assertIn("already serves this Site data", second.stderr)
            self.assertEqual(site.fetch("/user/login")[0], 200, "the running site stopped answering")
        finally:
            site.stop()
