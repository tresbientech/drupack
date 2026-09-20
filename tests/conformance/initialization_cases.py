"""Ported from tests/initialization.sh: first starts, listener records, database adoption,
interrupted installs, the startup lock, site naming, and PostgreSQL recovery.

Every case here is Linux only, matching where the old file ran in CI; the PostgreSQL
cases are also marked docker, through TOOLS.
"""

import os
import shutil
import signal
import subprocess
import sys
import time
from http.cookiejar import CookieJar
from urllib.error import HTTPError, URLError
from urllib.request import HTTPCookieProcessor, build_opener, urlopen

import harness

PASSWORD = "Initialization.test.2026"
SITE_NAME = "Drupack initialization check"
DEFAULT_SITE_NAME = "Drupal Mercury Demo"
DEFAULT_URL = "http://localhost:7225/"
LOGIN_LINE = "  Login:     http"
# Phase 2 leaves this line to the default-port case in site_cases.py and this one; phase 8's
# network_cases.py asserts it too, each module keeping its own copy.
READY_LINE = "Drupal is ready."
# Dockerfile installs the seed under this account; launch.php's seedPassword() carries its password.
SEED_ADMIN = "drupack-admin"
# postgres:17.11, pinned the way server_database_cases.py pins its own copy of the same image:
# docker buildx imagetools inspect postgres:17.11 --format '{{.Manifest.Digest}}'
POSTGRES_IMAGE = "postgres:17.11@sha256:67f41722b7a8cbdb868a44a4995c846eddfdc2973bccb291ce937dce88ad5675"

# A helper process for the startup-lock case: holds an exclusive flock on the path in argv[1],
# signals argv[2] once it has it, then waits for argv[3] to appear, bounded by the seconds in
# argv[4]. It releases the lock by exiting, never by a signal.
LOCK_HOLDER_SCRIPT = """
import fcntl, pathlib, sys, time
lock_path, ack_path, release_path, budget = sys.argv[1], sys.argv[2], sys.argv[3], float(sys.argv[4])
handle = open(lock_path, "a+")
fcntl.flock(handle, fcntl.LOCK_EX)
pathlib.Path(ack_path).write_text("")
deadline = time.monotonic() + budget
while time.monotonic() < deadline and not pathlib.Path(release_path).exists():
    time.sleep(0.25)
"""


def current_site_name(work_dir, data):
    return harness.run_dr(harness.BINARY, work_dir, data, "config:get", "system.site", "name", "--format=string")


def current_administrator(work_dir, data):
    return harness.run_dr(harness.BINARY, work_dir, data, "php:eval",
                           r"print \Drupal\user\Entity\User::load(1)->getAccountName();")


def follow_link(link):
    """Follow a one-time login link with a cookie jar. Drupal redirects it to the account
    form, so the landing page names the account it logged in.
    """
    opener = build_opener(HTTPCookieProcessor(CookieJar()))
    with opener.open(link, timeout=harness.WAITS["http_request"].seconds) as response:
        return response.geturl(), response.read().decode(errors="replace")


def assert_readiness(case, log_path, caddy_log):
    """Assert the readiness block launch.php prints, and that Caddy's own log never reaches
    the terminal output the launcher captures. Returns the log text for further assertions.
    """
    text = log_path.read_text(errors="replace")
    case.assertIn("Drupack is ready", text, f"the start printed no readiness heading: inspect {log_path}")
    case.assertIn("  URL:       http", text, f"the start printed no URL label: inspect {log_path}")
    case.assertIn("  Site data:", text, f"the start printed no site data label: inspect {log_path}")
    case.assertIn("  Log:", text, f"the start printed no log label: inspect {log_path}")
    case.assertIn("Press Ctrl+C to stop.", text, f"the start printed no stop instruction: inspect {log_path}")
    case.assertTrue(caddy_log.is_file(), "the Caddy log file was not created")
    sentinel = "caddy-log-sentinel"
    with open(caddy_log, "a") as handle:
        handle.write(sentinel + "\n")
    case.assertNotIn(sentinel, log_path.read_text(errors="replace"), "Caddy log content appeared in CLI output")
    case.assertIn(LOGIN_LINE, text, f"the start printed no one-time login link: inspect {log_path}")
    return text


