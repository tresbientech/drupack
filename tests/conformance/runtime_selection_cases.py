"""The runtime a Linux executable picks, and the variable that overrides it.

A Linux build carries one runtime per C library and stats the ELF interpreter each one
records to decide which this host runs. macOS and Windows carry one runtime, which the
launcher runs without reading anything, so these cases are Linux only.

The class keeps its own cache root: forcing the other runtime activates a second cache
entry, and activation removes the one the shared root holds for every other case.
"""

import os
import platform
import re
from pathlib import Path

import harness

# The line entrypoint.go prints for a build carrying a runtime per libc.
VERSION_PATTERN = re.compile(r"^drupack \S+ \(drupack \S+, (glibc|musl)\)$", re.MULTILINE)

# The interpreter a glibc build records, per architecture. A host holding the file runs
# the glibc runtime; a host without it falls to the musl one, which needs no loader.
GLIBC_INTERPRETERS = {
    "x86_64": Path("/lib64/ld-linux-x86-64.so.2"),
    "aarch64": Path("/lib/ld-linux-aarch64.so.1"),
}


class RuntimeSelection(harness.ConformanceCase):
    """Which runtime a start runs, and what --version reports about it."""

    PLATFORMS = (harness.LINUX,)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)
        cls.cache = harness.reserved_dir(cls.class_dir / "cache")

    def version(self, libc=None):
        """Run --version, returning the completed process. libc names DRUPACK_LIBC."""
        env = dict(os.environ, DRUPACK_CACHE_DIR=str(self.cache))
        if libc is not None:
            env["DRUPACK_LIBC"] = libc
        return harness.run(
            [str(harness.BINARY), "--version"],
            capture_output=True, text=True, env=env,
            timeout=harness.WAITS["unpack"].seconds,
        )

    def test_version_names_the_runtime_that_ran(self):
        result = self.version()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertRegex(result.stdout, VERSION_PATTERN)

    def test_the_host_loader_decides_which_runtime_runs(self):
        interpreter = GLIBC_INTERPRETERS.get(platform.machine())
        if interpreter is None:
            self.skipTest(f"no recorded glibc interpreter for {platform.machine()}")
        expected = "glibc" if interpreter.exists() else "musl"
        result = self.version()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(f", {expected})", result.stdout)

    def test_the_variable_selects_each_carried_runtime(self):
        for libc in ("musl", "glibc"):
            with self.subTest(libc=libc):
                result = self.version(libc)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn(f", {libc})", result.stdout)

    def test_an_unknown_libc_stops_the_start(self):
        result = self.version("gnu")
        self.assertNotEqual(result.returncode, 0, "an unknown DRUPACK_LIBC value started")
        # The refusal names what the build carries, so a reader can correct the value.
        for carried in ("glibc", "musl"):
            self.assertIn(carried, result.stderr)

    def test_forcing_a_runtime_leaves_one_entry_in_the_cache(self):
        for libc in ("glibc", "musl"):
            self.version(libc)
        entries = [path.name for path in self.cache.iterdir() if path.is_dir() and path.name != "app"]
        self.assertEqual(len(entries), 1, f"the cache holds {entries}")

    def test_a_forced_runtime_serves_the_site(self):
        """The runtime the variable names installs a site and answers a request."""
        case_dir = self.class_dir / "forced-musl"
        data = harness.fresh_dir(case_dir / "data")
        site = harness.Site(harness.BINARY, case_dir)
        site.start(data, env=dict(os.environ,
                                  DRUPACK_CACHE_DIR=str(self.cache),
                                  DRUPACK_LIBC="musl"))
        try:
            self.assertNotIn("core/install.php", site.http("/user/login"))
        finally:
            site.stop()
