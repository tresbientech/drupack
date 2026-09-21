"""An ASCII cache root on Windows: phase 1 found PHP startup resolves the
PHPRC-derived configuration path through the ANSI code page, so a cache root
it cannot represent breaks extension loading (see
docs/plans/ascii-cache-root.md, phase 1's ten probes, for the raw evidence).
runtime.Root() now resolves such a root to its ASCII short name before the
server ever sees it.

One case per script phase 1 measured, each pointing DRUPACK_CACHE_DIR at a
fresh directory named in that script: a Latin-1 accent (probe 1, HTTP 500,
12 of 13 DLL extensions failing), Cyrillic (probe 6, HTTP 500, no load
warning at all since php.ini itself was never located) and CJK (probe 7,
the same shape as Cyrillic). Windows-only: the fault and the fix are both
Windows-specific, and %LocalAppData% carries the account name only there.
"""

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

    def _assert_serves_from(self, script_name):
        cache = harness.reserved_dir(self.case_dir / "roots" / script_name / "cache")
        env = dict(os.environ, DRUPACK_CACHE_DIR=str(cache))
        data = self.case_dir / "data"
        self.site.start(data, *CREDENTIALS, env=env)
        status, _, _ = self.site.fetch("/user/login")
        self.assertEqual(200, status, f"the site did not answer 200: inspect {self.site.log_path}")
        log = self.site.log_path.read_text(errors="replace")
        self.assertNotIn(
            LOAD_FAILURE, log,
            f"an extension failed to load from a {script_name!r} cache root: inspect {self.site.log_path}",
        )

    def test_accented_latin_cache_root_serves_cleanly(self):
        self._assert_serves_from("tëst")

    def test_cyrillic_cache_root_serves_cleanly(self):
        self._assert_serves_from("Ольга")

    def test_cjk_cache_root_serves_cleanly(self):
        self._assert_serves_from("田中")
