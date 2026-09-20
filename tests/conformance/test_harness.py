"""Unit tests for the harness itself. No product binary, no results directory."""

import os
import signal
import socket
import subprocess
import sys
import unittest

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


if __name__ == "__main__":
    unittest.main(verbosity=2)
