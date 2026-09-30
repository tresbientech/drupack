"""SITE stop: ending a site a start serves in the foreground, what it says when nothing runs,
and the stop channel's own token check and loopback binding.
"""

import json
import socket
import stat
import urllib.error
import urllib.request

import harness

ADMIN_USER = "stop-admin"
ADMIN_PASSWORD = "Stop.test.password.2026"


def _stop(data, *options, cwd):
    return harness.run(
        [str(harness.BINARY), "stop", "--data-dir", str(data), *options],
        cwd=cwd, capture_output=True, text=True, timeout=harness.WAITS["stop"].seconds + 30,
    )


def _post(port, path="/stop", method="POST", authorization=None, host="127.0.0.1"):
    """The status the stop channel answers, without following or raising on an error status."""
    request = urllib.request.Request(f"http://{host}:{port}{path}", method=method,
                                     data=b"" if method == "POST" else None)
    if authorization is not None:
        request.add_header("Authorization", authorization)
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    try:
        with opener.open(request, timeout=harness.WAITS["http_request"].seconds) as response:
            return response.status
    except urllib.error.HTTPError as error:
        return error.code


def _refuses(host, port):
    try:
        socket.create_connection((host, port), timeout=harness.WAITS["port_closed"].seconds).close()
    except OSError:
        return True
    return False


def _other_address():
    """An address of this computer that is not loopback, or None when it has none."""
    try:
        address = socket.gethostbyname(socket.gethostname())
    except OSError:
        return None
    return None if address.startswith("127.") else address


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

    def _record(self):
        return json.loads((self.data / "stop.json").read_text())

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

    def test_stop_with_no_running_site_says_so_beside_a_stale_record(self):
        site = self._serve()
        site.stop()
        self.assertTrue((self.data / "stop.json").exists(), "the server left no record to go stale")
        result = _stop(self.data, cwd=self.case_dir)
        self.assertEqual(result.returncode, 0, f"stop exited non-zero: {result.stderr}")
        self.assertIn("is not running", result.stdout)

    def test_stop_on_a_path_that_holds_no_site_creates_nothing(self):
        missing = self.case_dir / "never-made"
        result = _stop(missing, cwd=self.case_dir)
        self.assertEqual(result.returncode, 0, f"stop exited non-zero: {result.stderr}")
        self.assertIn("is not running", result.stdout)
        self.assertFalse(missing.exists(), "stop created the Site data it was asked about")

    def test_stop_refuses_an_option_that_is_not_data_dir(self):
        result = harness.run([str(harness.BINARY), "stop", "--listen", "127.0.0.1:1"], cwd=self.case_dir,
                             capture_output=True, text=True, timeout=harness.WAITS["refusal"].seconds)
        self.assertEqual(result.returncode, 1)
        self.assertIn("Unknown argument: --listen", result.stderr)

    def test_a_wrong_or_missing_token_leaves_the_site_serving(self):
        site = self._serve()
        port = self._record()["port"]
        self.assertEqual(_post(port, authorization="Bearer " + "0" * 64), 403)
        self.assertEqual(_post(port), 403)
        self.assertEqual(_post(port, method="GET", authorization="Bearer " + self._record()["token"]), 404)
        self.assertEqual(_post(port, path="/"), 404)
        self.assertEqual(site.fetch("/user/login")[0], 200, "the site stopped answering")
        self.assertIsNone(site.process.poll(), "the site exited")

    def test_the_stop_channel_answers_on_loopback_alone_under_a_wildcard_listener(self):
        other = _other_address()
        if other is None:
            self.skipTest("this computer has no address besides loopback")
        site = self._serve(bind="0.0.0.0")
        self.assertFalse(_refuses(other, site.port), f"the site does not listen on {other}, so the probe proves nothing")
        port = self._record()["port"]
        self.assertEqual(_post(port), 403, "the channel does not answer on loopback")
        self.assertTrue(_refuses(other, port), f"the stop channel accepted a connection on {other}")


class StopRecordModeCases(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS)

    def test_the_stop_record_is_readable_by_its_owner_alone(self):
        data = self.case_dir / "data"
        site = harness.Site(harness.BINARY, self.case_dir / "serving")
        site.start(data)
        self.addCleanup(site.stop)
        mode = stat.S_IMODE((data / "stop.json").stat().st_mode)
        self.assertEqual(mode, 0o600, f"stop.json has mode {mode:o}")
