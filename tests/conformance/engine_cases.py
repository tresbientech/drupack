"""The engine executable serves a Drupal project folder as is and runs its Drush.

The project is a copy of the site's own application. Its settings name a copy of
the seed database outside the folder, so every write Drupal makes inside the
folder lands in its public files directory.
"""

import gzip
import hashlib
import http.client
import json
import os
import shlex
import shutil
import ssl
import stat
import subprocess
import threading
import time
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener, urlopen

import harness
import version_cases
from mercure_cases import HUB, TOPIC, Stream, token
from tls_cases import trusted_environment, write_root

SETTINGS = """<?php
$databases['default']['default'] = [
  'driver' => 'sqlite',
  'database' => {database},
  'namespace' => 'Drupal\\\\sqlite\\\\Driver\\\\Database\\\\sqlite',
  'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/',
];
$settings['hash_salt'] = 'engine-executable-case';
"""

USAGE = "Usage: drupack php SCRIPT [ARGUMENTS]\n       drupack php -r CODE"

PHP_MARKER_OUTPUT = "guard-probe-executed"
PHP_MARKER = f"<?php echo '{PHP_MARKER_OUTPUT}';"
TEXT_MARKER = "guard-probe-served"
SVG_MARKER = b'<svg xmlns="http://www.w3.org/2000/svg"><title>guard-probe-svgz</title></svg>'


class KeepRedirects(HTTPRedirectHandler):
    """Leaves a 3xx answer to the caller, where urllib would follow it."""

    def redirect_request(self, *_):
        return None


