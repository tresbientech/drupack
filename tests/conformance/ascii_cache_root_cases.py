"""An ASCII cache root on Windows. PHP startup resolves the PHPRC-derived configuration
path through the ANSI code page, so a cache root it cannot represent breaks extension
loading; runtime.Root() resolves such a root to its ASCII short name.

One case per script, each pointing DRUPACK_CACHE_DIR at a fresh directory named in that
script: a Latin-1 accent (HTTP 500, 12 of 13 DLL extensions failing), Cyrillic and CJK
(HTTP 500, no load warning since php.ini was never located). A fourth case points both
--data-dir and DRUPACK_CACHE_DIR at a non-ASCII path, as a default start on a non-ASCII
account does. Windows only: %LocalAppData% carries the account name only there.

Cyrillic and CJK print no load-failure warning when broken, so every case also checks that
a php-cli probe against the same cache root loads every extension in
runtime/php-extensions.txt.
"""

import json
import os

import harness

ADMIN_USER = "ascii-cache-root-admin"
ADMIN_PASSWORD = "Ascii.cache.root.test.password.2026"
CREDENTIALS = ("--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD)

LOAD_FAILURE = "Unable to load dynamic library"


class AsciiCacheRootCases(harness.ConformanceCase):
    PLATFORMS = (harness.WINDOWS,)

    def setUp(self):
        super().setUp()
        self.site = harness.Site(harness.BINARY, self.case_dir)

    def tearDown(self):
        self.site.stop()

    def _loaded_extensions(self, script_name, env):
        """The extensions a php-cli probe loads against env's cache root, through the same
        Root()/Prepare() path a full start takes."""
        probe = self.case_dir / "roots" / script_name / "extensions.php"
        probe.parent.mkdir(parents=True, exist_ok=True)
        probe.write_text("<?php echo json_encode(get_loaded_extensions());")
        result = harness.run(
            [str(harness.BINARY), "php-cli", str(probe)], cwd=self.case_dir, env=env,
            capture_output=True, text=True, timeout=harness.WAITS["php_cli"].seconds,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        return {extension.lower() for extension in json.loads(result.stdout)}

    def _assert_serves_from(self, script_name, data_dir=None):
        cache = harness.reserved_dir(self.case_dir / "roots" / script_name / "cache")
        env = dict(os.environ, DRUPACK_CACHE_DIR=str(cache))
        data = data_dir if data_dir is not None else self.case_dir / "data"
        self.site.start(data, *CREDENTIALS, env=env)
        log = self.site.log_path.read_text(errors="replace")
        self.assertNotIn(
            LOAD_FAILURE, log,
            f"an extension failed to load from a {script_name!r} cache root: inspect {self.site.log_path}",
        )
        expected = harness.expected_extensions()
        missing = sorted(expected - self._loaded_extensions(script_name, env))
        self.assertEqual(
            [], missing,
            f"a {script_name!r} cache root did not load {missing}",
        )

    def test_accented_latin_cache_root_serves_cleanly(self):
        self._assert_serves_from("tëst")

    def test_cyrillic_cache_root_serves_cleanly(self):
        self._assert_serves_from("Ольга")

    def test_cjk_cache_root_serves_cleanly(self):
        self._assert_serves_from("田中")

    def test_accented_latin_data_and_cache_dir_serve_cleanly(self):
        # A non-ASCII account name puts the data directory under a non-ASCII path too; the
        # other cases vary the cache root alone.
        data = self.case_dir / "tëst-data"
        self._assert_serves_from("tëst", data_dir=data)
