"""Proves the shipped php.ini and trust bundle reach a release start.

Drives the built executable through php-cli and asserts what a site owner could observe:
which ini loaded, the memory limit, a certificate path that parses, a working-directory
php.ini changing nothing, and a fetch that survives a broken host trust store.

RuntimeConfigurationServed asserts the same settings from inside a started site's
php-server hop, which php-cli probes would not catch if php.ini stopped reaching it.
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
    'zend_assertions' => ini_get('zend.assertions'),
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
    """Write source as probe.php in case_dir and run it through php-cli."""
    script = case_dir / "probe.php"
    script.write_text(source)
    return harness.run(
        [str(harness.BINARY), "php-cli", str(script)], cwd=case_dir,
        capture_output=True, text=True, timeout=harness.WAITS["php_cli"].seconds, env=env,
    )


class RuntimeConfiguration(harness.ConformanceCase):
    """The shipped php.ini and trust bundle, probed with no site started."""

    PLATFORMS = (harness.LINUX, harness.WINDOWS, harness.MACOS)
    RECIPE = False

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

    def test_assertions_are_off_as_in_drupal_htaccess(self):
        # PHP compiles zend.assertions on; Drupal's .htaccess turns it off for Apache.
        self.assertEqual(self.php_cli(INI_PROBE)["zend_assertions"], "0")

    def test_memory_limit_is_512m(self):
        values = self.php_cli(INI_PROBE)
        self.assertEqual(values["memory_limit"], "512M")

    def test_certificate_path_parses_over_100_certificates(self):
        values = self.php_cli(CERTIFICATE_PROBE)
        self.assertGreater(values["certificate_count"], 100, values)

    def test_working_directory_php_ini_changes_nothing(self):
        # A file beside where the executable runs must not reach the runtime PHPRC names.
        (self.case_dir / "php.ini").write_text("memory_limit = 999M\n")
        values = self.php_cli(INI_PROBE)
        self.assertEqual(values["memory_limit"], "512M")
        cache_root = os.environ["DRUPACK_CACHE_DIR"]
        self.assertTrue(values["loaded_ini"].startswith(cache_root))

    def test_release_history_reachable_with_a_broken_host_trust_store(self):
        if harness.running_offline():
            self.skipTest("no network inside the offline container")
        # Missing SSL_CERT_FILE and SSL_CERT_DIR reproduce a container with no host CA
        # package; the bundled cacert.pem must still verify the endpoint.
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

    Runs with the environment a release start gets, so a bundle truncated to one
    certificate is what turns it red.
    """

    PLATFORMS = (harness.LINUX, harness.WINDOWS, harness.MACOS)
    RECIPE = False

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

    PHPRC carries php.ini to the hop that answers requests. If it stopped reaching
    php-server, every php-cli probe would stay green while a live site ran at PHP's 128M
    default and a 2M upload cap. This starts a site, follows its one-time login link and
    reads phpinfo() at the status-report page.
    """

    PLATFORMS = (harness.LINUX, harness.WINDOWS)

    ADMIN_USER = "drupack-runtime-admin"
    ADMIN_PASSWORD = "Runtime.probe.administrator.2026!"

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        # Copied under its own name, as in SeededSite.
        cls.binary = cls.class_dir / f"drupack{harness.BINARY.suffix}"
        shutil.copyfile(harness.BINARY, cls.binary)
        cls.binary.chmod(0o700)

    def setUp(self):
        super().setUp()
        self.site = harness.Site(self.binary, self.case_dir)

    def tearDown(self):
        self.site.stop()

    def test_memory_limit_and_upload_limit_from_the_running_server(self):
        data = self.case_dir / "data"
        self.site.start(data, "--admin-user", self.ADMIN_USER, "--admin-password", self.ADMIN_PASSWORD)
        link = harness.wait_for_line(self.site.log_path, 0, LOGIN_PREFIX, harness.WAITS["start"].seconds)
        opener = build_opener(HTTPCookieProcessor(CookieJar()))
        # Spends the one-time link; the cookie jar keeps the administrator session.
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
        self.assertEqual(_phpinfo_local_value(page, "zend.assertions"), "0")