def assert_no_login_link(case, log_path):
    text = log_path.read_text(errors="replace")
    case.assertNotIn(LOGIN_LINE, text, f"a later start printed a one-time login link: inspect {log_path}")


def assert_marker(case, data):
    case.assertTrue((data / "site-installed").exists(), "Site data holds no completion marker")
    case.assertFalse((data / "installation-progress").exists(), "Site data still holds initialization progress")


def refuse(case, case_dir, name, *args):
    """Run a start that bootstraps Drupal against a real database before refusing; return
    its combined output. Every caller in this module reaches that far, so this waits on the
    bootstrap_refusal budget, not the shorter argument-refusal one.
    """
    log = case_dir / f"{name}.log"
    with open(log, "wb") as handle:
        try:
            result = subprocess.run(
                [str(harness.BINARY), *args], cwd=case_dir, stdout=handle, stderr=subprocess.STDOUT,
                timeout=harness.WAITS["bootstrap_refusal"].seconds,
            )
        except subprocess.TimeoutExpired:
            case.fail(f"{name}: the start kept running instead of refusing within "
                      f"{harness.WAITS['bootstrap_refusal'].seconds}s: inspect {log}")
    case.assertNotEqual(result.returncode, 0, f"{name}: the start succeeded instead of refusing: inspect {log}")
    return log.read_text(errors="replace")


def reset_installation_state(data):
    """Clear an installed site while keeping its extracted runtime, so a later start in the
    same Site data directory serves without re-extracting the embedded application.
    """
    directories = ("files", "private", "config", "tmp")
    files = ("settings.php", "site-installed", "installation-progress", "hash_salt", "site.sqlite",
              "site.sqlite-shm", "site.sqlite-wal", "site-adopted", "first-install", "listener")
    for name in directories:
        shutil.rmtree(data / name, ignore_errors=True)
    for name in files:
        (data / name).unlink(missing_ok=True)


class FirstStartAndListener(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX,)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def test_first_start_creates_the_site_and_serves_through_drush(self):
        data = self.case_dir / "data"
        site = harness.Site(harness.BINARY, self.case_dir / "first-start")
        site.start(data, "--admin-user", "init-admin", "--admin-password", PASSWORD)
        try:
            assert_marker(self, data)
            self.assertEqual(current_site_name(self.case_dir, data).stdout.strip(), DEFAULT_SITE_NAME)
            assert_readiness(self, site.log_path, data / "logs" / "caddy.log")
            # site.start() already got its own 200 from /user/login; the launcher's separate
            # internal poller writes this line only once its own first request finishes, so
            # the two race under load. Wait for it instead of trusting one read to have it.
            harness.wait_for_line(site.log_path, 0, READY_LINE, harness.WAITS["start"].seconds)

            bootstrap = harness.run_dr(harness.BINARY, self.case_dir, data, "status", "--field=bootstrap")
            self.assertEqual(bootstrap.returncode, 0, bootstrap.stderr)
            self.assertEqual(bootstrap.stdout.strip(), "Successful", "dr status while the server ran")

            link_result = harness.run_dr(harness.BINARY, self.case_dir, data, "user:login", "--no-browser")
            self.assertEqual(link_result.returncode, 0, link_result.stderr)
            link = link_result.stdout.strip()
            self.assertTrue(link.startswith(f"http://localhost:{site.port}/"), link)
            landing, body = follow_link(link)
            self.assertIn("/user/1/edit", landing, landing)
            self.assertIn('value="init-admin"', body)

            overridden = harness.run_dr(harness.BINARY, self.case_dir, data,
                                         "--listen", "127.0.0.1:19999", "user:login", "--no-browser")
            self.assertEqual(overridden.returncode, 0, overridden.stderr)
            self.assertTrue(overridden.stdout.strip().startswith("http://localhost:19999/"), overridden.stdout)

            listener_path = data / "listener"
            saved_listener = listener_path.read_bytes()
            listener_path.unlink()
            fallback = harness.run_dr(harness.BINARY, self.case_dir, data, "user:login", "--no-browser")
            self.assertEqual(fallback.returncode, 0, fallback.stderr)
            self.assertTrue(fallback.stdout.strip().startswith(DEFAULT_URL), fallback.stdout)
            listener_path.write_bytes(saved_listener)
        finally:
            site.stop()


