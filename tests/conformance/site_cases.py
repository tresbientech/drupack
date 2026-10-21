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


def draw_image(uri, colour, writer="imagepng"):
    """PHP for php:eval that writes a 64x64 image of one RGB colour at a stream URI, through
    the GD writer function that picks its format."""
    return (f"$image = imagecreatetruecolor(64, 64); imagefill($image, 0, 0, imagecolorallocate($image, {colour}));"
            f" {writer}($image, \\Drupal::service('file_system')->realpath('{uri}'));")

# The only methods that run on Windows.
WINDOWS_METHODS = frozenset({
    "test_protected_files",
    "test_startup_ignores_working_directory_script",
    "test_seeded_sqlite_site_and_drush",
    "test_smoke_paths_answer",
    "test_image_styles_read_the_formats_readers_upload",
})


class SeededSite(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        # BINARY's suffix is ".exe" on Windows, where a copy named plainly "drupack" would
        # not be found.
        cls.binary = cls.class_dir / f"drupack{harness.BINARY.suffix}"
        shutil.copyfile(harness.BINARY, cls.binary)
        cls.binary.chmod(0o700)

    def setUp(self):
        super().setUp()
        # A setUp skip reports each method on its own line, where a setUpClass skip reports
        # the class once. With no daemon the offline case cannot run, so these run on the host.
        if (harness.current_platform() == harness.LINUX and not harness.running_offline()
                and harness.docker_answers()):
            self.skipTest("Linux runs these cases through the offline case; see -k offline")
        if (harness.current_platform() == harness.WINDOWS
                and self._testMethodName not in WINDOWS_METHODS):
            self.skipTest("not marked for windows")
        self.site = harness.Site(self.binary, self.case_dir)

    served_site = None

    def served(self):
        """Returns the Site data of the class's one started site, for cases that only read
        it or add files under names of their own. The first case that asks starts it."""
        cls = type(self)
        if cls.served_site is None:
            directory = cls.class_dir / "served"
            directory.mkdir(exist_ok=True)
            site = harness.Site(cls.binary, directory)
            try:
                site.start(directory / "data", *CREDENTIALS)
            except BaseException:
                site.stop()
                raise
            cls.served_site = site
        self.site = cls.served_site
        return cls.served_site.case_dir / "data"

    def tearDown(self):
        if self.site is not type(self).served_site:
            self.site.stop()

    @classmethod
    def tearDownClass(cls):
        if cls.served_site is not None:
            cls.served_site.stop()
        super().tearDownClass()

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
        # These builds compile the allowlist's extensions and nothing else: an absence and a
        # surplus both fail.
        self.assertEqual(
            sorted(harness.expected_extensions() | harness.ALWAYS_COMPILED), sorted(loaded),
        )
        self.assertTrue({"mysql", "pgsql", "sqlite"} <= set(drivers), drivers)

    def test_image_styles_read_the_formats_readers_upload(self):
        # Drupal's GD toolkit refuses a source format gd_info() does not report, so an
        # uploaded JPEG or WebP would get no derivative.
        with tempfile.NamedTemporaryFile(mode="w", suffix=".php", dir=self.case_dir) as probe:
            probe.write("<?php echo json_encode(gd_info());")
            probe.flush()
            result = harness.run([str(self.binary), "php-cli", probe.name], cwd=self.case_dir,
                                 capture_output=True, text=True, timeout=harness.WAITS["php_cli"].seconds)
        self.assertEqual(result.returncode, 0, result.stderr)
        info = json.loads(result.stdout)
        for support in ("PNG Support", "JPEG Support", "WebP Support", "AVIF Support", "FreeType Support"):
            with self.subTest(support=support):
                self.assertTrue(info[support], f"gd_info() reports no {support}")

        data = self.served()
        for extension, writer in (("jpg", "imagejpeg"), ("webp", "imagewebp")):
            with self.subTest(format=extension):
                source = f"public://gd-source.{extension}"
                drawn = self.run_drush(data, "php:eval", draw_image(source, "0, 128, 0", writer)
                                       + f" echo \\Drupal\\image\\Entity\\ImageStyle::load('thumbnail')->buildUrl('{source}');")
                self.assertEqual(drawn.returncode, 0, drawn.stderr)
                address = urlsplit(drawn.stdout.strip())
                # The style picks the derivative's format, AVIF in Drupal CMS's thumbnail.
                status, headers, _ = self.site.fetch(f"{address.path}?{address.query}")
                self.assertEqual(status, 200)
                self.assertTrue(headers.get("Content-Type", "").startswith("image/"), headers.get("Content-Type"))

    def test_smoke_paths_answer(self):
        self.served()
        for path in harness.SITE["smoke_paths"]:
            try:
                status = self.site.fetch(path)[0]
            except HTTPError as error:
                status = error.code
            self.assertEqual(status, 200, f"the smoke path {path} answered {status}")

    def test_startup_ignores_working_directory_script(self):
        # exit(42) fails the readiness and bootstrap checks below if this script ever runs.
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
        self.assertFalse((data / "runtime" / "Caddyfile").exists())
        status = self.run_drush(data, "status", "--format=json")
        self.assertEqual(status.returncode, 0, status.stderr)
        self.assertIn("drupal-version", status.stdout)
        enabled = self.run_drush(data, "pm:list", "--status=enabled", "--format=json")
        self.assertEqual(enabled.returncode, 0, enabled.stderr)
        modules = json.loads(enabled.stdout)
        self.assertNotIn("automatic_updates", modules)
        self.assertNotIn("package_manager", modules)
        self.site.stop()
        self.site.start(data)
        self.assertNotIn("core/install.php", self.site.http("/"))

    def test_default_port_first_start_without_credentials(self):
        # No options and no terminal: the start installs on the default port; the printed link
        # must log a browser in as admin, on the dashboard.
        data = self.case_dir / "no-credentials"
        self.site.start(data, listen=False)
        origin = f"http://localhost:{self.site.port}"
        link = harness.wait_for_line(self.site.log_path, 0, LINK_PREFIX, harness.WAITS["start"].seconds)
        self.assertTrue(link.startswith(origin + "/user/reset/1/"), link)
        self.assertIn("?destination=/admin/dashboard", link)
        # Wait for the ready line, as a browser would, instead of racing the first cold request.
        harness.wait_for_line(self.site.log_path, 0, harness.ready_line(), harness.WAITS["start"].seconds)
        opener = build_opener(HTTPCookieProcessor(CookieJar()))
        with opener.open(link, timeout=harness.WAITS["http_request"].seconds) as response:
            landing = response.geturl()
        self.assertIn("/admin/dashboard", landing)
        # The account page's title names the account viewing it.
        with opener.open(f"{origin}/user/1", timeout=harness.WAITS["http_request"].seconds) as response:
            body = response.read().decode(errors="replace")
        self.assertIn(f"admin | {harness.SITE['site_name']}", body)
        # An anonymous request is refused, so the link supplied the session.
        with self.assertRaises(HTTPError) as error:
            self.site.http("/admin/dashboard")
        self.assertEqual(error.exception.code, 403)

    def test_protected_files(self):
        self.served()
        # The settings and sites-scoped private paths answer one exact code; site.sqlite and
        # the top-level private alias are not both guaranteed one.
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
        # Writable public storage must never execute PHP. FrankenPHP still runs a script
        # followed by a PATH_INFO suffix, so the barrier must also catch that, an uppercase
        # extension and encoded paths. Win32 strips trailing dots and spaces from a path
        # component, so such a name still opens the ".php" file on Windows.
        data = self.served()
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
                    # A 200 means the script executed or file_server leaked its source;
                    # the body tells which.
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
        self.site.start(data)
        self.assertEqual(self.site.http("/sites/default/files/probe.txt"), "served from the files directory")

    def test_public_storage_cache_headers(self):
        # An upload and a derivative can change at the same URL, so both revalidate. A
        # versioned application asset's URL changes with its content, so it caches immutably.
        data = self.served()
        (data / "files" / "upload.png").write_bytes(b"not-a-real-png")
        _, headers, _ = self.site.fetch("/sites/default/files/upload.png")
        self.assertEqual(headers.get("Cache-Control"), "max-age=0,must-revalidate")
        _, headers, _ = self.site.fetch("/core/misc/drupal.js")
        self.assertEqual(headers.get("Cache-Control"), "max-age=31536000,public,immutable")
        source = "public://derivative-source.png"
        drawn = self.run_drush(data, "php:eval", draw_image(source, "255, 0, 0")
                               + f" echo \\Drupal\\image\\Entity\\ImageStyle::load('thumbnail')->buildUrl('{source}');")
        self.assertEqual(drawn.returncode, 0, drawn.stderr)
        address = urlsplit(drawn.stdout.strip())
        derivative = f"{address.path}?{address.query}"
        # Drupal serves the first request itself; Caddy's cache headers apply from the second,
        # once the file exists on disk.
        status, _, _ = self.site.fetch(derivative)
        self.assertEqual(status, 200)
        status, headers, previous = self.revalidate(derivative)
        self.assertEqual(status, 200)
        self.assertEqual(headers.get("Cache-Control"), "max-age=0,must-revalidate")
        etag = headers["ETag"]
        self.assertEqual(self.revalidate(derivative, etag)[0], 304)
        # Replacing the source and flushing its derivatives, as a Drupal file update does, keeps
        # the URL token, so only revalidation hands the browser the new derivative.
        replaced = self.run_drush(data, "php:eval", draw_image(source, "0, 0, 255") + f" image_path_flush('{source}');")
        self.assertEqual(replaced.returncode, 0, replaced.stderr)
        for request in ("generated", "served"):
            with self.subTest(request=request):
                status, headers, body = self.revalidate(derivative, etag)
                self.assertEqual(status, 200, "the browser kept the derivative of the replaced source")
                self.assertNotEqual(body, previous)
