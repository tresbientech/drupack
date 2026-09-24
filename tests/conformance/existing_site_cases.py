"""A first start against a MySQL database that already holds a site keeps that site.

A site built without a recipe starts only this way. The case installs Drupal's minimal
profile with the executable's own Drush, then starts the executable on that database.
"""

import json
import os
from pathlib import Path

import harness

ADMIN_USER = "existing-admin"
SITE_NAME = "Existing site"


class ExistingSiteAdoption(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX,)
    RECIPE = False

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.case_dir = harness.RESULTS / cls.__name__
        cls.case_dir.mkdir(parents=True, exist_ok=True)
        cls.server = harness.DatabaseServer("mysql", "existing-site", cls.case_dir)
        cls.server.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.stop()

    def drush(self, installer, *command):
        """Runs the application's Drush on the installer's Site data, as build/seed.sh does."""
        app = self.application()
        env = dict(os.environ, DRUPACK_RUNTIME_DATA_DIR=str(installer), DRUPACK_RUNTIME_FILES_DIR=str(installer / "files"),
                   DRUPACK_RUNTIME_HOST="localhost")
        return harness.run(
            [str(harness.BINARY), "php-cli", str(app / "vendor" / "drush" / "drush" / "drush.php"),
             f"--root={app / harness.SITE['docroot']}", *command],
            cwd=self.case_dir, env=env, capture_output=True, text=True,
            timeout=harness.WAITS["database_install"].seconds,
        )

    def application(self):
        probe = self.case_dir / "application.php"
        probe.write_text("<?php echo getenv('DRUPACK_RUNTIME_APP_DIR');")
        result = harness.run([str(harness.BINARY), "php-cli", str(probe)], capture_output=True, text=True,
                             timeout=harness.WAITS["php_cli"].seconds)
        self.assertEqual(result.returncode, 0, result.stderr)
        return Path(result.stdout)

    def install(self):
        """Installs the minimal profile into the server's database and returns its enabled modules.

        The engine's settings template keeps public files in the installer's own Site data,
        away from the application every start of this release shares.
        """
        installer = self.case_dir / "installer"
        for directory in ("config", "private", "tmp", "files"):
            (installer / directory).mkdir(parents=True, exist_ok=True)
        (installer / "hash_salt").write_text("existing-site-hash-salt")
        database = (
            f"['driver' => 'mysql', 'database' => 'drupal', 'username' => '{harness.DATABASE_USER}', "
            f"'password' => '{harness.DATABASE_PASSWORD}', 'host' => '{self.server.host}', "
            f"'port' => '{self.server.port}', 'prefix' => '', "
            "'namespace' => 'Drupal\\\\mysql\\\\Driver\\\\Database\\\\mysql', "
            "'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/']"
        )
        template = (self.application() / "settings.php").read_text()
        (installer / "settings.php").write_text(
            template.replace("__DRUPACK_DATABASE_CONFIGURATION__", database).replace("__DRUPACK_SITE_SETTINGS__", "''"))
        installed = self.drush(installer, "site:install", "minimal", "--yes", f"--site-name={SITE_NAME}",
                               f"--account-name={ADMIN_USER}", f"--account-pass={harness.DATABASE_PASSWORD}")
        self.assertEqual(installed.returncode, 0, installed.stdout + installed.stderr)
        # Hosted sites often block uid 1, which leaves a start no one-time link to mint.
        blocked = self.drush(installer, "user:block", ADMIN_USER)
        self.assertEqual(blocked.returncode, 0, blocked.stdout + blocked.stderr)
        enabled = self.drush(installer, "pm:list", "--status=enabled", "--format=json")
        self.assertEqual(enabled.returncode, 0, enabled.stderr)
        return sorted(json.loads(enabled.stdout))

    def test_first_start_adopts_the_site_the_database_holds(self):
        connection = self.server.connection()
        if not harness.SITE["recipe"]:
            # The database holds no site yet, and this site has no recipe to install one.
            text = harness.refuse(self, self.case_dir, "empty-database",
                                  "--data-dir", str(self.case_dir / "refused"), *connection)
            self.assertIn("has no recipe to install one", text)
        before = self.install()

        data = self.case_dir / "data"
        site = harness.Site(harness.BINARY, self.case_dir / "first-start")
        site.start(data, *connection, ready_wait="database_start")
        try:
            self.assertEqual(site.fetch("/")[0], 200)
        finally:
            site.stop()
        log = site.log_path.read_text(errors="replace")
        self.assertIn("This database already holds a site", log)
        self.assertIn("Cannot mint a one-time login link", log)

        name = harness.run_dr(harness.BINARY, self.case_dir, data, "config:get", "system.site", "name",
                              "--format=string")
        self.assertEqual(name.stdout.strip(), SITE_NAME, name.stderr)
        enabled = harness.run_dr(harness.BINARY, self.case_dir, data, "pm:list", "--status=enabled",
                                 "--format=json")
        self.assertEqual(enabled.returncode, 0, enabled.stderr)
        self.assertEqual(sorted(json.loads(enabled.stdout)), before, "the first start changed the enabled modules")