class RecordedBackendAndAdoption(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX,)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def _install_and_rename(self, data):
        site = harness.Site(harness.BINARY, self.case_dir / "install")
        site.start(data, "--admin-user", "init-admin", "--admin-password", PASSWORD)
        site.stop()
        renamed = harness.run_dr(harness.BINARY, self.case_dir, data,
                                  "config:set", "system.site", "name", SITE_NAME, "--yes")
        self.assertEqual(renamed.returncode, 0, renamed.stderr)

    def test_a_completed_site_keeps_its_recorded_backend(self):
        data = self.case_dir / "data"
        self._install_and_rename(data)

        site = harness.Site(harness.BINARY, self.case_dir / "recorded-backend")
        site.start(data, "--database", "mysql", "--db-host", "127.0.0.1", "--db-port", "3306",
                   "--db-name", "absent", "--db-user", "absent", "--db-password", "absent")
        try:
            driver = harness.run_dr(harness.BINARY, self.case_dir, data, "status", "--field=db-driver")
            self.assertEqual(driver.stdout.strip(), "sqlite", "the start replaced the recorded backend")
            self.assertEqual(current_site_name(self.case_dir, data).stdout.strip(), SITE_NAME)
            assert_no_login_link(self, site.log_path)
        finally:
            site.stop()

    def test_adoption_and_refusals(self):
        data = self.case_dir / "data"
        self._install_and_rename(data)
        sqlite_backup = (data / "site.sqlite").read_bytes()

        (data / "site-installed").unlink()
        site = harness.Site(harness.BINARY, self.case_dir / "adoption")
        site.start(data)
        assert_marker(self, data)
        self.assertEqual(current_site_name(self.case_dir, data).stdout.strip(), SITE_NAME)
        site.stop()

        (data / "site-installed").unlink()
        (data / "site.sqlite").write_text("not a database")
        text = refuse(self, self.case_dir, "unbootstrappable", "--data-dir", str(data))
        self.assertIn("dr --data-dir", text, "the refusal does not name the recovery command")
        self.assertIn(str(data), text, "the refusal does not name the Site data directory")
        (data / "site.sqlite").write_bytes(sqlite_backup)

        (data / "settings.php").unlink()
        (data / "installation-progress").unlink(missing_ok=True)
        text = refuse(self, self.case_dir, "orphan-database", "--data-dir", str(data))
        self.assertIn(str(data), text, "the refusal does not name the Site data directory")
        self.assertEqual((data / "site.sqlite").read_bytes(), sqlite_backup,
                          "the refused start changed the existing database")


