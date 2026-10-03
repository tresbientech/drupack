"""A start that returns the prompt: it relays the server's log until the site answers,
leaves the server running in the background, and `stop` ends it.
"""

import base64
import json
import os
import shutil
import socket
import subprocess

import harness
import handover_cases


class DetachedCases(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

    def setUp(self):
        super().setUp()
        self.data = self.case_dir / "data"
        self.port = harness.pick_port()
        self.addCleanup(self._end_server)

    def _start(self, *words, env=None, binary=None, cwd=None):
        try:
            return harness.run(
                [str(binary or harness.BINARY), *words, "--data-dir", str(self.data), "--listen", f"127.0.0.1:{self.port}"],
                cwd=cwd or self.case_dir, capture_output=True, text=True, timeout=harness.WAITS["start"].seconds, env=env,
            )
        except subprocess.TimeoutExpired as timeout:
            self.fail(f"the start did not return within {timeout.timeout}s, after printing {timeout.output!r}: "
                      f"inspect {self.data / 'logs'}")

    def _stop(self):
        return harness.run(
            [str(harness.BINARY), "stop", "--data-dir", str(self.data)], cwd=self.case_dir,
            capture_output=True, text=True, timeout=harness.WAITS["stop"].seconds + 30,
        )

    def _end_server(self):
        """Stop the server a case left running, by its PID when `stop` cannot."""
        if self._stop().returncode == 0:
            return
        pid = json.loads((self.data / "stop.json").read_text())["pid"]
        if harness.current_platform() == harness.WINDOWS:
            subprocess.run(["taskkill", "/T", "/F", "/PID", str(pid)], capture_output=True)
        else:
            os.kill(pid, 9)

    def _assert_returns_with_a_running_site(self, *words):
        result = self._start(*words)
        self.assertEqual(result.returncode, 0, f"the start exited non-zero: {result.stdout}{result.stderr}")
        self.assertIn("Login:", result.stdout)
        log = self.data / "logs" / "server.log"
        self.assertIn(f"runs in the background. Its log: {log.as_posix()}", result.stdout)
        self.assertNotIn("Press Ctrl+C", result.stdout)
        self.assertIn(f"    {harness.BINARY} stop --data-dir {self.data}\n", result.stdout)
        self.assertTrue(log.is_file())
        drush = harness.run_drush(harness.BINARY, self.case_dir, self.data, "status")
        self.assertEqual(drush.returncode, 0, f"drush failed against the detached site: {drush.stderr}")
        self.assertIn("is ready.", log.read_text(errors="replace"))

    def test_a_first_start_returns_once_the_site_answers(self):
        self._assert_returns_with_a_running_site()

    def test_the_start_word_acts_as_no_word(self):
        self._assert_returns_with_a_running_site("start")

    def _assert_powershell_runs_the_printed_stop(self, binary, cwd, word):
        """PowerShell hands a program its full path, which the harness passes the same way."""
        if harness.current_platform() != harness.WINDOWS:
            self.skipTest("only Windows shells read the first word differently")
        result = self._start(binary=binary, cwd=cwd)
        self.assertEqual(result.returncode, 0, f"the start exited non-zero: {result.stdout}{result.stderr}")
        line = next(line.strip() for line in result.stdout.splitlines() if " stop --data-dir " in line)
        self.assertTrue(line.startswith(f"{word} stop --data-dir "), line)
        # An encoded command reaches PowerShell without a second round of quoting.
        stop = harness.run(
            ["powershell", "-NoProfile", "-EncodedCommand", base64.b64encode(line.encode("utf-16-le")).decode()],
            cwd=cwd, capture_output=True, text=True, timeout=harness.WAITS["stop"].seconds + 30,
        )
        self.assertEqual(stop.returncode, 0, f"{stop.stdout}{stop.stderr}")
        self.assertIn("stopped", stop.stdout)

    def test_powershell_runs_the_stop_command_of_a_start_from_the_executables_folder(self):
        self._assert_powershell_runs_the_printed_stop(
            harness.BINARY, harness.BINARY.parent, f".\\{harness.BINARY.name}")

    def test_powershell_runs_the_stop_command_of_an_executable_under_a_space(self):
        binary = self.case_dir / "with space" / harness.BINARY.name
        binary.parent.mkdir()
        shutil.copy2(harness.BINARY, binary)
        self._assert_powershell_runs_the_printed_stop(binary, self.case_dir, f'& "{binary}"')

    def test_a_start_without_a_data_dir_keeps_its_site_data_where_it_started(self):
        result = harness.run(
            [str(harness.BINARY), "--listen", f"127.0.0.1:{self.port}"],
            cwd=self.case_dir, capture_output=True, text=True, timeout=harness.WAITS["start"].seconds,
        )
        self.assertEqual(result.returncode, 0, f"the start exited non-zero: {result.stdout}{result.stderr}")
        self.assertTrue((self.data / "settings.php").is_file(), f"no Site data in the start directory: {result.stdout}")
        stop = harness.run([str(harness.BINARY), "stop"], cwd=self.case_dir, capture_output=True, text=True,
                           timeout=harness.WAITS["stop"].seconds + 30)
        self.assertEqual(stop.returncode, 0, f"{stop.stdout}{stop.stderr}")
        self.assertIn("stopped", stop.stdout)

    def test_stop_ends_a_detached_site(self):
        self.assertEqual(self._start().returncode, 0)
        result = self._stop()
        self.assertEqual(result.returncode, 0, f"stop exited non-zero: {result.stderr}")
        self.assertIn("stopped", result.stdout)
        with self.assertRaises(OSError):
            socket.create_connection(("127.0.0.1", self.port), timeout=harness.WAITS["port_closed"].seconds).close()

    def test_a_start_on_a_taken_port_exits_one_and_leaves_no_server(self):
        holder = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        holder.bind(("127.0.0.1", self.port))
        holder.listen(1)
        try:
            result = self._start()
        finally:
            holder.close()
        self.assertEqual(result.returncode, 1)
        self.assertIn(f"Another program is listening on 127.0.0.1:{self.port}", result.stderr)
        self.assertIn("is not running", self._stop().stdout)


class DetachedHandoverCases(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS)

    def test_a_second_start_while_detached_hands_over(self):
        data = self.case_dir / "data"
        port = harness.pick_port()
        command = [str(harness.BINARY), "--data-dir", str(data), "--listen", f"127.0.0.1:{port}"]
        first = harness.run(command, cwd=self.case_dir, capture_output=True, text=True,
                            timeout=harness.WAITS["start"].seconds)
        self.assertEqual(first.returncode, 0, first.stderr)
        try:
            env, recorded = handover_cases._console_env(self.case_dir / "recorder")
            second = harness.run(command, cwd=self.case_dir, capture_output=True, text=True,
                                 timeout=harness.WAITS["start"].seconds, env=env)
            self.assertEqual(second.returncode, 0, second.stderr)
            self.assertIn("already serving this Site data", second.stdout)
            self.assertIn("/user/reset/1/", harness.wait_for_recorded_url(recorded, harness.WAITS["browser_open"].seconds))
        finally:
            harness.run([str(harness.BINARY), "stop", "--data-dir", str(data)], cwd=self.case_dir,
                        capture_output=True, text=True, timeout=harness.WAITS["stop"].seconds + 30)