REDIRECTS_KEPT = build_opener(KeepRedirects())


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

    def engine_run(self, *arguments, cwd=None, env=None):
        return harness.run([str(self.engine), *arguments], cwd=cwd or self.class_dir, env=env,
                           capture_output=True, text=True, timeout=harness.WAITS["drush"].seconds)

    def start(self, name, *arguments, cwd=None, env=None):
        """Starts the engine executable on a port of its own, waits for /user/login and the ready line, and
        returns the port and log. Drupal can answer before the server writes its stop record, which precedes
        the ready line."""
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
                harness.wait_for_line(log, 0, "drupack is ready", max(deadline - time.monotonic(), 0))
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

    @staticmethod
    def fetch(port, path, headers=None):
        """The status, headers and body of one request, whatever the status, a redirect
        unfollowed. The body is latin-1 text, so encode("latin-1") returns the original bytes."""
        request = Request(f"http://127.0.0.1:{port}{path}", headers=headers or {})
        try:
            with REDIRECTS_KEPT.open(request, timeout=harness.WAITS["http_request"].seconds) as response:
                return response.status, response.headers, response.read().decode("latin-1")
        except HTTPError as error:
            return error.code, error.headers, error.read().decode("latin-1")

    def detached_start(self, *arguments, cwd=None, env=None):
        """Starts the engine executable in the background on a port of its own, which the
        case's cleanup stops, and returns the port and the finished start."""
        port = harness.pick_port()
        target = str(cwd or self.class_dir)
        self.addCleanup(self.engine_run, "stop", str(self.project), cwd=target, env=env)
        start = harness.run([str(self.engine), *arguments, "--listen", f"127.0.0.1:{port}"], cwd=target, env=env,
                            capture_output=True, text=True, timeout=harness.WAITS["start"].seconds)
        return port, start

    def snapshot(self):
        """Size and modification time of every project file outside the public files directory."""
        return {
            path.relative_to(self.project): (path.lstat().st_size, path.lstat().st_mtime_ns)
            for path in self.project.rglob("*")
            if not path.is_relative_to(self.files) and not path.is_dir()
        }

    def test_a_trusted_root_serves_the_folder_over_https(self):
        # SSL_CERT_FILE stands in for the system trust store on Linux alone.
        if harness.current_platform() != harness.LINUX or shutil.which("openssl") is None:
            self.skipTest("needs Linux and openssl")
        case_dir = self.class_dir / "https"
        root = write_root(case_dir / "tls")
        port = harness.pick_port()
        log = case_dir / "start.log"
        with open(log, "wb") as handle:
            process = harness.popen(
                [str(self.engine), str(self.project), "--foreground", "--listen", f"127.0.0.1:{port}"],
                cwd=self.class_dir, env=trusted_environment(root.parent, root), stdout=handle,
                stderr=subprocess.STDOUT, start_new_session=True)
        self.addCleanup(harness.stop_process, process, harness.WAITS["stop"].seconds, f": inspect {log}")
        # The ready line follows the probe, which verifies https://127.0.0.1, an address with no SNI.
        harness.wait_for_line(log, 0, "drupack is ready", harness.WAITS["start"].seconds)
        context = ssl.create_default_context(cafile=str(root))
        with urlopen(f"https://127.0.0.1:{port}/user/login", context=context,
                     timeout=harness.WAITS["http_request"].seconds) as response:
            self.assertEqual(response.status, 200)
        self.assertIn(f"URL:     https://127.0.0.1:{port}\n", log.read_text(errors="replace"))

    def test_version_names_the_runtime_components_alone(self):
        lines = version_cases.version_lines(self.engine)[1:]
        self.assertEqual([line.split(" ")[0] for line in lines], list(version_cases.RUNTIME_COMPONENTS))
        for line in lines:
            self.assertRegex(line, rf" {version_cases.RELEASE}$")

    def marker(self, relative, content):
        """Writes a file into the served docroot for the length of the test, with the
        directories it has to create."""
        path = self.docroot / relative
        created = None
        ancestor = path.parent
        while not ancestor.exists():
            created, ancestor = ancestor, ancestor.parent
        path.parent.mkdir(parents=True, exist_ok=True)
        if isinstance(content, bytes):
            path.write_bytes(content)
        else:
            path.write_text(content)
        if created is None:
            self.addCleanup(path.unlink)
        else:
            self.addCleanup(shutil.rmtree, created)

    def test_the_htaccess_sources_are_the_ones_the_guards_reproduce(self):
        # The files guards.caddy reproduces by hand. The scaffold copy is core's own.
        sources = {
            self.docroot / "core/assets/scaffold/files/htaccess":
                "1422931a25b6b7cdccd344b29ff93dcc6e5d99640f5a5eba6160a3f2b3d721b5",
            self.project / "vendor/.htaccess":
                "4f62c1eb3b42589fccb318763f5012794152d667d159a930a90e5081b83fe1ef",
            self.docroot / "core/lib/Drupal/Component/FileSecurity/FileSecurity.php":
                "3459cec95df72c653f90db84f1f964f73bbe34f7edf29622960467c2aaf9a840",
        }
        for path, expected in sources.items():
            relative = path.relative_to(self.project)
            with self.subTest(source=str(relative)):
                self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), expected,
                                 f"{relative} changed: re-read application/guards.caddy against it, then move this hash")

    def test_the_guards_reproduce_the_htaccess_rules(self):
        # A body holding a marker means the file was served or the script ran.
        for relative in ("modules/probe/probe.php", "modules/probe/upper.PHP", "core/probe-core.php",
                         "probe-root.php", "sites/default/files/probe.php"):
            self.marker(relative, PHP_MARKER)
        for relative in ("vendor/notes.txt", "sites/default/files/config_probe/sync/plain.txt",
                         "package-lock.json", ".well-known/probe.txt", ".well-known/.hidden",
                         ".well-known-evil/x.txt", "probe.sqlite", "sites/default/files/.probe", "drupack.caddy"):
            self.marker(relative, TEXT_MARKER)
        self.marker("probe.svgz", gzip.compress(SVG_MARKER))
        self.marker("probe-proxy.php", '<?php echo "proxy=", var_export($_SERVER["HTTP_PROXY"] ?? null, true);')
        self.marker("css/css_probe.css", TEXT_MARKER)
        self.marker("css/css_probe.css.gz", gzip.compress(TEXT_MARKER.encode()))
        self.marker("probe-dir/index.html", TEXT_MARKER)
        self.marker("probe-both/index.html", TEXT_MARKER)
        self.marker("probe-both/index.php", PHP_MARKER)
        # Core's installer stands in for a marker, since the exemption names its path.
        installer = self.docroot / "core" / "install.php"
        original = installer.read_bytes()
        self.addCleanup(installer.write_bytes, original)
        installer.write_text('<?php echo "rewrite=", $_GET["rewrite"] ?? "unset", " x=", $_GET["x"] ?? "unset";')
        port, _ = self.start("htaccess-rules", str(self.project))
        refused = {
            "php below the root": (403, ["/modules/probe/probe.php", "/modules/probe/probe.php/x",
                                         "/modules/probe/PROBE.PHP", "/modules/probe/probe%2ephp",
                                         "/modules/probe/probe.php.", "/modules/probe/probe.php%20/x",
                                         "/core/lib/Drupal.php", "/autoload.php", "/core/probe-core.php/x"]),
            "site configuration": (403, ["/sites/default/settings.php", "/sites/default/settings.php/x",
                                         "/sites/default/SETTINGS.PHP", "/sites/default/default.services.yml",
                                         "/sites/default/settings.txt"]),
            "dotted segment": (403, ["/.well-known/.hidden", "/.well-known-evil/x.txt",
                                     "/sites/default/files/.probe"]),
            "protected name": (403, ["/package-lock.json", "/PACKAGE-LOCK.JSON", "/probe.sqlite",
                                     "/core/composer.json", "/clients/acme.inc", "/Tag", "/drupack.caddy"]),
            "protected directory": (403, ["/vendor/notes.txt", "/vendor/",
                                          "/sites/default/files/config_probe/sync/plain.txt"]),
            "public storage php": (404, ["/sites/default/files/probe.php", "/sites/default/files/probe.php/x",
                                         "/sites/default/files/PROBE.PHP"]),
            # A browser drops the tab from the third shape.
            "host-like path": (403, ["//evil.example/../probe-dir", "/%5Cevil.example/../probe-dir",
                                     "/%09/evil.example/../../probe-dir", "//probe-both"]),
        }
        for rule, (expected, paths) in refused.items():
            for path in paths:
                with self.subTest(rule=rule, path=path):
                    status, _, body = self.fetch(port, path)
                    self.assertEqual(status, expected, body[:200])
                    self.assertNotIn(PHP_MARKER_OUTPUT, body)
                    self.assertNotIn(TEXT_MARKER, body)
        for path, expected in (("/.well-known/probe.txt", TEXT_MARKER), ("/core/probe-core.php", PHP_MARKER_OUTPUT),
                               ("/probe-root.php", PHP_MARKER_OUTPUT)):
            with self.subTest(rule="allowed", path=path):
                status, _, body = self.fetch(port, path)
                self.assertEqual(status, 200, body[:200])
                self.assertIn(expected, body)
        # A refused name matches by name and case alone.
        for path in ("/tag", "/template", "/entries/2024", "/vendor/acme", "/private", "/clients/acme.inc/edit",
                     "/core/rebuild.php"):
            with self.subTest(rule="reaches drupal", path=path):
                self.assertNotEqual(self.fetch(port, path)[0], 403)
        with self.subTest(rule="missing favicon"):
            self.assertEqual(self.fetch(port, "/favicon.ico")[::2], (404, ""))
        # Once per response, a page's own header replaced.
        for path in ("/user/login", "/core/misc/drupal.js", "/autoload.php"):
            with self.subTest(rule="nosniff", path=path):
                _, headers, _ = self.fetch(port, path)
                self.assertEqual(headers.get_all("X-Content-Type-Options"), ["nosniff"])
        with self.subTest(rule="svgz"):
            status, headers, body = self.fetch(port, "/probe.svgz")
            self.assertEqual(status, 200)
            self.assertEqual(headers.get("Content-Type"), "image/svg+xml")
            self.assertEqual(headers.get("Content-Encoding"), "gzip")
            self.assertEqual(gzip.decompress(body.encode("latin-1")), SVG_MARKER)
            status, headers, _ = self.fetch(port, "/missing.svgz")
            self.assertEqual((status, headers.get("Content-Encoding")), (404, None))
        with self.subTest(rule="proxy header"):
            _, _, body = self.fetch(port, "/probe-proxy.php", {"Proxy": "http://evil.example"})
            self.assertEqual(body, "proxy=NULL")
        for path, location in (("/install.php?a=1", "/core/install.php?a=1"), ("/rebuild.php", "/core/rebuild.php")):
            with self.subTest(rule="installer redirect", path=path):
                status, headers, _ = self.fetch(port, path)
                self.assertEqual((status, headers.get("Location")), (301, location))
        with self.subTest(rule="installer below the root refused, not redirected"):
            self.assertEqual(self.fetch(port, "/modules/core/install.php")[0], 403)
        with self.subTest(rule="rewrite=ok"):
            self.assertEqual(self.fetch(port, "/core/install.php?x=1")[2], "rewrite=ok x=1")
        with self.subTest(rule="precompressed aggregate"):
            status, headers, body = self.fetch(port, "/css/css_probe.css", {"Accept-Encoding": "gzip"})
            self.assertEqual(status, 200)
            self.assertEqual(headers.get("Content-Encoding"), "gzip")
            self.assertIn("Accept-Encoding", headers.get("Vary", ""))
            self.assertEqual(gzip.decompress(body.encode("latin-1")).decode(), TEXT_MARKER)
            status, headers, body = self.fetch(port, "/css/css_probe.css")
            self.assertEqual((status, headers.get("Content-Encoding"), body), (200, None, TEXT_MARKER))
        with self.subTest(rule="html index"):
            status, headers, _ = self.fetch(port, "/probe-dir?q=1")
            self.assertEqual((status, headers.get("Location")), (301, "/probe-dir/?q=1"))
            self.assertEqual(self.fetch(port, "/probe-dir/")[2], TEXT_MARKER)
            self.assertEqual(self.fetch(port, "/probe-both/")[0], 403)
        # A missing file or a directory carries Drupal's header alone.
        for path, expected in (("/core/misc/drupal.js", "max-age=31536000,public,immutable"),
                               ("/robots.txt", "max-age=31536000"), ("/probe-dir/", "max-age=31536000"),
                               ("/css/css_probe.css", "max-age=31536000")):
            with self.subTest(rule="cache", path=path):
                self.assertEqual(self.fetch(port, path)[1].get_all("Cache-Control"), [expected])
        for path in ("/sites/default/files/absent.png", "/modules/probe/", "/user/login"):
            with self.subTest(rule="cache", path=path):
                values = self.fetch(port, path)[1].get_all("Cache-Control")
                self.assertEqual(len(values), 1, values)
                self.assertIn("no-cache", values[0])

    def test_a_project_snippet_runs_after_the_guards(self):
        # drupack.caddy at the project root is imported after the guards, so its respond on a
        # guarded path never answers.
        self.marker("modules/probe/probe.php", PHP_MARKER)
        self.marker("css/css_probe.css", TEXT_MARKER)
        snippet = self.project / "drupack.caddy"
        snippet.write_text('header X-Project-Rule applied\n'
                           'redir /old-path /new-path 301\n'
                           'respond /modules/probe/probe.php "unlocked" 200\n')
        self.addCleanup(snippet.unlink)
        port, _ = self.start("project-snippet", str(self.project))
        for path in ("/user/login", "/core/misc/drupal.js", "/css/css_probe.css"):
            with self.subTest(path=path):
                self.assertEqual(self.fetch(port, path)[1].get("X-Project-Rule"), "applied")
        status, headers, _ = self.fetch(port, "/old-path")
        self.assertEqual((status, headers.get("Location")), (301, "/new-path"))
        status, _, body = self.fetch(port, "/modules/probe/probe.php")
        self.assertEqual(status, 403)
        self.assertNotIn("unlocked", body)

    def test_a_broken_project_snippet_stops_the_start(self):
        snippet = self.project / "drupack.caddy"
        snippet.write_text("not_a_directive foo\n")
        self.addCleanup(snippet.unlink)
        result = self.engine_run("start", str(self.project), "--foreground", "--listen", f"127.0.0.1:{harness.pick_port()}")
        self.assertNotEqual(result.returncode, 0)
        output = result.stdout + result.stderr
        self.assertIn("unrecognized directive: not_a_directive", output)
        self.assertIn(snippet.resolve().as_posix(), output)

    def test_a_start_serves_the_folder_and_writes_only_its_files(self):
        before = self.snapshot()
        port, log = self.start("start-directory", str(self.project))
        self.assertEqual(self.status(port, "/"), 200)
        self.assertEqual(self.status(port, "/sites/default/settings.php"), 403)
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

    def test_clean_keeps_a_served_folders_entry_and_removes_it_once_stopped(self):
        # A cache of its own: the run's shared cache holds the site executable's entries too.
        cache = harness.reserved_dir(self.class_dir / "clean-cache")
        env = {**os.environ, "DRUPACK_CACHE_DIR": str(cache)}
        _, start = self.detached_start(str(self.project), env=env)
        self.assertEqual(start.returncode, 0, f"{start.stdout}{start.stderr}")
        served = self.engine_run("clean", env=env)
        self.assertEqual(served.returncode, 0, served.stderr)
        self.assertRegex(served.stdout, r"  folders/f[0-9a-f]{16}  \d+ MB  in use by a running site, kept\n")
        self.assertIn("Kept 1 folder entry a running site still uses.", served.stdout)

        stop = self.engine_run("stop", str(self.project), env=env)
        self.assertEqual(stop.returncode, 0, stop.stderr)
        cleaned = self.engine_run("clean", env=env)
        self.assertEqual(cleaned.returncode, 0, cleaned.stderr)
        self.assertIn("Removed 1 folder entry", cleaned.stdout)
        self.assertEqual([path for path in (cache / "folders").iterdir() if path.is_dir()], [],
                         f"clean left a folder entry: {cleaned.stdout}")

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

    def test_a_folder_is_served_on_its_drupack_site_name(self):
        named = self.class_dir / "My Shop"
        shutil.copytree(self.project, named, symlinks=True)
        self.with_subdomain(None, named)
        port, log = self.start("start-named", str(named), env=dict(os.environ, DRUPACK_DNS_ANSWER="127.0.0.1"))
        self.assertIn(f"URL:     http://my-shop.drupack.site:{port}\n", log.read_text(errors="replace"))
        request = Request(f"http://127.0.0.1:{port}/user/login", headers={"Host": f"my-shop.drupack.site:{port}"})
        with urlopen(request, timeout=harness.WAITS["http_request"].seconds) as response:
            self.assertEqual(response.status, 200)

    def with_subdomain(self, value, project=None):
        """Sets extra.drupack.subdomain in a project's composer.json until the case ends, or removes it
        for None. The project defaults to the demo's copy, whose composer.json sets one."""
        composer = (project or self.project) / "composer.json"
        original = composer.read_text()
        self.addCleanup(composer.write_text, original)
        content = json.loads(original)
        drupack = content.setdefault("extra", {}).setdefault("drupack", {})
        if value is None:
            drupack.pop("subdomain", None)
        else:
            drupack["subdomain"] = value
        composer.write_text(json.dumps(content, indent=4))

    def test_a_folder_with_a_subdomain_is_served_on_it(self):
        self.with_subdomain("shop")
        port, log = self.start("start-subdomain", str(self.project), env=dict(os.environ, DRUPACK_DNS_ANSWER="127.0.0.1"))
        self.assertIn(f"URL:     http://shop.drupack.site:{port}\n", log.read_text(errors="replace"))
        request = Request(f"http://127.0.0.1:{port}/user/login", headers={"Host": f"shop.drupack.site:{port}"})
        with urlopen(request, timeout=harness.WAITS["http_request"].seconds) as response:
            self.assertEqual(response.status, 200)

    def test_a_subdomain_that_is_not_one_dns_label_stops_the_start(self):
        self.with_subdomain("-bad")
        result = self.engine_run(str(self.project))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("extra.drupack.subdomain must be one DNS label", result.stderr)

    def test_a_folder_whose_name_does_not_resolve_is_served_on_its_bind_address(self):
        self.with_subdomain(None)
        port, log = self.start("start-unnamed", str(self.project))
        text = log.read_text(errors="replace")
        self.assertIn(f"URL:     http://127.0.0.1:{port}\n", text)
        self.assertIn("Name: project.drupack.site did not resolve to 127.0.0.1 (no answer), so the URL uses 127.0.0.1.", text)

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

    def test_a_web_root_outside_the_project_stops_the_start(self):
        composer = self.project / "composer.json"
        original = composer.read_text()
        self.addCleanup(composer.write_text, original)
        content = json.loads(original)
        content["extra"]["drupal-scaffold"]["locations"]["web-root"] = "/var/www/html"
        composer.write_text(json.dumps(content))
        result = self.engine_run(str(self.project))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("extra.drupal-scaffold.locations.web-root must name a directory inside the project, "
                      'not "/var/www/html"', result.stderr)

    def test_a_folder_without_composer_json_serves_its_web_directory(self):
        if harness.SITE["docroot"] != "web":
            self.skipTest("the site under test keeps its docroot outside web")
        composer = self.project / "composer.json"
        aside = self.class_dir / "composer.json.aside"
        composer.rename(aside)
        self.addCleanup(aside.rename, composer)
        port, log = self.start("start-no-composer", str(self.project))
        self.assertEqual(self.status(port, "/user/login"), 200, f"inspect {log}")

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

    def test_a_request_child_process_runs_on_the_runtime_php_with_no_php_on_path(self):
        version = self.engine_run("php", "-r", "echo PHP_VERSION;")
        self.assertEqual(version.returncode, 0, version.stderr)
        script = self.docroot / "engine-case-child.php"
        script.write_text("<?php passthru('php -r \"echo PHP_VERSION;\"');")
        self.addCleanup(script.unlink)
        empty = harness.fresh_dir(self.class_dir / "empty-server-path")
        port, log = self.start("request-child", str(self.project), env=dict(os.environ, PATH=str(empty)))
        with urlopen(f"http://127.0.0.1:{port}/engine-case-child.php",
                     timeout=harness.WAITS["http_request"].seconds) as response:
            self.assertEqual(response.read().decode(), version.stdout, f"inspect {log}")

    def uninstall(self):
        """Points the project's settings at a database no installer has run, until the case ends."""
        settings = self.docroot / "sites" / "default" / "settings.php"
        original = settings.read_text()
        self.addCleanup(settings.write_text, original)
        database = harness.fresh_dir(self.class_dir / f"uninstalled-{self._testMethodName}") / "site.sqlite"
        settings.write_text(SETTINGS.format(database=json.dumps(str(database))))

    def test_a_start_on_a_project_with_no_installed_site_prints_the_installer_address(self):
        self.uninstall()
        port, log = self.start("uninstalled", str(self.project))
        output = log.read_text()
        self.assertIn(f"  Install: http://127.0.0.1:{port}/core/install.php\n", output)
        self.assertNotIn("  Login:", output)
        self.assertNotIn("Cannot mint", output)

    def test_dr_runs_drupal_cores_command_line(self):
        result = self.engine_run("dr", "list", cwd=self.project)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("cache:rebuild", result.stdout)

    def test_drush_names_the_drush_a_project_lacks(self):
        result = self.engine_run("drush", "status", cwd=self.lacking / "web")
        self.assertNotEqual(result.returncode, 0)
        # serve.php prints paths in Drupack's canonical form, forward slashes on Windows too.
        self.assertIn(f"{self.lacking.as_posix()}/vendor/drush/drush/drush.php does not exist", result.stderr)

    def with_mercure(self, value=True):
        """Sets extra.drupack.mercure in the project's composer.json and adds the probe that
        publishes through mercure_publish(), until the case ends."""
        composer = self.project / "composer.json"
        original = composer.read_text()
        self.addCleanup(composer.write_text, original)
        content = json.loads(original)
        content.setdefault("extra", {}).setdefault("drupack", {})["mercure"] = value
        composer.write_text(json.dumps(content, indent=4))
        probe = self.docroot / "mercure-probe.php"
        probe.write_text("<?php echo mercure_publish('drupack/probe', (string) ($_GET['data'] ?? ''), true);")
        self.addCleanup(probe.unlink)

    def hub_entry(self, port):
        """The folder's cache entry holding the hub a start on port recorded."""
        root = self.engine_run("php", "-r", "echo getenv('DRUPACK_RUNTIME_CACHE_ROOT');", cwd=self.project).stdout
        hub = f"http://127.0.0.1:{port}{HUB}"
        entries = [path.parent for path in Path(root, "folders").glob("*/mercure_url") if path.read_text() == hub]
        self.assertEqual(len(entries), 1, f"entries recording {hub}: {entries}")
        return entries[0]

    def test_a_project_with_the_mercure_key_serves_the_hub_from_its_cache_entry(self):
        self.with_mercure()
        before = self.snapshot()
        port, log = self.start("mercure", str(self.project))
        entry = self.hub_entry(port)
        secrets = {name: (entry / f"mercure_{name}_secret").read_text() for name in ("publisher", "subscriber")}
        stream = Stream(port, token(secrets["subscriber"], port, "subscribe"))
        connection = http.client.HTTPConnection("127.0.0.1", port, timeout=harness.WAITS["http_request"].seconds)
        try:
            self.assertEqual(stream.response.status, 200, f"inspect {log}")
            stream.read_until(":")
            with urlopen(f"http://127.0.0.1:{port}/mercure-probe.php?data=live",
                         timeout=harness.WAITS["http_request"].seconds) as response:
                update = response.read().decode()
            stream.read_until(f"id: {update}\ndata: live\n")
            connection.request("POST", HUB, body=f"topic={TOPIC}&data=posted&private=on", headers={
                "Authorization": f"Bearer {token(secrets['publisher'], port, 'publish')}",
                "Content-Type": "application/x-www-form-urlencoded"})
            response = connection.getresponse()
            posted = response.read().decode()
            self.assertEqual(response.status, 200, posted)
            stream.read_until(f"id: {posted}\ndata: posted\n")
        finally:
            connection.close()
            stream.close()
        for name, secret in secrets.items():
            self.assertRegex(secret, r"^[0-9a-f]{64}$", name)
            if harness.current_platform() != harness.WINDOWS:
                self.assertEqual(stat.S_IMODE((entry / f"mercure_{name}_secret").stat().st_mode), 0o600, name)
        self.assertTrue((entry / "mercure.db").is_file())
        self.assertEqual(self.snapshot(), before, "the hub wrote into the project folder")

    def test_drush_sees_the_hub_the_last_start_served(self):
        self.with_mercure()
        port, _ = self.start("mercure-drush", str(self.project))
        entry = self.hub_entry(port)
        code = "echo json_encode(array_map('getenv', ['DRUPACK_MERCURE_URL', 'DRUPACK_MERCURE_PUBLISHER_SECRET', 'DRUPACK_MERCURE_SUBSCRIBER_SECRET']));"
        result = self.engine_run("drush", "php:eval", code, cwd=self.project)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), [
            f"http://127.0.0.1:{port}{HUB}",
            (entry / "mercure_publisher_secret").read_text(),
            (entry / "mercure_subscriber_secret").read_text(),
        ])

    def test_a_project_without_the_mercure_key_serves_no_hub(self):
        port, log = self.start("no-mercure", str(self.project))
        self.assertEqual(self.status(port, f"{HUB}?match={TOPIC}"), 404, f"inspect {log}")

    def test_a_mercure_key_that_is_not_a_boolean_stops_the_start(self):
        self.with_mercure("on")
        result = self.engine_run(str(self.project))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("extra.drupack.mercure must be true or false", result.stderr)

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

    def test_self_update_on_a_site_executable_names_its_publisher(self):
        result = harness.run([str(harness.BINARY), "self-update"], capture_output=True, text=True,
                             timeout=harness.WAITS["php_cli"].seconds)
        self.assertEqual(result.returncode, 1, result.stdout)
        self.assertIn(f"The new release of {harness.SITE['name']} comes from its publisher.", result.stderr)

    def test_self_update_on_a_composer_copy_names_the_composer_commands(self):
        # composer/drupack-install lays the executable beside the project's vendor/.
        project = self.class_dir / "composer-copy"
        (project / "vendor" / "drupal" / "drupack").mkdir(parents=True, exist_ok=True)
        copy = project / self.engine.name
        shutil.copyfile(self.engine, copy)
        copy.chmod(0o755)
        result = harness.run([str(copy), "self-update", "--check"], capture_output=True, text=True,
                             timeout=harness.WAITS["php_cli"].seconds)
        self.assertEqual(result.returncode, 1, result.stdout)
        self.assertIn("Run composer update drupal/drupack, then vendor/bin/drupack-install.", result.stderr)

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
