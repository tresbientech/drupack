"""--version names the site and its release, the engine release, and on Linux the C
library of the one runtime the executable carries, so a bug report says which build ran.
The lines after it name each component the file carries and its release."""

import re

import harness

# A release the build reads from the runtime itself, such as 8.5.10.
RELEASE = r"\d+(\.\d+)+"
RUNTIME_COMPONENTS = ("FrankenPHP", "PHP", "Caddy", "SQLite")


def carries_node():
    """A site asking for Node carries it on every file but a musl one."""
    return bool(harness.SITE.get("node")) and not harness.BINARY.stem.endswith("-musl")


def version_lines(executable):
    result = harness.run([str(executable), "--version"], capture_output=True, text=True,
                         timeout=harness.WAITS["unpack"].seconds)
    if result.returncode != 0:
        raise AssertionError(result.stderr)
    return result.stdout.splitlines()


class Version(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)
    RECIPE = False

    def test_version_names_the_site_the_engine_and_the_linux_libc(self):
        libc = ", (glibc|musl)" if harness.current_platform() == harness.LINUX else ""
        self.assertRegex(version_lines(harness.BINARY)[0],
                         rf"^{re.escape(harness.SITE['name'])} \S+ \(drupack \S+{libc}\)$")

    def test_version_names_each_component_the_site_carries(self):
        lines = version_lines(harness.BINARY)[1:]
        for line, name in zip(lines, RUNTIME_COMPONENTS):
            self.assertRegex(line, rf"^{name} {RELEASE}$")
        site = [f"Node {harness.SITE['node']}"] if carries_node() else []
        site += [f"Drupal {harness.SITE['drupal']}", f"Drush {harness.SITE['drush']}"]
        self.assertEqual(lines[len(RUNTIME_COMPONENTS):], site)

    def test_the_version_word_is_an_unknown_command(self):
        result = harness.run([str(harness.BINARY), "version"], capture_output=True, text=True,
                             timeout=harness.WAITS["unpack"].seconds)
        self.assertEqual(result.returncode, 1, result.stdout)
        self.assertIn("Unknown command: version", result.stderr)
