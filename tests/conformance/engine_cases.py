"""The engine executable serves a Drupal project folder as is and runs its Drush.

The project is a copy of the site's own application. Its settings name a copy of
the seed database outside the folder, so every write Drupal makes inside the
folder lands in its public files directory.
"""

import http.client
import json
import os
import shlex
import shutil
import subprocess
import threading
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

# The two forms the php command takes, which every refusal lists.
USAGE = "Usage: drupack php SCRIPT [ARGUMENTS]\n       drupack php -r CODE"


class EngineExecutable(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)
    ENGINE = True

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.engine = harness.engine_executable()
        harness.fresh_dir(cls.class_dir)
        cls.project = cls.class_dir / "project"
        shutil.copytree(cls.site_application(), cls.project, symlinks=True)
        cls.docroot = cls.project / harness.SITE["docroot"]
        cls.files = cls.docroot / "sites" / "default" / "files"
        # A release build of the application may carry an empty files directory already.
        shutil.copytree(cls.project / "seed" / "files", cls.files, dirs_exist_ok=True)
        database = cls.class_dir / "database" / "site.sqlite"
        database.parent.mkdir()
        shutil.copyfile(cls.project / "seed" / "site.sqlite", database)
        (cls.docroot / "sites" / "default" / "settings.php").write_text(
            SETTINGS.format(database=json.dumps(str(database))))
        # A project whose lock declares an extension no runtime carries, and no Drush.
        cls.lacking = cls.class_dir / "lacking"
        (cls.lacking / "web" / "core" / "lib").mkdir(parents=True)
        (cls.lacking / "web" / "index.php").write_text("<?php")
        (cls.lacking / "web" / "core" / "lib" / "Drupal.php").write_text("<?php")
        (cls.lacking / "vendor").mkdir()
        (cls.lacking / "vendor" / "autoload.php").write_text("<?php")
        (cls.lacking / "composer.lock").write_text(json.dumps(
            {"packages": [{"name": "acme/absent", "require": {"ext-drupack-absent": "*"}}]}))

    @classmethod
    def site_application(cls):
        """The site executable's own unpacked application, which a php-cli probe names."""
        probe = cls.class_dir / "application.php"
        probe.write_text("<?php echo getenv('DRUPACK_RUNTIME_APP_DIR');")
        result = harness.run([str(harness.BINARY), "php-cli", str(probe)], capture_output=True, text=True,
                             timeout=harness.WAITS["php_cli"].seconds)
        if result.returncode != 0:
            raise AssertionError(result.stderr)
        return Path(result.stdout)

    def engine_run(self, *arguments, cwd=None, env=None):
        return harness.run([str(self.engine), *arguments], cwd=cwd or self.class_dir, env=env,
                           capture_output=True, text=True, timeout=harness.WAITS["drush"].seconds)

    def start(self, name, *arguments, cwd=None, env=None):
        """Starts the engine executable on a port of its own, waits for /user/login, and returns the port and log."""
        port = harness.pick_port()
        log = self.class_dir / f"{name}.log"
        with open(log, "wb") as handle:
            process = harness.popen([str(self.engine), *arguments, "--foreground", "--listen", f"127.0.0.1:{port}"],
                                    cwd=cwd or self.class_dir, env=env, stdout=handle, stderr=subprocess.STDOUT,
                                    start_new_session=True)
        self.addCleanup(harness.stop_process, process, harness.WAITS["stop"].seconds, f": inspect {log}")
        deadline = time.monotonic() + harness.WAITS["start"].seconds
        while time.monotonic() < deadline:
            if process.poll() is not None:
                self.fail(f"the start exited before answering: inspect {log}")
            if self.status(port, "/user/login") == 200:
                return port, log
            time.sleep(0.25)
        self.fail(f"the start did not answer /user/login: inspect {log}")

    @staticmethod
    def status(port, path):
        try:
            with urlopen(f"http://127.0.0.1:{port}{path}", timeout=harness.WAITS["http_request"].seconds) as response:
                return response.status
        except HTTPError as error:
            return error.code
        except (URLError, ConnectionError, TimeoutError):
            return None

    def detached_start(self, *arguments, cwd=None):
        """Starts the engine executable in the background on a port of its own, which the
        case's cleanup stops, and returns the port and the finished start."""
        port = harness.pick_port()
        target = str(cwd or self.class_dir)
        self.addCleanup(self.engine_run, "stop", str(self.project), cwd=target)
        start = harness.run([str(self.engine), *arguments, "--listen", f"127.0.0.1:{port}"], cwd=target,
                            capture_output=True, text=True, timeout=harness.WAITS["start"].seconds)
        return port, start

    def snapshot(self):
        """Size and modification time of every project file outside the public files directory."""
        return {
            path.relative_to(self.project): (path.lstat().st_size, path.lstat().st_mtime_ns)
            for path in self.project.rglob("*")
            if not path.is_relative_to(self.files) and not path.is_dir()
        }

    def test_a_start_serves_the_folder_and_writes_only_its_files(self):
        before = self.snapshot()
        port, log = self.start("start-directory", str(self.project))
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
        self.assertEqual(self.snapshot(), before, "the start changed the project outside its public files directory")

    def test_a_detached_start_returns_once_ready_and_stop_ends_it(self):
        port, start = self.detached_start(str(self.project))
        self.assertEqual(start.returncode, 0, f"the start exited non-zero: {start.stdout}{start.stderr}")
        self.assertIn("  Login:", start.stdout)
        self.assertIn("runs in the background. Its log: ", start.stdout)
        self.assertNotIn("Press Ctrl+C", start.stdout)
        self.assertEqual(self.status(port, "/"), 200)
        stop = self.engine_run("stop", str(self.project))
        self.assertEqual(stop.returncode, 0, stop.stderr)
        self.assertIn("stopped", stop.stdout)
        self.assertIsNone(self.status(port, "/"), "the server still answers after stop")
        again = self.engine_run("stop", str(self.project))
        self.assertEqual(again.returncode, 0, again.stderr)
        self.assertIn("is not running", again.stdout)

    def test_the_printed_stop_command_names_a_folder_started_from_another_directory(self):
        port, start = self.detached_start(str(self.project))
        self.assertEqual(start.returncode, 0, f"{start.stdout}{start.stderr}")
        command = f"    {self.engine} stop {self.project}\n"
        self.assertIn(f"Stop it with:\n\n{command}", start.stdout)
        stop = harness.run(shlex.split(command, posix=os.name != "nt"), cwd=self.class_dir.parent,
                           capture_output=True, text=True, timeout=harness.WAITS["stop"].seconds + 30)
        self.assertEqual(stop.returncode, 0, stop.stderr)
        self.assertIn("stopped", stop.stdout)
        self.assertIsNone(self.status(port, "/"), "the printed command left the server running")

    def test_a_detached_start_and_stop_leave_the_project_unchanged(self):
        before = self.snapshot()
        port, start = self.detached_start(cwd=self.project)
        self.assertEqual(start.returncode, 0, f"the start exited non-zero: {start.stdout}{start.stderr}")
        self.assertEqual(self.status(port, "/"), 200)
        stop = self.engine_run("stop", cwd=self.project)
        self.assertEqual(stop.returncode, 0, stop.stderr)
        self.assertEqual(self.snapshot(), before, "the start or stop changed the project outside its public files directory")

    def test_a_second_start_on_a_served_folder_says_it_already_serves(self):
        _, first = self.detached_start(str(self.project))
        self.assertEqual(first.returncode, 0, f"{first.stdout}{first.stderr}")
        second = self.engine_run(str(self.project), "--listen", f"127.0.0.1:{harness.pick_port()}")
        self.assertEqual(second.returncode, 1, second.stdout)
        self.assertIn(f"drupack already serves {self.project.as_posix()}", second.stderr)

    def test_a_start_in_the_foreground_is_stopped_by_stop(self):
        port, _ = self.start("start-stopped", str(self.project))
        stop = self.engine_run("stop", str(self.project))
        self.assertEqual(stop.returncode, 0, stop.stderr)
        self.assertIsNone(self.status(port, "/"), "the server still answers after stop")

    def test_a_stop_on_a_missing_folder_names_it(self):
        result = self.engine_run("stop", str(self.class_dir / "never-served"))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("No such directory", result.stderr)

    def test_a_start_serves_a_folder_whose_path_holds_a_space(self):
        spaced = self.class_dir / "spaced project"
        shutil.copytree(self.project, spaced, symlinks=True)
        temporary = harness.fresh_dir(self.class_dir / "start-space-temporary")
        env = dict(os.environ, TMPDIR=str(temporary), TMP=str(temporary), TEMP=str(temporary))
        port, _ = self.start("start-space", str(spaced), env=env)
        self.assertEqual(self.status(port, "/"), 200)
        self.assertEqual(list(temporary.glob("*Caddyfile*")), [], "the start wrote a Caddyfile into the temporary directory")

    def test_a_start_without_a_directory_serves_the_working_directory(self):
        port, _ = self.start("start-working-directory", cwd=self.project)
        self.assertEqual(self.status(port, "/"), 200)

    def test_a_stop_ends_a_request_that_is_still_running(self):
        # FrankenPHP waits 30s for a running request before it forces the stop, the
        # harness's own stop wait, so only the runtime's 10s deadline passes this case.
        sleeper = self.class_dir / "sleeper"
        (sleeper / "web" / "core" / "lib").mkdir(parents=True)
        (sleeper / "web" / "core" / "lib" / "Drupal.php").write_text("<?php")
        (sleeper / "web" / "index.php").write_text(
            "<?php if ($_SERVER['REQUEST_URI'] !== '/user/login') { sleep(60); } echo 'ok';")
        # A start mints its login link with the folder's Drush; this one fails, and the start serves on.
        (sleeper / "vendor" / "drush" / "drush").mkdir(parents=True)
        (sleeper / "vendor" / "drush" / "drush" / "drush.php").write_text("<?php exit(1);")
        port, _ = self.start("start-sleeper", str(sleeper))
        request = threading.Thread(target=self.status, args=(port, "/"), daemon=True)
        request.start()
        time.sleep(1)
        self.doCleanups()

    def test_a_start_without_drush_serves_with_no_login_link(self):
        bare = self.class_dir / "bare"
        (bare / "web" / "core" / "lib").mkdir(parents=True)
        (bare / "web" / "core" / "lib" / "Drupal.php").write_text("<?php")
        (bare / "web" / "index.php").write_text("<?php echo 'ok';")
        _, log = self.start("start-bare", str(bare))
        output = log.read_text()
        self.assertIn(f"No login link: {bare.as_posix()} has no Drush.", output)
        self.assertNotIn("  Login:", output)

    def test_a_start_refuses_a_lock_declaring_an_extension_the_runtime_lacks(self):
        result = self.engine_run(str(self.lacking))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("drupack-absent: acme/absent", result.stderr)

    def test_a_start_refuses_a_folder_without_drupal_core(self):
        wordpress = self.class_dir / "wordpress"
        (wordpress / "wp-includes").mkdir(parents=True)
        (wordpress / "index.php").write_text("<?php")
        (wordpress / "wp-includes" / "version.php").write_text("<?php")
        (wordpress / "composer.json").write_text(
            json.dumps({"extra": {"drupal-scaffold": {"locations": {"web-root": "./"}}}}))
        result = self.engine_run(str(wordpress))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(f"{wordpress.as_posix()}/core/lib/Drupal.php does not exist", result.stderr)

    def test_drush_runs_the_drush_of_the_project_holding_the_working_directory(self):
        result = self.engine_run("drush", "status", "--field=db-driver", cwd=self.docroot / "core")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), "sqlite")
        version = self.engine_run("drush", "status", "--field=drupal-version", cwd=self.docroot / "core")
        self.assertEqual(version.returncode, 0, version.stderr)
        self.assertRegex(version.stdout.strip(), r"^\d+\.\d+\.\d+")

    def test_drush_child_processes_run_on_the_runtime_php_with_no_php_on_path(self):
        version = self.engine_run("php", "-r", "echo PHP_VERSION;")
        self.assertEqual(version.returncode, 0, version.stderr)
        empty = harness.fresh_dir(self.class_dir / "empty-path")
        child = "passthru('php -r \"echo PHP_VERSION;\"');"
        result = self.engine_run("drush", "php:eval", child, cwd=self.project,
                                 env=dict(os.environ, PATH=str(empty)))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), version.stdout)

    def test_dr_runs_drupal_cores_command_line(self):
        result = self.engine_run("dr", "list", cwd=self.project)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("cache:rebuild", result.stdout)

    def test_drush_names_the_drush_a_project_lacks(self):
        result = self.engine_run("drush", "status", cwd=self.lacking / "web")
        self.assertNotEqual(result.returncode, 0)
        # serve.php prints paths in Drupack's canonical form, forward slashes on Windows too.
        self.assertIn(f"{self.lacking.as_posix()}/vendor/drush/drush/drush.php does not exist", result.stderr)

    def alias_run(self, *commands):
        """Runs each shell command from class_dir inside Drush, whose PATH names the php alias, and returns
        each one's merged output and exit status."""
        code = ("chdir(getenv('ENGINE_CASE_DIR')); $results = [];"
                " foreach (json_decode(getenv('ENGINE_CASE_COMMANDS')) as $command) {"
                " $output = []; exec($command . ' 2>&1', $output, $status);"
                " $results[] = [implode(\"\\n\", $output), $status]; }"
                " echo json_encode($results);")
        result = self.engine_run("drush", "php:eval", code, cwd=self.project, env=dict(
            os.environ, ENGINE_CASE_DIR=str(self.class_dir), ENGINE_CASE_COMMANDS=json.dumps(commands)))
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_php_runs_a_script_with_its_arguments_in_the_working_directory(self):
        (self.class_dir / "arguments.php").write_text(
            "<?php echo getcwd(), \"\\n\", implode(',', array_slice($argv, 1)); exit(3);")
        result = self.engine_run("php", "arguments.php", "a", "b")
        self.assertEqual(result.returncode, 3, result.stderr)
        directory, arguments = result.stdout.split("\n")
        self.assertEqual(Path(directory).resolve(), self.class_dir.resolve())
        self.assertEqual(arguments, "a,b")
        [[output, status]] = self.alias_run("php arguments.php a b")
        self.assertEqual(status, 3, output)
        directory, arguments = output.split("\n")
        self.assertEqual(Path(directory).resolve(), self.class_dir.resolve())
        self.assertEqual(arguments, "a,b")

    def test_an_inherited_application_directory_does_not_reach_the_runtime(self):
        env = dict(os.environ, DRUPACK_RUNTIME_APP_DIR=str(self.lacking))
        (self.class_dir / "arguments.php").write_text("<?php echo getcwd();")
        result = self.engine_run("php", "arguments.php", env=env)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(Path(result.stdout).resolve(), self.class_dir.resolve())
        port = harness.pick_port()
        log = self.class_dir / "inherited.log"
        with open(log, "wb") as handle:
            process = harness.popen([str(self.engine), str(self.project), "--foreground", "--listen", f"127.0.0.1:{port}"],
                                    cwd=self.class_dir, env=env, stdout=handle, stderr=subprocess.STDOUT,
                                    start_new_session=True)
        self.addCleanup(harness.stop_process, process, harness.WAITS["stop"].seconds, f": inspect {log}")
        deadline = time.monotonic() + harness.WAITS["start"].seconds
        while self.status(port, "/") != 200:
            self.assertIsNone(process.poll(), f"the start exited before answering: inspect {log}")
            self.assertLess(time.monotonic(), deadline, f"the start did not answer /: inspect {log}")
            time.sleep(0.25)

    def test_php_runs_code(self):
        result = self.engine_run("php", "-r", "echo 1;")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, "1")

    def test_php_refuses_an_option_by_name_before_php_starts(self):
        (self.class_dir / "sentinel.php").write_text("<?php echo 'php-ran';")
        commands = (["-d", "x=1", "sentinel.php"], ["-l", "sentinel.php"], ["-v"], ["-S", "127.0.0.1:0", "sentinel.php"])
        for command in commands:
            with self.subTest(route="launcher", option=command[0]):
                result = self.engine_run("php", *command)
                self.assertEqual(result.returncode, 1, result.stderr)
                self.assertEqual(result.stdout, "")
                self.assertIn(f"php option {command[0]} is not supported.", result.stderr)
                self.assertIn(USAGE, result.stderr)
                self.assertNotIn("php-ran", result.stderr)
        results = self.alias_run(*(" ".join(["php", *command]) for command in commands))
        for command, (output, status) in zip(commands, results):
            with self.subTest(route="alias", option=command[0]):
                self.assertEqual(status, 1, output)
                self.assertIn(f"php option {command[0]} is not supported.", output)
                self.assertIn(USAGE, output)
                self.assertNotIn("php-ran", output)

    def test_php_refuses_an_argument_after_code(self):
        result = self.engine_run("php", "-r", "echo 'php-ran';", "extra")
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertEqual(result.stdout, "")
        self.assertIn("extra follows it", result.stderr)
        self.assertIn(USAGE, result.stderr)

    def test_php_reads_no_settings_planted_in_the_temporary_directory(self):
        temporary = harness.fresh_dir(self.class_dir / "temporary")
        planted = temporary / "drupack-php-e3b0c44298fc1c14"
        planted.mkdir()
        (planted / "prepend.php").write_text("<?php echo 'planted';")
        (planted / "settings.ini").write_text(f"auto_prepend_file={json.dumps(str(planted / 'prepend.php'))}\n")
        (self.class_dir / "script.php").write_text("<?php echo 'engine-php';")
        env = dict(os.environ, TMPDIR=str(temporary), TMP=str(temporary), TEMP=str(temporary))
        script = self.engine_run("php", "script.php", env=env)
        self.assertEqual(script.returncode, 0, script.stderr)
        self.assertEqual(script.stdout, "engine-php")
        code = self.engine_run("php", "-r", "echo 1;", env=env)
        self.assertEqual(code.returncode, 0, code.stderr)
        self.assertEqual(code.stdout, "1")
        self.assertEqual([path.name for path in temporary.iterdir()], [planted.name])

    def test_php_passes_arguments_through_the_alias_unchanged(self):
        # Drush starts its child processes through Symfony Process, which runs php.cmd through cmd.exe on Windows.
        arguments = ["a b", 'say "hi"', "100%", "%PATH%"]
        (self.class_dir / "argv.php").write_text("<?php echo json_encode(array_slice($argv, 1));")
        code = ("$process = new Symfony\\Component\\Process\\Process(array_merge(['php', 'argv.php'],"
                " json_decode(getenv('ENGINE_CASE_ARGUMENTS'))), getenv('ENGINE_CASE_DIR'));"
                " $process->run(); echo $process->getOutput(); fwrite(STDERR, $process->getErrorOutput());")
        result = self.engine_run("drush", "php:eval", code, cwd=self.project, env=dict(
            os.environ, ENGINE_CASE_DIR=str(self.class_dir), ENGINE_CASE_ARGUMENTS=json.dumps(arguments)))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), arguments, result.stderr)