class InterruptedStartAndRace(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX,)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def test_interrupted_start_then_concurrent_race(self):
        data = self.case_dir / "data"
        # A rerun into the same results directory finds this method's own race step left a
        # fully installed site here; the interrupted start below needs a truly uninstalled
        # data directory to reach its own pending-administrator step.
        reset_installation_state(data)

        # A first start interrupted before the administrator step records its progress, in
        # its own process group, and leaves the settings behind without a completion marker.
        interrupted_dir = self.case_dir / "interrupted"
        interrupted_dir.mkdir(exist_ok=True)
        log = interrupted_dir / "run.log"
        with open(log, "wb") as handle:
            process = subprocess.Popen(
                [str(harness.BINARY), "--data-dir", str(data), "--admin-user", "init-admin",
                 "--admin-password", PASSWORD],
                cwd=interrupted_dir, stdout=handle, stderr=subprocess.STDOUT, start_new_session=True,
            )
        try:
            progress = data / "installation-progress"
            deadline = time.monotonic() + harness.WAITS["progress"].seconds
            pending = False
            while time.monotonic() < deadline:
                if progress.exists() and progress.read_text() == '["administrator"]':
                    pending = True
                    break
                if process.poll() is not None:
                    break
                time.sleep(0.1)
            self.assertTrue(pending, "the first start recorded no pending administrator step")
            self.assertNotEqual(os.getpgid(process.pid), os.getpgid(0),
                                 "the interrupted start shares this process group")
        finally:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait(timeout=harness.WAITS["stop"].seconds)
        self.assertTrue((data / "settings.php").exists(), "the interrupted start recorded no settings")
        self.assertFalse((data / "site-installed").exists(),
                          "the interrupted start recorded a completed installation")

        # An interrupted first start that lost its progress record is not adopted with the seed password.
        progress_backup = progress.read_bytes()
        progress.unlink()
        text = refuse(self, self.case_dir, "seed-administrator", "--data-dir", str(data))
        self.assertIn("packaged seed password", text)
        self.assertIn("dr --data-dir", text, "the refusal does not name the recovery command")
        self.assertIn(str(data), text, "the refusal does not name the Site data directory")
        progress.write_bytes(progress_backup)

        # The interrupted start left the seed administrator in place.
        account = current_administrator(self.case_dir, data)
        self.assertEqual(account.returncode, 0, account.stderr)
        self.assertEqual(account.stdout.strip(), SEED_ADMIN, "the seed administrator is already replaced")

        # A start with credentials finishes the interrupted setup and prints a link. A
        # graceful shutdown removes the extracted application, so this case, which the race
        # below reuses, kills the server hard instead.
        recovery_dir = self.case_dir / "recovery"
        recovery_dir.mkdir(exist_ok=True)
        site = harness.Site(harness.BINARY, recovery_dir)
        site.start(data, "--admin-user", "init-admin", "--admin-password", PASSWORD)
        assert_marker(self, data)
        assert_readiness(self, site.log_path, data / "logs" / "caddy.log")
        account = current_administrator(self.case_dir, data)
        self.assertEqual(account.stdout.strip(), "init-admin", "the recovery left the seed administrator")
        os.killpg(site.process.pid, signal.SIGKILL)
        site.process.wait(timeout=harness.WAITS["stop"].seconds)

        # Two simultaneous first starts initialize once and the loser names the directory.
        reset_installation_state(data)
        port = harness.pick_port()
        race_dir = self.case_dir / "race"
        race_dir.mkdir(exist_ok=True)
        candidates = []
        handles = []
        for label in ("a", "b"):
            handle = open(race_dir / f"{label}.log", "wb")
            handles.append(handle)
            candidates.append((label, subprocess.Popen(
                [str(harness.BINARY), "--data-dir", str(data), "--listen", f"127.0.0.1:{port}",
                 "--admin-user", "init-admin", "--admin-password", PASSWORD],
                cwd=race_dir, stdout=handle, stderr=subprocess.STDOUT, start_new_session=True,
            )))
        try:
            deadline = time.monotonic() + harness.WAITS["start"].seconds
            loser_label = None
            while time.monotonic() < deadline and loser_label is None:
                for label, candidate in candidates:
                    if candidate.poll() is not None:
                        loser_label = label
                        break
                if loser_label is None:
                    time.sleep(0.25)
            self.assertIsNotNone(loser_label, "both simultaneous starts kept running")
            loser = next(candidate for label, candidate in candidates if label == loser_label)
            winner = next(candidate for label, candidate in candidates if label != loser_label)
            code = loser.wait(timeout=harness.WAITS["stop"].seconds)
            self.assertNotEqual(code, 0, "a simultaneous start exited without a failure")
            loser_log = (race_dir / f"{loser_label}.log").read_text(errors="replace")
            self.assertIn("Another Drupack start", loser_log, "the losing start names no other start")
            self.assertIn(str(data), loser_log, "the losing start does not name the Site data directory")

            ready_deadline = time.monotonic() + harness.WAITS["start"].seconds
            ready = False
            while time.monotonic() < ready_deadline:
                if winner.poll() is not None:
                    break
                try:
                    if urlopen(f"http://localhost:{port}/user/login",
                               timeout=harness.WAITS["http_request"].seconds).status == 200:
                        ready = True
                        break
                except (URLError, HTTPError, ConnectionError, TimeoutError):
                    pass
                time.sleep(0.25)
            self.assertTrue(ready, "the winning start did not serve /user/login")
            assert_marker(self, data)
        finally:
            for _, candidate in candidates:
                if candidate.poll() is None:
                    harness.stop_process(candidate, harness.WAITS["stop"].seconds)
            for handle in handles:
                handle.close()


