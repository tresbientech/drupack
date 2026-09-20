"""Unit tests for the harness itself. No product binary, no results directory."""

import os
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

import harness


class WaitTableTest(unittest.TestCase):
    def test_every_wait_exceeds_its_product_deadline(self):
        for wait in harness.WAIT_TABLE:
            if wait.deadline is None:
                continue
            with self.subTest(wait=wait.name):
                self.assertGreater(wait.seconds, wait.deadline)


class PickPortTest(unittest.TestCase):
    def test_pick_port_returns_a_bindable_port(self):
        port = harness.pick_port()
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
            listener.bind(("127.0.0.1", port))
            listener.listen(1)


class StopProcessTest(unittest.TestCase):
    def test_a_stalled_process_is_killed_and_reported(self):
        # The child announces once its SIGTERM handler is installed, so the SIGTERM below
        # never lands during the interpreter's own startup and gets the default handling.
        process = subprocess.Popen(
            [sys.executable, "-u", "-c",
             "import signal, time\n"
             "signal.signal(signal.SIGTERM, signal.SIG_IGN)\n"
             "print('ready', flush=True)\n"
             "time.sleep(60)\n"],
            start_new_session=True, stdout=subprocess.PIPE, text=True,
        )
        try:
            process.stdout.readline()
            with self.assertRaises(AssertionError):
                harness.stop_process(process, timeout=0.3)
            self.assertIsNotNone(process.poll(), "the stalled process should be dead")
        finally:
            if process.poll() is None:
                os.killpg(process.pid, signal.SIGKILL)
                process.wait()
            process.stdout.close()


class StopProcessWindowsBranchTest(unittest.TestCase):
    """This host is Linux, with no taskkill: patch the platform and subprocess.run to prove
    the branch itself builds the right command and still waits for the exit it caused,
    which is as far as a Linux run can verify it; the command's own behaviour is Windows CI's.
    """

    def test_taskkills_the_pid_and_waits_for_exit(self):
        process = subprocess.Popen([sys.executable, "-c", "import time; time.sleep(60)"])
        calls = []

        def fake_run(command, **kwargs):
            calls.append(command)
            process.kill()
            return subprocess.CompletedProcess(command, 0)

        try:
            with mock.patch.object(harness, "current_platform", return_value=harness.WINDOWS), \
                    mock.patch.object(harness.subprocess, "run", side_effect=fake_run):
                harness.stop_process(process, timeout=5)
            self.assertEqual(calls, [["taskkill", "/T", "/F", "/PID", str(process.pid)]])
            self.assertIsNotNone(process.poll())
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()


class RemoveCacheDirTest(unittest.TestCase):
    """This host is POSIX, which holds no handle on a directory it just unpacked into: the
    retry path itself is proven by forcing shutil.rmtree to fail, as far as a Linux run can
    verify it; that a real Windows handle actually clears within the budget is Windows CI's.
    """

    def test_removes_a_directory_nothing_holds_on_the_first_try(self):
        cache_dir = tempfile.mkdtemp(prefix="drupack-cache-test-")
        (harness.Path(cache_dir) / "marker").write_text("x")
        harness.remove_cache_dir(cache_dir, timeout=5)
        self.assertFalse(os.path.exists(cache_dir))

    def test_retries_past_a_held_handle_then_succeeds(self):
        cache_dir = tempfile.mkdtemp(prefix="drupack-cache-test-")
        real_rmtree = shutil.rmtree
        attempts = []

        def flaky_rmtree(path):
            attempts.append(path)
            if len(attempts) < 3:
                raise PermissionError(5, "Access is denied")
            real_rmtree(path)

        with mock.patch.object(harness.shutil, "rmtree", side_effect=flaky_rmtree):
            harness.remove_cache_dir(cache_dir, timeout=5)
        self.assertEqual(len(attempts), 3)
        self.assertFalse(os.path.exists(cache_dir))

    def test_gives_up_past_the_budget_without_raising(self):
        cache_dir = tempfile.mkdtemp(prefix="drupack-cache-test-")
        try:
            with mock.patch.object(
                harness.shutil, "rmtree", side_effect=PermissionError(5, "Access is denied")
            ):
                harness.remove_cache_dir(cache_dir, timeout=0.3)
        finally:
            shutil.rmtree(cache_dir, ignore_errors=True)


if __name__ == "__main__":
    unittest.main(verbosity=2)
