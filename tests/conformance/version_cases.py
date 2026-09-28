"""--version names the site and its release, the engine release, and on Linux the C
library of the one runtime the executable carries, so a bug report says which build ran."""

import re

import harness


class Version(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)
    RECIPE = False

    def test_version_names_the_site_the_engine_and_the_linux_libc(self):
        result = harness.run([str(harness.BINARY), "--version"], capture_output=True, text=True,
                             timeout=harness.WAITS["unpack"].seconds)
        self.assertEqual(result.returncode, 0, result.stderr)
        libc = ", (glibc|musl)" if harness.current_platform() == harness.LINUX else ""
        self.assertRegex(result.stdout, re.compile(
            rf"^{re.escape(harness.SITE['name'])} \S+ \(drupack \S+{libc}\)$", re.MULTILINE))