class EquivalentPathLock(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX,)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def test_an_equivalent_path_takes_the_same_lock(self):
        data = self.case_dir / "data"
        data.mkdir(exist_ok=True)
        equivalent = self.case_dir / "equivalent"
        # A rerun into the same results directory finds this symlink from the last run.
        equivalent.unlink(missing_ok=True)
        equivalent.symlink_to(data, target_is_directory=True)
        ack = self.case_dir / "lock-acquired"
        release = self.case_dir / "release-lock"
        # LOCK_HOLDER_SCRIPT keys its own handshake on these two paths, so a rerun into the
        # same results directory must not find either surviving from the last pass: a stale
        # ack lets this test race ahead of the new holder actually taking the lock, and a
        # stale release lets the new holder exit before ever really holding it.
        ack.unlink(missing_ok=True)
        release.unlink(missing_ok=True)

        holder = subprocess.Popen(
            [sys.executable, "-c", LOCK_HOLDER_SCRIPT, str(data / "startup.lock"), str(ack), str(release),
             str(harness.WAITS["lock_hold"].seconds)],
            start_new_session=True,
        )
        try:
            deadline = time.monotonic() + harness.WAITS["lock_ack"].seconds
            while time.monotonic() < deadline and not ack.exists():
                time.sleep(0.05)
            self.assertTrue(ack.exists(), "the lock-holding helper did not acquire startup.lock")

            log = self.case_dir / "equivalent.log"
            with open(log, "wb") as handle:
                try:
                    result = subprocess.run(
                        [str(harness.BINARY), "--data-dir", str(equivalent)], cwd=self.case_dir,
                        stdout=handle, stderr=subprocess.STDOUT, timeout=harness.WAITS["refusal"].seconds,
                    )
                except subprocess.TimeoutExpired:
                    self.fail("a start waited for a lock another process held")
            self.assertNotEqual(result.returncode, 0, "a start took a lock another process held")
            text = log.read_text(errors="replace")
            self.assertIn("Another Drupack start", text, f"the blocked start names no other start: inspect {log}")
            self.assertIn(str(data.resolve()), text, "the blocked start does not name the resolved directory")
        finally:
            release.touch()
            try:
                holder.wait(timeout=harness.WAITS["stop"].seconds)
            except subprocess.TimeoutExpired:
                holder.kill()
                holder.wait(timeout=harness.WAITS["stop"].seconds)


class SiteName(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX,)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def test_a_chosen_site_name_survives_a_later_start(self):
        data = self.case_dir / "data"
        site = harness.Site(harness.BINARY, self.case_dir / "chosen-name")
        site.start(data, "--site-name", "Chosen initialization name",
                   "--admin-user", "init-admin", "--admin-password", PASSWORD)
        self.assertEqual(current_site_name(self.case_dir, data).stdout.strip(), "Chosen initialization name")
        site.stop()

        site = harness.Site(harness.BINARY, self.case_dir / "rename-attempt")
        site.start(data, "--site-name", "Rejected initialization name")
        self.assertEqual(current_site_name(self.case_dir, data).stdout.strip(), "Chosen initialization name",
                          "a later start renamed the site")
        site.stop()

    def test_the_site_name_also_comes_from_the_environment(self):
        data = self.case_dir / "data"
        site = harness.Site(harness.BINARY, self.case_dir)
        os.environ["DRUPACK_SITE_NAME"] = "Environment initialization name"
        try:
            site.start(data, "--admin-user", "init-admin", "--admin-password", PASSWORD)
        finally:
            del os.environ["DRUPACK_SITE_NAME"]
        self.assertEqual(current_site_name(self.case_dir, data).stdout.strip(), "Environment initialization name")
        site.stop()


