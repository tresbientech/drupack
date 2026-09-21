"""Proves the shipped php.ini and trust bundle reach a release start.

Drives the built executable through its php-cli subcommand, the same probe
site_cases.py's own extensions check uses, and asserts what a site owner
could observe: which ini loaded, the active memory limit, a certificate path
that resolves and parses, a working-directory php.ini changing nothing, and a
fetch that survives a host trust store pointed at nothing.

RuntimeConfigurationServed asserts the same two settings a different way: from
inside a started site's own php-server hop, never through php-cli. Every case
above it drives php-cli, which would stay green even if php.ini stopped
reaching the hop that answers a site's requests.
"""

import json
import os
import re
import shutil
from http.cookiejar import CookieJar
from urllib.request import HTTPCookieProcessor, build_opener

import harness

CERTIFICATE_MARKER = "-----BEGIN CERTIFICATE-----"
RELEASE_HISTORY_URL = "https://updates.drupal.org/release-history/drupal/current"
LOGIN_PREFIX = "  Login:     "

INI_PROBE = """<?php
echo json_encode([
    'loaded_ini' => php_ini_loaded_file(),
    'memory_limit' => ini_get('memory_limit'),
    'cainfo' => ini_get('curl.cainfo'),
]);
"""

CERTIFICATE_PROBE = """<?php
$path = ini_get('curl.cainfo');
$contents = file_get_contents($path);
echo json_encode([
    'path' => $path,
    'certificate_count' => substr_count($contents, '""" + CERTIFICATE_MARKER + """'),
]);
"""

FETCH_PROBE = """<?php
$handle = curl_init('""" + RELEASE_HISTORY_URL + """');
curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
curl_setopt($handle, CURLOPT_NOBODY, true);
curl_setopt($handle, CURLOPT_TIMEOUT, 30);
curl_exec($handle);
echo json_encode([
    'http_status' => curl_getinfo($handle, CURLINFO_HTTP_CODE),
    'curl_errno' => curl_errno($handle),
]);
"""


def probe(case_dir, source, env=None):
    """Write source as probe.php in case_dir and run it through the built executable's
    php-cli subcommand. Returns the completed process, for the caller to assert and parse.
    """
    script = case_dir / "probe.php"
    script.write_text(source)
    return harness.run(
        [str(harness.BINARY), "php-cli", str(script)], cwd=case_dir,
        capture_output=True, text=True, timeout=harness.WAITS["php_cli"].seconds, env=env,
    )


class RuntimeConfiguration(harness.ConformanceCase):
    """The shipped php.ini and trust bundle, probed with no site started."""

    PLATFORMS = (harness.LINUX, harness.WINDOWS, harness.MACOS)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def php_cli(self, source, env=None):
        result = probe(self.case_dir, source, env=env)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_loaded_ini_sits_under_the_runtime_cache(self):
        values = self.php_cli(INI_PROBE)
        cache_root = os.environ["DRUPACK_CACHE_DIR"]
        self.assertTrue(
            values["loaded_ini"] and values["loaded_ini"].startswith(cache_root),
            f"loaded ini {values['loaded_ini']!r} is not under the runtime cache {cache_root!r}",
        )

    def test_memory_limit_is_512m(self):
        values = self.php_cli(INI_PROBE)
        self.assertEqual(values["memory_limit"], "512M")

    def test_certificate_path_parses_over_100_certificates(self):
        values = self.php_cli(CERTIFICATE_PROBE)
        self.assertGreater(values["certificate_count"], 100, values)

    def test_working_directory_php_ini_changes_nothing(self):
        # Same rule launch.php already follows in site_cases.py's own cwd case:
        # a file dropped beside where a reader happens to run the executable
        # from must not reach the runtime that PHPRC names.
        (self.case_dir / "php.ini").write_text("memory_limit = 999M\n")
        values = self.php_cli(INI_PROBE)
        self.assertEqual(values["memory_limit"], "512M")
        cache_root = os.environ["DRUPACK_CACHE_DIR"]
        self.assertTrue(values["loaded_ini"].startswith(cache_root))

    def test_release_history_reachable_with_a_broken_host_trust_store(self):
        if harness.running_offline():
            self.skipTest("no network inside the offline container")
        # A missing SSL_CERT_FILE and SSL_CERT_DIR reproduce a minimal container with
        # no host CA package installed, the failure user story 6 names; the bundled
        # cacert.pem must still verify the endpoint.
        env = dict(
            os.environ,
            SSL_CERT_FILE="/nonexistent/missing-ca-bundle.pem",
            SSL_CERT_DIR="/nonexistent/missing-ca-dir",
        )
        values = self.php_cli(FETCH_PROBE, env=env)
        self.assertEqual(values["curl_errno"], 0, values)
        self.assertEqual(values["http_status"], 200, values)


