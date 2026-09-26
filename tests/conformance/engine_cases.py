"""The engine executable serves a Drupal project folder as is and runs its Drush.

The project is a copy of the site's own application. Its settings name a copy of
the seed database outside the folder, so every write Drupal makes inside the
folder lands in its public files directory.
"""

import http.client
import json
import os
import shutil
import subprocess
import time
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import urlopen

import harness

SETTINGS = """<?php
$databases['default']['default'] = [
  'driver' => 'sqlite',
  'database' => {database},
  'namespace' => 'Drupal\\\\sqlite\\\\Driver\\\\Database\\\\sqlite',
  'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/',
];
$settings['hash_salt'] = 'engine-executable-case';
"""


class EngineExecutable(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)
    ENGINE = True

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.engine = harness.engine_executable()
        cls.case_dir = harness.fresh_dir(harness.RESULTS / cls.__name__)
        cls.project = cls.case_dir / "project"
        shutil.copytree(cls.site_application(), cls.project, symlinks=True)
        cls.docroot = cls.project / harness.SITE["docroot"]
        cls.files = cls.docroot / "sites" / "default" / "files"
        shutil.copytree(cls.project / "seed" / "files", cls.files)
        database = cls.case_dir / "database" / "site.sqlite"
        database.parent.mkdir()
        shutil.copyfile(cls.project / "seed" / "site.sqlite", database)
        (cls.docroot / "sites" / "default" / "settings.php").write_text(
            SETTINGS.format(database=json.dumps(str(database))))
        # A project whose lock declares an extension no runtime carries, and no Drush.
        cls.lacking = cls.case_dir / "lacking"
        (cls.lacking / "web").mkdir(parents=True)
        (cls.lacking / "web" / "index.php").write_text("<?php")
        (cls.lacking / "vendor").mkdir()
        (cls.lacking / "vendor" / "autoload.php").write_text("<?php")
        (cls.lacking / "composer.lock").write_text(json.dumps(
            {"packages": [{"name": "acme/absent", "require": {"ext-drupack-absent": "*"}}]}))

    @classmethod
    def site_application(cls):
        """The site executable's own unpacked application, which a php-cli probe names."""
        probe = cls.case_dir / "application.php"
        probe.write_text("<?php echo getenv('DRUPACK_RUNTIME_APP_DIR');")
        result = harness.run([str(harness.BINARY), "php-cli", str(probe)], capture_output=True, text=True,
                             timeout=harness.WAITS["php_cli"].seconds)
        if result.returncode != 0:
            raise AssertionError(result.stderr)
        return Path(result.stdout)

    def engine_run(self, *arguments, cwd=None, env=None):
        return harness.run([str(self.engine), *arguments], cwd=cwd or self.case_dir, env=env,
                           capture_output=True, text=True, timeout=harness.WAITS["dr"].seconds)

    def serve(self, name, *arguments, cwd=None):
        """Starts serve on a port of its own, waits for /user/login, and returns the port and log."""
        port = harness.pick_port()
        log = self.case_dir / f"{name}.log"
        with open(log, "wb") as handle:
            process = harness.popen([str(self.engine), "serve", *arguments, "--listen", f"127.0.0.1:{port}"],
                                    cwd=cwd or self.case_dir, stdout=handle, stderr=subprocess.STDOUT,
                                    start_new_session=True)
        self.addCleanup(harness.stop_process, process, harness.WAITS["stop"].seconds, f": inspect {log}")
        deadline = time.monotonic() + harness.WAITS["start"].seconds
        while time.monotonic() < deadline:
            if process.poll() is not None:
                self.fail(f"serve exited before answering: inspect {log}")
            if self.status(port, "/user/login") == 200:
                return port, log
            time.sleep(0.25)
        self.fail(f"serve did not answer /user/login: inspect {log}")

    @staticmethod
    def status(port, path):
        try:
            with urlopen(f"http://127.0.0.1:{port}{path}", timeout=harness.WAITS["http_request"].seconds) as response:
                return response.status
        except HTTPError as error:
            return error.code
        except (URLError, ConnectionError, TimeoutError):
            return None

    def snapshot(self):
        """Size and modification time of every project file outside the public files directory."""
        return {
            path.relative_to(self.project): (path.lstat().st_size, path.lstat().st_mtime_ns)
            for path in self.project.rglob("*")
            if not path.is_relative_to(self.files) and not path.is_dir()
        }

    def test_serve_serves_the_folder_and_writes_only_its_files(self):
        before = self.snapshot()
        port, log = self.serve("serve-directory", str(self.project))
        self.assertEqual(self.status(port, "/"), 200)
        self.assertEqual(self.status(port, "/sites/default/settings.php"), 404)
        link = urlsplit(harness.wait_for_line(log, 0, "  Login:", harness.WAITS["start"].seconds))
        connection = http.client.HTTPConnection("127.0.0.1", port, timeout=harness.WAITS["http_request"].seconds)
        connection.request("GET", link.path)
        response = connection.getresponse()
        self.assertEqual(response.status, 302, f"the login link did not sign in: inspect {log}")
        self.assertIn("SESS", response.getheader("Set-Cookie", ""))
        connection.close()
        self.doCleanups()
        self.assertEqual(self.snapshot(), before, "serve changed the project outside its public files directory")

    def test_serve_without_a_directory_serves_the_working_directory(self):
        port, _ = self.serve("serve-working-directory", cwd=self.project)
        self.assertEqual(self.status(port, "/"), 200)

    def test_serve_refuses_a_lock_declaring_an_extension_the_runtime_lacks(self):
        result = self.engine_run("serve", str(self.lacking))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("drupack-absent: acme/absent", result.stderr)

    def test_dr_runs_the_drush_of_the_project_holding_the_working_directory(self):
        result = self.engine_run("dr", "status", "--field=db-driver", cwd=self.docroot / "core")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), "sqlite")
        version = self.engine_run("dr", "status", "--field=drupal-version", cwd=self.docroot / "core")
        self.assertEqual(version.returncode, 0, version.stderr)
        self.assertRegex(version.stdout.strip(), r"^\d+\.\d+\.\d+")

    def test_drush_child_processes_run_on_the_runtime_php_with_no_php_on_path(self):
        version = self.engine_run("php", "-r", "echo PHP_VERSION;")
        self.assertEqual(version.returncode, 0, version.stderr)
        empty = harness.fresh_dir(self.case_dir / "empty-path")
        child = "passthru('php -r \"echo PHP_VERSION;\"');"
        result = self.engine_run("dr", "php:eval", child, cwd=self.project,
                                 env=dict(os.environ, PATH=str(empty)))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), version.stdout)

    def test_dr_names_the_drush_a_project_lacks(self):
        result = self.engine_run("dr", "status", cwd=self.lacking / "web")
        self.assertNotEqual(result.returncode, 0)
        # serve.php prints paths in Drupack's canonical form, forward slashes on Windows too.
        self.assertIn(f"{self.lacking.as_posix()}/vendor/drush/drush/drush.php does not exist", result.stderr)

    def test_php_runs_a_script_named_from_the_working_directory(self):
        (self.case_dir / "script.php").write_text("<?php echo 'engine-php';")
        result = self.engine_run("php", "script.php")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, "engine-php")
