"""Ported from tests/initialization.sh: first starts, listener records, database adoption,
interrupted installs, the serving lease, site naming, and PostgreSQL recovery.

Every case here is Linux only, matching where the old file ran in CI; the PostgreSQL
cases take their server from harness.DatabaseServer.
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
LOGIN_LINE = "  Login:     http"
# Dockerfile installs the seed under this account; launch.php's seedPassword() carries its password.
SEED_ADMIN = "drupack-admin"

# A helper process for the serving-lease case: holds an exclusive flock on the path in argv[1],
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
    return harness.run_drush(harness.BINARY, work_dir, data, "config:get", "system.site", "name", "--format=string")


def current_administrator(work_dir, data):
    return harness.run_drush(harness.BINARY, work_dir, data, "php:eval",
                           r"print \Drupal\user\Entity\User::load(1)->getAccountName();")


def follow_link(link):
    """Follow a one-time login link with a cookie jar, returning where it landed and the
    session's opener, so a caller can prove the session's identity with a further request.
    """
    opener = build_opener(HTTPCookieProcessor(CookieJar()))
    with opener.open(link, timeout=harness.WAITS["http_request"].seconds) as response:
        return response.geturl(), opener


def assert_readiness(case, log_path, caddy_log):
    """Assert both halves of a start's terminal output, the addresses and paths launch.php
    prints and the readiness line the serving process adds once it answers, and that Caddy's
    own log never reaches that output. Returns the log text for further assertions.
    """
    # A case's own readiness wait can return before the serving process prints this.
    harness.wait_for_line(log_path, 0, harness.ready_line(), harness.WAITS["start"].seconds)
    text = log_path.read_text(errors="replace")
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
    case.assertIn("?destination=/admin/dashboard", text,
                   f"the printed login link carries no dashboard destination: inspect {log_path}")
    return text


def assert_marker(case, data):
    case.assertTrue((data / "site-installed").exists(), "Site data holds no completion marker")
    case.assertFalse((data / "installation-progress").exists(), "Site data still holds initialization progress")



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
            # A first start spends minutes inside these steps and prints nothing else
            # until the site answers, so each one names itself as it runs.
            started = site.log_path.read_text(errors="replace")
            for report in (
                "[1/3] Copying the packaged site into Site data",
                "[2/3] Writing the site settings",
                "[3/3] Creating the administrator account",
            ):
                self.assertIn(report, started, "a first start reported no progress")
            self.assertEqual(current_site_name(self.case_dir, data).stdout.strip(), harness.SITE["site_name"])
            assert_readiness(self, site.log_path, data / "logs" / "caddy.log")
            # site.start() already got its own 200 from /user/login; the launcher's separate
            # internal poller writes this line only once its own first request finishes, so
            # the two race under load. Wait for it instead of trusting one read to have it.
            harness.wait_for_line(site.log_path, 0, harness.ready_line(), harness.WAITS["start"].seconds)

            bootstrap = harness.run_drush(harness.BINARY, self.case_dir, data, "status", "--field=bootstrap")
            self.assertEqual(bootstrap.returncode, 0, bootstrap.stderr)
            self.assertEqual(bootstrap.stdout.strip(), "Successful", "drush status while the server ran")

            link_result = harness.run_drush(harness.BINARY, self.case_dir, data, "user:login", "--no-browser",
                                          "/admin/dashboard")
            self.assertEqual(link_result.returncode, 0, link_result.stderr)
            link = link_result.stdout.strip()
            self.assertTrue(link.startswith(f"http://localhost:{site.port}/"), link)
            self.assertIn("?destination=/admin/dashboard", link)
            landing, opener = follow_link(link)
            self.assertIn("/admin/dashboard", landing, landing)
            # The same session that landed on the dashboard is signed in as init-admin: the
            # account page's title names the account viewing it.
            with opener.open(f"http://localhost:{site.port}/user/1",
                              timeout=harness.WAITS["http_request"].seconds) as response:
                body = response.read().decode(errors="replace")
            self.assertIn(f"init-admin | {harness.SITE['site_name']}", body)

            overridden = harness.run_drush(harness.BINARY, self.case_dir, data,
                                         "--listen", "127.0.0.1:19999", "user:login", "--no-browser")
            self.assertEqual(overridden.returncode, 0, overridden.stderr)
            self.assertTrue(overridden.stdout.strip().startswith("http://localhost:19999/"), overridden.stdout)

            listener_path = data / "listener"
            saved_listener = listener_path.read_bytes()
            listener_path.unlink()
            fallback = harness.run_drush(harness.BINARY, self.case_dir, data, "user:login", "--no-browser")
            self.assertEqual(fallback.returncode, 0, fallback.stderr)
            self.assertTrue(fallback.stdout.strip().startswith(f"http://localhost:{harness.SITE['port']}/"), fallback.stdout)
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
        renamed = harness.run_drush(harness.BINARY, self.case_dir, data,
                                  "config:set", "system.site", "name", SITE_NAME, "--yes")
        self.assertEqual(renamed.returncode, 0, renamed.stderr)

    def test_a_completed_site_keeps_its_recorded_backend(self):
        data = self.case_dir / "data"
        self._install_and_rename(data)

        site = harness.Site(harness.BINARY, self.case_dir / "recorded-backend")
        site.start(data, "--database", "mysql", "--db-host", "127.0.0.1", "--db-port", "3306",
                   "--db-name", "absent", "--db-user", "absent", "--db-password", "absent")
        try:
            driver = harness.run_drush(harness.BINARY, self.case_dir, data, "status", "--field=db-driver")
            self.assertEqual(driver.stdout.strip(), "sqlite", "the start replaced the recorded backend")
            self.assertEqual(current_site_name(self.case_dir, data).stdout.strip(), SITE_NAME)
            assert_readiness(self, site.log_path, data / "logs" / "caddy.log")
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
        text = harness.refuse(self, self.case_dir, "unbootstrappable", "--data-dir", str(data))
        self.assertIn("drush --data-dir", text, "the refusal does not name the recovery command")
        self.assertIn(str(data), text, "the refusal does not name the Site data directory")
        (data / "site.sqlite").write_bytes(sqlite_backup)

        (data / "settings.php").unlink()
        (data / "installation-progress").unlink(missing_ok=True)
        text = harness.refuse(self, self.case_dir, "orphan-database", "--data-dir", str(data))
        self.assertIn(str(data), text, "the refusal does not name the Site data directory")
        self.assertEqual((data / "site.sqlite").read_bytes(), sqlite_backup,
                          "the refused start changed the existing database")

    def test_a_blocked_administrator_does_not_stop_a_later_start(self):
        # A later start needs no generated password, so credentialsRequired() reports it
        # false, and the site keeps serving without a link: the owner already has a password.
        data = self.case_dir / "data"
        site = harness.Site(harness.BINARY, self.case_dir / "install")
        site.start(data, "--admin-user", "init-admin", "--admin-password", PASSWORD)
        site.stop()

        blocked = harness.run_drush(harness.BINARY, self.case_dir, data, "php:eval",
                                  r'\Drupal\user\Entity\User::load(1)->block()->save();')
        self.assertEqual(blocked.returncode, 0, blocked.stderr)

        restart = harness.Site(harness.BINARY, self.case_dir / "restart")
        restart.start(data)
        try:
            harness.wait_for_line(restart.log_path, 0, harness.ready_line(), harness.WAITS["start"].seconds)
            text = restart.log_path.read_text(errors="replace")
            self.assertNotIn(LOGIN_LINE, text, "the readiness block printed a login link despite a failed mint")
            self.assertIn("drush --data-dir", text, "the diagnostic does not name the recovery command")
            self.assertIn("user:login /admin/dashboard", text,
                           "the diagnostic does not name the recovery destination")
            self.assertIn("blocked", text, "the diagnostic does not carry the reason Drush gave")
        finally:
            restart.stop()


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
            process = harness.popen(
                [str(harness.BINARY), "--data-dir", str(data), "--admin-user", "init-admin",
                 "--admin-password", PASSWORD, "--listen", f"127.0.0.1:{harness.pick_port()}"],
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
        text = harness.refuse(self, self.case_dir, "seed-administrator", "--data-dir", str(data))
        self.assertIn("packaged seed password", text)
        self.assertIn("drush --data-dir", text, "the refusal does not name the recovery command")
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
            candidates.append((label, harness.popen(
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
            self.assertIn(f"Another {harness.SITE['name']} start", loser_log, "the losing start names no other start")
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
            [sys.executable, "-c", LOCK_HOLDER_SCRIPT, str(data / "serving.lock"), str(ack), str(release),
             str(harness.WAITS["lock_hold"].seconds)],
            start_new_session=True,
        )
        try:
            deadline = time.monotonic() + harness.WAITS["lock_ack"].seconds
            while time.monotonic() < deadline and not ack.exists():
                time.sleep(0.05)
            self.assertTrue(ack.exists(), "the lock-holding helper did not acquire serving.lock")

            log = self.case_dir / "equivalent.log"
            with open(log, "wb") as handle:
                try:
                    result = harness.run(
                        [str(harness.BINARY), "--data-dir", str(equivalent),
                         "--listen", f"127.0.0.1:{harness.pick_port()}"], cwd=self.case_dir,
                        stdout=handle, stderr=subprocess.STDOUT, timeout=harness.WAITS["refusal"].seconds,
                    )
                except subprocess.TimeoutExpired:
                    self.fail("a start waited for a lock another process held")
            self.assertNotEqual(result.returncode, 0, "a start took a lock another process held")
            text = log.read_text(errors="replace")
            self.assertIn(f"Another {harness.SITE['name']} start", text, f"the blocked start names no other start: inspect {log}")
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

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)
        cls.server = harness.DatabaseServer("pgsql", "initialization", cls.class_dir)
        cls.server.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.stop()

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def _create_database(self, name):
        self.server.execute("drupal", f"CREATE DATABASE {name}")

    def _connection(self, name):
        return self.server.connection(name)

    def test_first_start_then_interrupted_recovery_without_reinstalling(self):
        self._create_database("recovery")
        data = self.case_dir / "data"
        connection = self._connection("recovery")

        site = harness.Site(harness.BINARY, self.case_dir / "first")
        site.start(data, *connection, "--admin-user", "init-admin", "--admin-password", PASSWORD)
        assert_marker(self, data)
        self.assertEqual(current_site_name(self.case_dir, data).stdout.strip(), harness.SITE["site_name"])
        assert_readiness(self, site.log_path, data / "logs" / "caddy.log")
        site.stop()

        renamed = harness.run_drush(harness.BINARY, self.case_dir, data,
                                  "config:set", "system.site", "name", SITE_NAME, "--yes")
        self.assertEqual(renamed.returncode, 0, renamed.stderr)
        (data / "site-installed").unlink()
        (data / "installation-progress").write_text('["install","modules"]')

        # The recovery passes no database options, so the recorded settings decide the backend.
        site = harness.Site(harness.BINARY, self.case_dir / "recovery")
        site.start(data, "--admin-user", "init-admin", "--admin-password", PASSWORD)
        assert_marker(self, data)
        self.assertEqual(current_site_name(self.case_dir, data).stdout.strip(), SITE_NAME)
        assert_readiness(self, site.log_path, data / "logs" / "caddy.log")
        driver = harness.run_drush(harness.BINARY, self.case_dir, data, "status", "--field=db-driver")
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
        renamed = harness.run_drush(harness.BINARY, self.case_dir, data,
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
        self.server.execute("occupied", "CREATE TABLE tenant (id integer)")
        data = self.case_dir / "data"
        connection = self._connection("occupied")
        text = harness.refuse(self, self.case_dir, "occupied-database", "--data-dir", str(data), *connection)
        self.assertIn(str(data), text, "the refusal does not name the Site data directory")

        tables = self.server.query(
            "occupied", "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'")
        self.assertEqual(tables, "1", "the refused start left an unexpected number of tables")
        tenant = self.server.query(
            "occupied", "SELECT count(*) FROM information_schema.tables WHERE table_name = 'tenant'")
        self.assertEqual(tenant, "1", "the refused start dropped the existing tables")