class RuntimeTrustOnline(harness.ConformanceCase):
    """Proves the shipped anchors verify a real endpoint, not only a file that parses.

    No hijacked host trust store here: RuntimeConfiguration's own fetch case already
    covers surviving one of those. This case runs with the environment a release start
    actually gets, so a bundle truncated to one certificate is the only thing that can
    turn it red.
    """

    PLATFORMS = (harness.LINUX, harness.WINDOWS, harness.MACOS)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def test_release_history_returns_200(self):
        if harness.running_offline():
            self.skipTest("no network inside the offline container")
        result = probe(self.case_dir, FETCH_PROBE)
        self.assertEqual(result.returncode, 0, result.stderr)
        values = json.loads(result.stdout)
        self.assertEqual(values["curl_errno"], 0, values)
        self.assertEqual(values["http_status"], 200, values)


def _phpinfo_local_value(page, directive):
    """Return directive's Local Value cell from a rendered phpinfo() table, or None."""
    match = re.search(
        rf'<tr><td class="e">{re.escape(directive)}</td><td class="v">([^<]*)</td>', page,
    )
    return match.group(1) if match else None


class RuntimeConfigurationServed(harness.ConformanceCase):
    """The shipped php.ini, read from inside a started site's php-server hop.

    Every case in RuntimeConfiguration drives php-cli, a different hop from the one that
    answers a site's requests. PHPRC is what carries php.ini to that second hop. If it
    stopped reaching php-server, every php-cli probe here would stay green while a live
    site ran at PHP's 128M default and a 2M upload cap. This starts a real site, follows its own
    printed one-time login link, and reads phpinfo() the way an administrator would, at
    Drupal's own status-report page. Closes phase 6's "a 64M upload succeeds on a site
    started from a release build" criterion.
    """

    PLATFORMS = (harness.LINUX, harness.WINDOWS)

    ADMIN_USER = "drupack-runtime-admin"
    ADMIN_PASSWORD = "Runtime.probe.administrator.2026!"

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)
        # Same reason SeededSite copies the binary under its own name: BINARY's suffix
        # carries the platform's naming rule, none on Linux, ".exe" on Windows.
        cls.binary = cls.class_dir / f"drupack{harness.BINARY.suffix}"
        shutil.copyfile(harness.BINARY, cls.binary)
        cls.binary.chmod(0o700)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)
        self.site = harness.Site(self.binary, self.case_dir)

    def tearDown(self):
        self.site.stop()

    def test_memory_limit_and_upload_limit_from_the_running_server(self):
        data = self.case_dir / "data"
        self.site.start(data, "--admin-user", self.ADMIN_USER, "--admin-password", self.ADMIN_PASSWORD)
        link = harness.wait_for_line(self.site.log_path, 0, LOGIN_PREFIX, harness.WAITS["start"].seconds)
        opener = build_opener(HTTPCookieProcessor(CookieJar()))
        # Spends the one-time link and signs the opener's cookie jar in as the
        # administrator; the phpinfo page below reuses that same session.
        with opener.open(link, timeout=harness.WAITS["http_request"].seconds):
            pass
        with opener.open(
            f"http://localhost:{self.site.port}/admin/reports/status/php",
            timeout=harness.WAITS["http_request"].seconds,
        ) as response:
            page = response.read().decode(errors="replace")
        self.assertEqual(
            _phpinfo_local_value(page, "memory_limit"), "512M",
            "phase 6: 512M memory limit, read from the running php-server hop",
        )
        self.assertEqual(
            _phpinfo_local_value(page, "upload_max_filesize"), "64M",
            "phase 6: a 64M upload succeeds on a site started from a release build",
        )
