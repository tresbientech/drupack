"""A start whose address is already taken: the handover to its own running site, and the
refusals when it cannot hand over. A serving start holds a lease over its Site data, so
these cases prove what a second start does without ever binding the default port.
"""

import http.server
import os
import socket
import threading

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


class _AnswersEverything(http.server.BaseHTTPRequestHandler):
    """Poses as a Drupack server: 204 for any request, the answer the identity route gave."""

    def do_GET(self):
        self.send_response(204)
        self.end_headers()

    def log_message(self, *arguments):
        pass


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

    def test_a_listener_that_answers_everything_receives_no_login_link(self):
        """A 204 from the port proves nothing: any local program can answer one.

        This installs a site, stops it, then puts an impostor on the port that site served.
        The start must refuse rather than mint a one-time administrator link and open a
        browser at a listener it never verified.
        """
        env, recorded = _console_env(self.case_dir / "recorder")
        site = harness.Site(harness.BINARY, self.case_dir / "serving")
        site.start(self.data, "--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD)
        port = site.port
        site.stop()

        impostor = http.server.HTTPServer(("127.0.0.1", port), _AnswersEverything)
        threading.Thread(target=impostor.serve_forever, daemon=True).start()
        try:
            start = harness.run(
                [str(harness.BINARY), "--data-dir", str(self.data), "--listen", f"127.0.0.1:{port}"],
                cwd=self.case_dir, capture_output=True, text=True,
                timeout=harness.WAITS["refusal"].seconds, env=env,
            )
        finally:
            impostor.shutdown()
        self.assertNotEqual(start.returncode, 0, "a start handed over to a listener it never verified")
        self.assertNotIn("Login:", start.stdout)
        self.assertFalse(recorded.exists(), f"a browser opened at the impostor: {recorded}")


class ServingLeaseCases(harness.ConformanceCase):
    """One server per Site data, whatever address a second start asks for.

    Two FrankenPHP processes over one database and one runtime directory corrupt both, so
    the claim belongs to the Site data. A port probe cannot make it: a second start on
    another port finds that port free, and a listener that answers on the first port
    proves nothing about which site it serves.
    """

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

    def test_a_second_start_on_another_port_refuses(self):
        site = harness.Site(harness.BINARY, self.case_dir / "serving")
        site.start(self.data, "--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD)
        try:
            free = harness.pick_port()
            second = harness.run(
                [str(harness.BINARY), "--data-dir", str(self.data), "--listen", f"127.0.0.1:{free}"],
                cwd=self.case_dir, capture_output=True, text=True,
                timeout=harness.WAITS["refusal"].seconds,
            )
            self.assertNotEqual(second.returncode, 0, "a second server started on a free port")
            self.assertIn("already serves this Site data", second.stderr)
            # The refusal names where the running server answers, not the port asked for.
            self.assertIn(str(site.port), second.stderr)
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
            # A person is present, so the lease alone keeps this from becoming a handover:
            # this Site data has no server of its own, and the port belongs to another site.
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
