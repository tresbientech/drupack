"""Exercise a seeded embedded Drupal site without network access."""

import json
import shutil
import tempfile
import time
from http.cookiejar import CookieJar
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import HTTPCookieProcessor, Request, build_opener, urlopen

import harness

ADMIN_USER = "drupack-test-admin"
ADMIN_PASSWORD = "Offline.test.administrator.2026!"
CREDENTIALS = ("--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD)
LINK_PREFIX = "  Login:     "


def draw_png(uri, colour):
    """PHP for php:eval that writes a 64x64 PNG of one RGB colour at a stream URI."""
    return (f"$image = imagecreatetruecolor(64, 64); imagefill($image, 0, 0, imagecolorallocate($image, {colour}));"
            f" imagepng($image, \\Drupal::service('file_system')->realpath('{uri}'));")

# Windows runs three methods: a credentialed first start with the settings and
# private-path codes, drush status --field=bootstrap, and a credential-free restart.
# Every other method in this class stays Linux and macOS only.
WINDOWS_METHODS = frozenset({
    "test_protected_files",
    "test_startup_ignores_working_directory_script",
    "test_seeded_sqlite_site_and_drush",
    "test_smoke_paths_answer",
})


class SeededSite(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        # BINARY's own suffix carries the platform's naming rule: none on Linux and macOS,
        # ".exe" on Windows, where launching a copy named plainly "drupack" would look for
        # "drupack.exe" and never find it.
        cls.binary = cls.class_dir / f"drupack{harness.BINARY.suffix}"
        shutil.copyfile(harness.BINARY, cls.binary)
        cls.binary.chmod(0o700)

    def setUp(self):
        super().setUp()
        # A setUp skip, unlike a setUpClass skip, reports each test method on its own
        # line, so -v marks every site case skipped rather than the class once.
        # With no daemon the offline case cannot run, so these run on the host instead.
        if (harness.current_platform() == harness.LINUX and not harness.running_offline()
                and harness.docker_answers()):
            self.skipTest("Linux runs these cases through the offline case; see -k offline")
        if (harness.current_platform() == harness.WINDOWS
                and self._testMethodName not in WINDOWS_METHODS):
            self.skipTest("not marked for windows")
        self.site = harness.Site(self.binary, self.case_dir)

    def tearDown(self):
        self.site.stop()

    def run_drush(self, data, *command):
        return harness.run_drush(self.binary, self.case_dir, data, *command)

    def revalidate(self, path, etag=None):
        """Asks for path the way a browser holding the response tagged etag asks again."""
        request = Request(f"http://localhost:{self.site.port}{path}",
                          headers={} if etag is None else {"If-None-Match": etag})
        try:
            with urlopen(request, timeout=harness.WAITS["http_request"].seconds) as response:
                return response.status, response.headers, response.read()
        except HTTPError as error:
            return error.code, error.headers, b""

    def test_extensions(self):
        with tempfile.NamedTemporaryFile(mode="w", suffix=".php", dir=self.case_dir) as probe:
            probe.write("<?php echo json_encode([get_loaded_extensions(), PDO::getAvailableDrivers()]);")
            probe.flush()
            result = harness.run([str(self.binary), "php-cli", probe.name], cwd=self.case_dir,
                                 capture_output=True, text=True, timeout=harness.WAITS["php_cli"].seconds)
        self.assertEqual(result.returncode, 0, result.stderr)
        extensions, drivers = json.loads(result.stdout)
        loaded = {extension.lower() for extension in extensions}
        # These builds compile the extensions the allowlist names and nothing
        # else, so an absence and a surplus are both failures. The Windows PHP
        # carries a fixed set of its own, and its case asserts presence alone.
        self.assertEqual(
            sorted(harness.expected_extensions() | harness.ALWAYS_COMPILED), sorted(loaded),
        )
        self.assertTrue({"mysql", "pgsql", "sqlite"} <= set(drivers), drivers)

    def test_smoke_paths_answer(self):
        data = self.case_dir / "data"
        self.site.start(data, *CREDENTIALS)
        for path in harness.SITE["smoke_paths"]:
            try:
                status = self.site.fetch(path)[0]
            except HTTPError as error:
                status = error.code
            self.assertEqual(status, 200, f"the smoke path {path} answered {status}")

    def test_startup_ignores_working_directory_script(self):
        # exit(42) fails the readiness and bootstrap checks below if this script ever runs;
        # the marker line is the explicit check for the same fact.
        script = self.case_dir / "launch.php"
        script.write_text('<?php fwrite(STDOUT, "cwd launch executed\\n"); exit(42);')
        data = self.case_dir / "data"
        self.site.start(data, *CREDENTIALS)
        self.assertEqual(self.site.fetch("/user/login")[0], 200)
        bootstrap = self.run_drush(data, "status", "--field=bootstrap")
        self.assertEqual(bootstrap.returncode, 0, bootstrap.stderr)
        self.assertEqual(bootstrap.stdout.strip(), "Successful")
        output = self.site.log_path.read_text(errors="replace")
        self.assertNotIn("cwd launch executed", output)

    def test_seeded_sqlite_site_and_drush(self):
        data = self.case_dir / "data"
        self.site.start(data, *CREDENTIALS)
        # Latency guard: catches a cron-triggered stall without waiting out the full 240s limit.
        requested = time.monotonic()
        homepage = self.site.http("/")
        elapsed = time.monotonic() - requested
        self.assertLess(elapsed, 20, "the first full request waited on cron")
        self.assertNotIn("core/install.php", homepage)
        self.assertNotIn("Choose language", homepage)
        self.assertTrue((data / "site.sqlite").is_file())
        self.assertTrue((data / "settings.php").is_file())
        self.assertTrue((data / "files").is_dir())
        status = self.run_drush(data, "status", "--format=json")
        self.assertEqual(status.returncode, 0, status.stderr)
        self.assertIn("drupal-version", status.stdout)
        # Deterministic check: the Seed site never enables these modules, regardless of timing.
        enabled = self.run_drush(data, "pm:list", "--status=enabled", "--format=json")
        self.assertEqual(enabled.returncode, 0, enabled.stderr)
        modules = json.loads(enabled.stdout)
        self.assertNotIn("automatic_updates", modules)
        self.assertNotIn("package_manager", modules)
        self.site.stop()
        # An already-installed site serves again without credentials.
        self.site.start(data)
        self.assertNotIn("core/install.php", self.site.http("/"))

    def test_default_port_first_start_without_credentials(self):
        # No options and no terminal: the start installs a site on the default port and hands
        # its reader a way in. Following the printed link must log a browser in as admin, on
        # the dashboard.
        data = self.case_dir / "no-credentials"
        self.site.start(data, listen=False)
        origin = f"http://localhost:{self.site.port}"
        link = harness.wait_for_line(self.site.log_path, 0, LINK_PREFIX, harness.WAITS["start"].seconds)
        self.assertTrue(link.startswith(origin + "/user/reset/1/"), link)
        self.assertIn("?destination=/admin/dashboard", link)
        # A browser reaches the link only once the site has answered, so follow the
        # product's own order instead of racing the first cold request.
        harness.wait_for_line(self.site.log_path, 0, harness.ready_line(), harness.WAITS["start"].seconds)
        opener = build_opener(HTTPCookieProcessor(CookieJar()))
        with opener.open(link, timeout=harness.WAITS["http_request"].seconds) as response:
            landing = response.geturl()
        self.assertIn("/admin/dashboard", landing)
        # The same session that landed on the dashboard is signed in as admin: the account
        # page's title names the account viewing it.
        with opener.open(f"{origin}/user/1", timeout=harness.WAITS["http_request"].seconds) as response:
            body = response.read().decode(errors="replace")
        self.assertIn(f"admin | {harness.SITE['site_name']}", body)
        # The same page refuses an anonymous request, so the link supplied the session.
        with self.assertRaises(HTTPError) as error:
            self.site.http("/admin/dashboard")
        self.assertEqual(error.exception.code, 403)

    def test_protected_files(self):
        data = self.case_dir / "protected"
        self.site.start(data, *CREDENTIALS)
        # The settings and sites-scoped private paths answer one exact code. site.sqlite
        # and the top-level private alias keep the looser check, since they are not both
        # named Caddyfile matchers guaranteeing one specific code.
        checks = {
            "/site.sqlite": (403, 404),
            "/private/probe.txt": (403, 404),
            "/sites/default/settings.php": (404,),
            "/sites/default/private/": (403,),
        }
        for path, expected in checks.items():
            with self.subTest(path=path):
                with self.assertRaises(HTTPError) as error:
                    self.site.http(path)
                self.assertIn(error.exception.code, expected)

    def test_public_storage_blocks_php_execution(self):
        # Writable public storage must never execute PHP: FrankenPHP still
        # runs a script when a PATH_INFO suffix follows it, so the barrier
        # has to catch that case, an uppercase extension, and encoded paths,
        # not only a filename ending exactly in ".php". Win32 also strips
        # trailing dots and spaces from a path component, so a name ending
        # in one of those still opens the ".php" file underneath on the
        # Windows build. Fixtures are written by the test itself so no
        # executable probe ships in the repository.
        data = self.case_dir / "php-barrier"
        self.site.start(data, *CREDENTIALS)
        files = data / "files"
        (files / "probe.php").write_text("<?php echo 'executed';")
        (files / "PROBE.PHP").write_text("<?php echo 'executed';")
        (files / "ordinary.txt").write_text("not executable")
        blocked = [
            "/sites/default/files/probe.php",
            "/sites/default/files/probe.php/path-info",
            "/sites/default/files/PROBE.PHP",
            "/sites/default/files/probe%2ephp/path-info",
            "/sites/default/files/probe.php.",
            "/sites/default/files/probe.php%20",
            "/sites/default/files/probe.php%2fpath-info",
        ]
        for path in blocked:
            with self.subTest(path=path):
                try:
                    status, _, body = self.site.fetch(path)
                except HTTPError as error:
                    self.assertIn(error.code, [403, 404])
                else:
                    # A 200 here is a bug regardless of body: either the
                    # script executed, or the barrier missed it and
                    # file_server leaked the source instead. Show both
                    # so the two failure modes are not confused.
                    self.fail(f"expected {path} to be blocked, got {status}: {body!r}")
        self.assertEqual(self.site.http("/sites/default/files/ordinary.txt"), "not executable")

    def test_files_dir_outside_site_data(self):
        data = self.case_dir / "data"
        public = self.case_dir / "public"
        self.site.start(data, *CREDENTIALS, "--files-dir", str(public))
        (public / "probe.txt").write_text("served from the files directory")
        self.assertEqual(self.site.http("/sites/default/files/probe.txt"), "served from the files directory")
        self.assertFalse((data / "files").exists(), "Site data holds a files directory")
        self.site.stop()
        # The recorded directory holds for a start that names none.
        self.site.start(data)
        self.assertEqual(self.site.http("/sites/default/files/probe.txt"), "served from the files directory")

    def test_public_storage_cache_headers(self):
        # An upload and an image style derivative can change at the same URL, so
        # both revalidate rather than serve a year-old cached copy. A versioned
        # application asset's URL changes with its content, so it caches immutably.
        data = self.case_dir / "cache-headers"
        self.site.start(data, *CREDENTIALS)
        (data / "files" / "upload.png").write_bytes(b"not-a-real-png")
        _, headers, _ = self.site.fetch("/sites/default/files/upload.png")
        self.assertEqual(headers.get("Cache-Control"), "max-age=0,must-revalidate")
        _, headers, _ = self.site.fetch("/core/misc/drupal.js")
        self.assertEqual(headers.get("Cache-Control"), "max-age=31536000,public,immutable")
        # The runtime's GD reads PNG and no JPEG, so the case draws its own source and
        # takes the thumbnail's address, token included, from Drupal.
        source = "public://derivative-source.png"
        drawn = self.run_drush(data, "php:eval", draw_png(source, "255, 0, 0")
                               + f" echo \\Drupal\\image\\Entity\\ImageStyle::load('thumbnail')->buildUrl('{source}');")
        self.assertEqual(drawn.returncode, 0, drawn.stderr)
        address = urlsplit(drawn.stdout.strip())
        derivative = f"{address.path}?{address.query}"
        # The first request generates the derivative; Drupal serves that response
        # itself. The file exists on disk from the second request onward. Caddy's
        # own cache headers apply only to that second request.
        status, _, _ = self.site.fetch(derivative)
        self.assertEqual(status, 200)
        status, headers, previous = self.revalidate(derivative)
        self.assertEqual(status, 200)
        self.assertEqual(headers.get("Cache-Control"), "max-age=0,must-revalidate")
        etag = headers["ETag"]
        self.assertEqual(self.revalidate(derivative, etag)[0], 304)
        # The source is replaced at the same path and its derivatives flushed, as a file
        # update in Drupal does. The token in the URL stays the same, so only revalidation
        # hands the browser holding the old derivative the new one.
        replaced = self.run_drush(data, "php:eval", draw_png(source, "0, 0, 255") + f" image_path_flush('{source}');")
        self.assertEqual(replaced.returncode, 0, replaced.stderr)
        for request in ("generated", "served"):
            with self.subTest(request=request):
                status, headers, body = self.revalidate(derivative, etag)
                self.assertEqual(status, 200, "the browser kept the derivative of the replaced source")
                self.assertNotEqual(body, previous)
