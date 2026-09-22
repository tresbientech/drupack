"""An ASCII cache root on Windows: phase 1 found PHP startup resolves the
PHPRC-derived configuration path through the ANSI code page, so a cache root
it cannot represent breaks extension loading.
runtime.Root() now resolves such a root to its ASCII short name before the
server ever sees it.

One case per script phase 1 measured, each pointing DRUPACK_CACHE_DIR at a
fresh directory named in that script: a Latin-1 accent (probe 1, HTTP 500,
12 of 13 DLL extensions failing), Cyrillic (probe 6, HTTP 500, no load
warning at all since php.ini itself was never located) and CJK (probe 7,
the same shape as Cyrillic). A fourth case points both --data-dir and
DRUPACK_CACHE_DIR at a non-ASCII path in the same start, the shape a default
start on a non-ASCII Windows account actually takes. Windows-only: the fault
and the fix are both Windows-specific, and %LocalAppData% carries the account
name only there.

Cyrillic and CJK never print the load-failure warning even when broken, so
every case also checks that a php-cli probe against the same cache root loads
every extension runtime/php-extensions.txt names: the signal that separates
a fixed start from a broken one for those two scripts.
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

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)
        self.site = harness.Site(harness.BINARY, self.case_dir)

    def tearDown(self):
        self.site.stop()

    def _loaded_extensions(self, script_name, env):
        """The extensions a php-cli probe loads against env's cache root: the same
        Root()/Prepare() path a full start takes, so a broken asciiRoot rung shows
        up here exactly as it would for the running server.
        """
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
        # start() already polls /user/login to 200 before returning, so no separate
        # fetch-and-assert is needed here.
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
        # The PRD's default-start stories: a non-ASCII account name puts the data
        # directory under a non-ASCII path as well as the cache root. Every other
        # case here varies the cache root alone.
        data = self.case_dir / "tëst-data"
        self._assert_serves_from("tëst", data_dir=data)
