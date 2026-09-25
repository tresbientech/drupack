"""Unit tests for the harness itself. No product binary, no results directory."""

import os
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
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


class InstallRecorderTest(unittest.TestCase):
    """No built executable: the recorder is a plain shell script, run directly."""

    def test_the_recorder_appends_each_url_it_receives(self):
        directory = harness.Path(tempfile.mkdtemp(prefix="drupack-recorder-test-"))
        try:
            script, recorded = harness.install_recorder(directory)
            self.assertEqual(script.name, harness.opener_name())

            first = subprocess.run([str(script), "http://localhost:7225/admin/dashboard"],
                                    capture_output=True, text=True, timeout=5)
            self.assertEqual(first.returncode, 0, first.stderr)
            self.assertEqual(recorded.read_text().splitlines(), ["http://localhost:7225/admin/dashboard"])

            second = subprocess.run([str(script), "http://localhost:7225/user/login"],
                                     capture_output=True, text=True, timeout=5)
            self.assertEqual(second.returncode, 0, second.stderr)
            self.assertEqual(
                recorded.read_text().splitlines(),
                ["http://localhost:7225/admin/dashboard", "http://localhost:7225/user/login"],
            )
        finally:
            shutil.rmtree(directory, ignore_errors=True)


class SiteTest(unittest.TestCase):
    def test_load_site_reads_the_site_json_beside_the_executable(self):
        with tempfile.TemporaryDirectory() as directory:
            directory = Path(directory)
            (directory / "site.json").write_text('{"name": "acme", "port": 7300}')
            site = harness.load_site(directory / "acme")
        self.assertEqual(site, {"name": "acme", "port": 7300})

    def test_the_ready_line_names_the_executable(self):
        with mock.patch.object(harness, "SITE", {"name": "acme"}):
            self.assertEqual(harness.ready_line(), "acme is ready.")


class SkipReportTest(unittest.TestCase):
    def test_each_skipped_case_gets_its_own_line(self):
        report = harness.skip_report([("test_a (cases.One)", "needs docker"), ("test_b (cases.Two)", "not marked")])
        self.assertEqual(report, ["  test_a (cases.One): needs docker", "  test_b (cases.Two): not marked"])


class DatabaseAddressTest(unittest.TestCase):
    def test_an_unset_variable_names_no_server(self):
        with mock.patch.dict(os.environ, {}, clear=False):
            os.environ.pop("DRUPACK_TEST_MYSQL", None)
            self.assertIsNone(harness.database_address("mysql"))

    def test_host_and_port_are_read(self):
        with mock.patch.dict(os.environ, {"DRUPACK_TEST_PGSQL": "postgres:5432"}):
            self.assertEqual(harness.database_address("pgsql"), ("postgres", 5432))

    def test_a_malformed_value_names_its_variable(self):
        for value in ("postgres", ":5432", "postgres:port"):
            with self.subTest(value=value), mock.patch.dict(os.environ, {"DRUPACK_TEST_PGSQL": value}):
                with self.assertRaisesRegex(ValueError, "DRUPACK_TEST_PGSQL"):
                    harness.database_address("pgsql")


class DockerGateTest(unittest.TestCase):
    def test_a_case_marked_docker_skips_when_no_daemon_answers(self):
        class NeedsDocker(harness.ConformanceCase):
            PLATFORMS = (harness.current_platform(),)
            TOOLS = ("docker",)
            RECIPE = False

        with mock.patch.object(harness, "docker_answers", return_value=False):
            with self.assertRaisesRegex(unittest.SkipTest, "Docker daemon"):
                NeedsDocker.setUpClass()



class RecipeGateTest(unittest.TestCase):
    def test_a_case_that_installs_skips_when_the_site_has_no_recipe(self):
        class Installs(harness.ConformanceCase):
            PLATFORMS = (harness.current_platform(),)

        with mock.patch.object(harness, "SITE", {"recipe": ""}):
            with self.assertRaisesRegex(unittest.SkipTest, "the site has no recipe"):
                Installs.setUpClass()

    def test_a_case_that_installs_runs_when_the_site_has_a_recipe(self):
        class Installs(harness.ConformanceCase):
            PLATFORMS = (harness.current_platform(),)

        with mock.patch.object(harness, "SITE", {"recipe": "recipes/acme"}):
            Installs.setUpClass()


class WritableGateTest(unittest.TestCase):
    def test_a_case_that_writes_skips_when_the_site_lists_no_writable_directory(self):
        class Writes(harness.ConformanceCase):
            PLATFORMS = (harness.current_platform(),)
            WRITABLE = True

        with mock.patch.object(harness, "SITE", {"recipe": "recipes/acme", "writable": []}):
            with self.assertRaisesRegex(unittest.SkipTest, "the site lists no writable directory"):
                Writes.setUpClass()

    def test_a_case_that_writes_runs_when_the_site_lists_one(self):
        class Writes(harness.ConformanceCase):
            PLATFORMS = (harness.current_platform(),)
            WRITABLE = True

        with mock.patch.object(harness, "SITE", {"recipe": "recipes/acme", "writable": ["web/themes/custom"]}):
            Writes.setUpClass()

if __name__ == "__main__":
    unittest.main(verbosity=2)
