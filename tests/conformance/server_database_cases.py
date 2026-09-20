"""Ported from tests/server-database.sh: MySQL and PostgreSQL as the site's live backend.

Each backend gets its own class: a container started in setUpClass and torn down in
tearDownClass, following initialization_cases.PostgresqlLifecycle's shape.
_ServerDatabaseBackend carries the shared container lifecycle and the one test method; it
mixes into each backend's ConformanceCase rather than being one itself, so unittest never
collects it on its own with no DATABASE or IMAGE set.
"""

import json
import os
import subprocess
import time
from urllib.error import HTTPError

import harness

PASSWORD = "Server.database.test.2026"
ADMIN_USER = "server-admin"
# mysql:8.4.11 and postgres:17.11, pinned the way tests/server-database.sh pinned them:
# docker buildx imagetools inspect IMAGE --format '{{.Manifest.Digest}}'
MYSQL_IMAGE = "mysql:8.4.11@sha256:85b9bf2e29cf836ecb8c2a15a935d4ba0c606631dff1dd79531a11983c638f2a"
POSTGRES_IMAGE = "postgres:17.11@sha256:67f41722b7a8cbdb868a44a4995c846eddfdc2973bccb291ce937dce88ad5675"


class _ServerDatabaseBackend:
    """Shared setUpClass/tearDownClass and the one test method; DATABASE, IMAGE, CONTAINER_PORT
    and RUN_ENV come from the concrete backend below, and _ready_probe names its readiness command.
    """

    PLATFORMS = (harness.LINUX,)
    TOOLS = ("docker",)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)
        cls.container = f"drupack-server-database-{cls.DATABASE}-{os.getpid()}"
        subprocess.run(
            ["docker", "run", "-d", "--name", cls.container, "-p", f"127.0.0.1::{cls.CONTAINER_PORT}",
             *cls.RUN_ENV, cls.IMAGE],
            check=True, capture_output=True, timeout=harness.WAITS["database_container"].seconds,
        )
        # unittest skips tearDownClass once setUpClass raises, so a failure past this point
        # removes the container itself before re-raising, the way the old script's EXIT trap did.
        try:
            port_output = subprocess.run(
                ["docker", "port", cls.container, f"{cls.CONTAINER_PORT}/tcp"], check=True,
                capture_output=True, text=True, timeout=harness.WAITS["docker_admin"].seconds,
            ).stdout
            cls.port = int(port_output.strip().splitlines()[0].rsplit(":", 1)[-1])
            deadline = time.monotonic() + harness.WAITS["database_ready"].seconds
            ready = False
            while time.monotonic() < deadline:
                probe = subprocess.run(cls._ready_probe(), capture_output=True,
                                        timeout=harness.WAITS["docker_admin"].seconds)
                if probe.returncode == 0:
                    ready = True
                    break
                time.sleep(2)
            if not ready:
                raise AssertionError(
                    f"the {cls.DATABASE} container did not become ready within "
                    f"{harness.WAITS['database_ready'].seconds}s"
                )
        except Exception:
            subprocess.run(["docker", "rm", "-f", cls.container], capture_output=True,
                            timeout=harness.WAITS["docker_admin"].seconds)
            raise

    @classmethod
    def tearDownClass(cls):
        with open(cls.class_dir / f"{cls.DATABASE}-server.log", "wb") as handle:
            subprocess.run(["docker", "logs", cls.container], stdout=handle, stderr=subprocess.STDOUT,
                            timeout=harness.WAITS["docker_admin"].seconds)
        subprocess.run(["docker", "rm", "-f", cls.container], capture_output=True,
                        timeout=harness.WAITS["docker_admin"].seconds)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def _connection(self):
        return ["--database", self.DATABASE, "--db-host", "127.0.0.1", "--db-port", str(self.port),
                "--db-name", "drupal", "--db-user", "drupal", "--db-password", PASSWORD]

    def test_first_start_then_restart(self):
        data = self.case_dir / "data"
        site = harness.Site(harness.BINARY, self.case_dir / "first-start")
        site.start(data, *self._connection(), "--admin-user", ADMIN_USER, "--admin-password", PASSWORD,
                   ready_wait="database_start")
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

        driver = harness.run_dr(harness.BINARY, self.case_dir, data, "status", "--field=db-driver")
        self.assertEqual(driver.stdout.strip(), self.DATABASE, driver.stderr)
        bootstrap = harness.run_dr(harness.BINARY, self.case_dir, data, "status", "--field=bootstrap")
        self.assertEqual(bootstrap.stdout.strip(), "Successful", bootstrap.stderr)
        self.assertFalse((data / "site.sqlite").exists(), "Site data holds a SQLite database")

        # The Mercury Demo recipe enables these during site:install; a runtime install must
        # remove them, matching the Dockerfile's seed cleanup, or cron stalls for 240s.
        enabled = harness.run_dr(harness.BINARY, self.case_dir, data, "pm:list", "--status=enabled", "--format=json")
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
    IMAGE = MYSQL_IMAGE
    CONTAINER_PORT = 3306
    RUN_ENV = ("-e", "MYSQL_DATABASE=drupal", "-e", "MYSQL_USER=drupal",
               "-e", f"MYSQL_PASSWORD={PASSWORD}", "-e", "MYSQL_RANDOM_ROOT_PASSWORD=yes")

    @classmethod
    def _ready_probe(cls):
        # The image's initialization server listens on a socket only, so a TCP query waits
        # for the final server, the same probe tests/server-database.sh used.
        return ["docker", "exec", cls.container, "mysql", "-h", "127.0.0.1", "-u", "drupal",
                f"-p{PASSWORD}", "drupal", "-e", "SELECT 1"]


class PostgresqlServerDatabase(_ServerDatabaseBackend, harness.ConformanceCase):
    DATABASE = "pgsql"
    IMAGE = POSTGRES_IMAGE
    CONTAINER_PORT = 5432
    RUN_ENV = ("-e", "POSTGRES_DB=drupal", "-e", "POSTGRES_USER=drupal", "-e", f"POSTGRES_PASSWORD={PASSWORD}")

    @classmethod
    def _ready_probe(cls):
        return ["docker", "exec", cls.container, "pg_isready", "-h", "127.0.0.1", "-U", "drupal", "-d", "drupal"]