class PostgresqlLifecycle(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX,)
    TOOLS = ("docker",)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)
        cls.container = f"drupack-initialization-postgres-{os.getpid()}"
        subprocess.run(
            ["docker", "run", "-d", "--name", cls.container, "-p", "127.0.0.1::5432",
             "-e", "POSTGRES_DB=drupal", "-e", "POSTGRES_USER=drupal", "-e", f"POSTGRES_PASSWORD={PASSWORD}",
             POSTGRES_IMAGE],
            check=True, capture_output=True, timeout=harness.WAITS["database_container"].seconds,
        )
        # unittest skips tearDownClass once setUpClass raises, so a failure past this point
        # removes the container itself before re-raising, the way the old script's EXIT trap did.
        try:
            port_output = subprocess.run(
                ["docker", "port", cls.container, "5432/tcp"], check=True, capture_output=True, text=True,
                timeout=harness.WAITS["docker_admin"].seconds,
            ).stdout
            cls.port = int(port_output.strip().splitlines()[0].rsplit(":", 1)[-1])
            deadline = time.monotonic() + harness.WAITS["database_ready"].seconds
            ready = False
            while time.monotonic() < deadline:
                probe = subprocess.run(
                    ["docker", "exec", cls.container, "pg_isready", "-h", "127.0.0.1", "-U", "drupal", "-d", "drupal"],
                    capture_output=True, timeout=harness.WAITS["docker_admin"].seconds,
                )
                if probe.returncode == 0:
                    ready = True
                    break
                time.sleep(2)
            if not ready:
                raise AssertionError(
                    f"the PostgreSQL container did not become ready within "
                    f"{harness.WAITS['database_ready'].seconds}s"
                )
        except Exception:
            subprocess.run(["docker", "rm", "-f", cls.container], capture_output=True,
                            timeout=harness.WAITS["docker_admin"].seconds)
            raise

    @classmethod
    def tearDownClass(cls):
        with open(cls.class_dir / "postgres.log", "wb") as handle:
            subprocess.run(["docker", "logs", cls.container], stdout=handle, stderr=subprocess.STDOUT,
                            timeout=harness.WAITS["docker_admin"].seconds)
        subprocess.run(["docker", "rm", "-f", cls.container], capture_output=True,
                        timeout=harness.WAITS["docker_admin"].seconds)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def _create_database(self, name):
        subprocess.run(
            ["docker", "exec", self.container, "psql", "-U", "drupal", "-d", "drupal", "-c", f"CREATE DATABASE {name}"],
            check=True, capture_output=True, timeout=harness.WAITS["docker_admin"].seconds,
        )

    def _connection(self, name):
        return ["--database", "pgsql", "--db-host", "127.0.0.1", "--db-port", str(self.port),
                "--db-name", name, "--db-user", "drupal", "--db-password", PASSWORD]

    def test_first_start_then_interrupted_recovery_without_reinstalling(self):
        self._create_database("recovery")
        data = self.case_dir / "data"
        connection = self._connection("recovery")

        site = harness.Site(harness.BINARY, self.case_dir / "first")
        site.start(data, *connection, "--admin-user", "init-admin", "--admin-password", PASSWORD)
        assert_marker(self, data)
        self.assertEqual(current_site_name(self.case_dir, data).stdout.strip(), DEFAULT_SITE_NAME)
        assert_readiness(self, site.log_path, data / "logs" / "caddy.log")
        site.stop()

        renamed = harness.run_dr(harness.BINARY, self.case_dir, data,
                                  "config:set", "system.site", "name", SITE_NAME, "--yes")
        self.assertEqual(renamed.returncode, 0, renamed.stderr)
        uninstalled = harness.run_dr(harness.BINARY, self.case_dir, data, "pm:uninstall", "mcp_tools", "--yes")
        self.assertEqual(uninstalled.returncode, 0, uninstalled.stderr)
        (data / "site-installed").unlink()
        (data / "installation-progress").write_text('["install","modules"]')

        # The recovery passes no database options, so the recorded settings decide the backend.
        site = harness.Site(harness.BINARY, self.case_dir / "recovery")
        site.start(data, "--admin-user", "init-admin", "--admin-password", PASSWORD)
        assert_marker(self, data)
        self.assertEqual(current_site_name(self.case_dir, data).stdout.strip(), SITE_NAME)
        assert_readiness(self, site.log_path, data / "logs" / "caddy.log")
        enabled = harness.run_dr(harness.BINARY, self.case_dir, data, "php:eval",
                                  r'print \Drupal::moduleHandler()->moduleExists("mcp_tools") ? "enabled" : "missing";')
        self.assertEqual(enabled.stdout.strip(), "enabled", "the recovery left MCP Tools disabled")
        driver = harness.run_dr(harness.BINARY, self.case_dir, data, "status", "--field=db-driver")
        self.assertEqual(driver.stdout.strip(), "pgsql", "the recovery served the wrong driver")
        site.stop()

    def test_first_start_never_reinstalls_a_database_that_already_holds_a_site(self):
        self._create_database("existing")
        connection = self._connection("existing")
        # A second start reuses this Site data directory, not a fresh one: Drupal's installed
        # config caches the embedded application's own local path, and a different directory's
        # extraction, though built from the same binary, does not resolve to that same path.
        data = self.case_dir / "data"

        site = harness.Site(harness.BINARY, self.case_dir / "first")
        site.start(data, *connection, "--admin-user", "init-admin", "--admin-password", PASSWORD)
        renamed = harness.run_dr(harness.BINARY, self.case_dir, data,
                                  "config:set", "system.site", "name", SITE_NAME, "--yes")
        self.assertEqual(renamed.returncode, 0, renamed.stderr)
        site.stop()

        reset_installation_state(data)
        site = harness.Site(harness.BINARY, self.case_dir / "second")
        site.start(data, *connection, "--admin-user", "other-admin", "--admin-password", PASSWORD)
        self.assertEqual(current_site_name(self.case_dir, data).stdout.strip(), SITE_NAME,
                          "the start reinstalled a database that already held a site")
        account = current_administrator(self.case_dir, data)
        self.assertEqual(account.stdout.strip(), "init-admin", "the start replaced the existing administrator")
        site.stop()

    def test_first_start_refuses_a_database_that_holds_other_tables(self):
        self._create_database("occupied")
        subprocess.run(
            ["docker", "exec", self.container, "psql", "-U", "drupal", "-d", "occupied", "-c",
             "CREATE TABLE tenant (id integer)"],
            check=True, capture_output=True, timeout=harness.WAITS["docker_admin"].seconds,
        )
        data = self.case_dir / "data"
        connection = self._connection("occupied")
        text = refuse(self, self.case_dir, "occupied-database", "--data-dir", str(data), *connection)
        self.assertIn(str(data), text, "the refusal does not name the Site data directory")

        tables = subprocess.run(
            ["docker", "exec", self.container, "psql", "-U", "drupal", "-d", "occupied", "-t", "-A", "-c",
             "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'"],
            check=True, capture_output=True, text=True, timeout=harness.WAITS["docker_admin"].seconds,
        ).stdout.strip()
        self.assertEqual(tables, "1", "the refused start left an unexpected number of tables")
        tenant = subprocess.run(
            ["docker", "exec", self.container, "psql", "-U", "drupal", "-d", "occupied", "-t", "-A", "-c",
             "SELECT count(*) FROM information_schema.tables WHERE table_name = 'tenant'"],
            check=True, capture_output=True, text=True, timeout=harness.WAITS["docker_admin"].seconds,
        ).stdout.strip()
        self.assertEqual(tenant, "1", "the refused start dropped the existing tables")
