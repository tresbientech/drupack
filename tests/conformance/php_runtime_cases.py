"""Proves the shipped php.ini and trust bundle reach a release start.

Drives the built executable through its php-cli subcommand, the same probe
site_cases.py's own extensions check uses, and asserts what a site owner
could observe: which ini loaded, the active memory limit, a certificate path
that resolves and parses, a working-directory php.ini changing nothing, and a
fetch that survives a host trust store pointed at nothing.
"""

import json
import os

import harness

CERTIFICATE_MARKER = "-----BEGIN CERTIFICATE-----"
RELEASE_HISTORY_URL = "https://updates.drupal.org/release-history/drupal/current"

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
        script = self.case_dir / "probe.php"
        script.write_text(source)
        result = harness.run(
            [str(harness.BINARY), "php-cli", str(script)], cwd=self.case_dir,
            capture_output=True, text=True, timeout=harness.WAITS["php_cli"].seconds, env=env,
        )
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
