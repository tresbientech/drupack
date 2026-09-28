"""Ported from tests/server-database.sh: MySQL and PostgreSQL as the site's live backend.

Each backend gets its own class and its own harness.DatabaseServer, started in setUpClass
and stopped in tearDownClass. _ServerDatabaseBackend carries that lifecycle and the one test
method; it mixes into each backend's ConformanceCase rather than being one itself, so
unittest never collects it on its own with no DATABASE set.
"""

import json
import re
import threading
from pathlib import Path
from urllib.error import HTTPError

import harness

ADMIN_USER = "server-admin"


def collect_drush_arguments(seen, stop):
    """Adds the arguments of every Drush process /proc lists to seen until stop is set."""
    while not stop.is_set():
        for cmdline in Path("/proc").glob("[0-9]*/cmdline"):
            try:
                arguments = cmdline.read_bytes().split(b"\0")
            except OSError:
                continue
            if any(b"drush.php" in argument for argument in arguments):
                seen.add(tuple(argument.decode(errors="replace") for argument in arguments if argument))
        stop.wait(0.02)


class _ServerDatabaseBackend:
    """Shared setUpClass/tearDownClass and the one test method; DATABASE comes from the
    concrete backend below.
    """

    PLATFORMS = (harness.LINUX,)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)
        cls.server = harness.DatabaseServer(cls.DATABASE, "server-database", cls.class_dir)
        cls.server.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.stop()

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def test_first_start_then_restart(self):
        data = self.case_dir / "data"
        site = harness.Site(harness.BINARY, self.case_dir / "first-start")
        # Every local account can read a process's arguments, so no Drush child the
        # install runs may carry a password or a database URL there.
        seen = set()
        stop = threading.Event()
        watcher = threading.Thread(target=collect_drush_arguments, args=(seen, stop), daemon=True)
        watcher.start()
        try:
            site.start(data, *self.server.connection(), "--admin-user", ADMIN_USER,
                       "--admin-password", harness.DATABASE_PASSWORD, ready_wait="database_start")
        finally:
            stop.set()
            watcher.join()
        self.assertTrue([arguments for arguments in seen if "site:install" in arguments],
                        f"no Drush child ran site:install among {len(seen)} seen")
        for arguments in seen:
            line = " ".join(arguments)
            self.assertNotIn(harness.DATABASE_PASSWORD, line)
            self.assertIsNone(re.search(r"://[^/\s]*@", line), f"a Drush child named a database URL: {line}")
        try:
            self.assertEqual(site.fetch("/")[0], 200)
            with self.assertRaises(HTTPError) as error:
                site.fetch("/sites/default/settings.php")
            self.assertEqual(error.exception.code, 404)
            with self.assertRaises(HTTPError) as error:
                site.fetch("/sites/default/private/")
            self.assertEqual(error.exception.code, 403)
        finally:
            site.stop()

        driver = harness.run_drush(harness.BINARY, self.case_dir, data, "status", "--field=db-driver")
        self.assertEqual(driver.stdout.strip(), self.DATABASE, driver.stderr)
        bootstrap = harness.run_drush(harness.BINARY, self.case_dir, data, "status", "--field=bootstrap")
        self.assertEqual(bootstrap.stdout.strip(), "Successful", bootstrap.stderr)
        self.assertFalse((data / "site.sqlite").exists(), "Site data holds a SQLite database")

        # Drupal CMS recipes enable these during site:install; a runtime install must
        # remove them, matching the seed build's cleanup, or cron stalls for 240s.
        enabled = harness.run_drush(harness.BINARY, self.case_dir, data, "pm:list", "--status=enabled", "--format=json")
        self.assertEqual(enabled.returncode, 0, enabled.stderr)
        modules = json.loads(enabled.stdout)
        self.assertNotIn("automatic_updates", modules)
        self.assertNotIn("package_manager", modules)

        site = harness.Site(harness.BINARY, self.case_dir / "restart")
        site.start(data, ready_wait="database_start")
        try:
            self.assertEqual(site.fetch("/")[0], 200)
        finally:
            site.stop()


class MysqlServerDatabase(_ServerDatabaseBackend, harness.ConformanceCase):
    DATABASE = "mysql"


class PostgresqlServerDatabase(_ServerDatabaseBackend, harness.ConformanceCase):
    DATABASE = "pgsql"
