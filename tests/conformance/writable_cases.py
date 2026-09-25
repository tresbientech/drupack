"""A site whose contract names writable directories runs its own application in Site data.

The case writes a theme where a Drush command would, into a writable directory under the
docroot's themes, enables it with `dr` and fetches a page it renders.
"""

import os
import subprocess

import harness

ADMIN_USER = "writable-admin"
ADMIN_PASSWORD = "Writable.directory.test.2026"
MARKER = "drupack-writable-probe"
INFO = "name: Probe\ntype: theme\nbase theme: false\ncore_version_requirement: ^11\n"
PAGE = f'<div class="{MARKER}">{{{{ page.content }}}}</div>\n'


class WritableDirectories(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS)
    WRITABLE = True

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        themes = f"{harness.SITE['docroot']}/themes"
        cls.themes = next((directory for directory in harness.SITE["writable"]
                           if directory == themes or directory.startswith(themes + "/")), None)
        cls.case_dir = harness.RESULTS / cls.__name__
        cls.case_dir.mkdir(parents=True, exist_ok=True)
        # clean empties the cache this case names, and no other case reads it.
        cls.env = dict(os.environ, DRUPACK_CACHE_DIR=str(cls.case_dir / "cache"))

    def setUp(self):
        if self.themes is None:
            self.skipTest("the site lists no writable directory under its themes")
        self.data = self.case_dir / "data"
        self.site = harness.Site(harness.BINARY, self.case_dir / "site")

    def tearDown(self):
        self.site.stop()

    def dr(self, *command):
        return harness.run([str(harness.BINARY), "dr", "--data-dir", str(self.data), *command],
                           cwd=self.case_dir, env=self.env, capture_output=True, text=True,
                           timeout=harness.WAITS["dr"].seconds)

    def shared_application(self):
        probe = self.case_dir / "application.php"
        probe.write_text("<?php echo getenv('DRUPACK_RUNTIME_APP_DIR');")
        result = harness.run([str(harness.BINARY), "php-cli", str(probe)], env=self.env,
                             capture_output=True, text=True, timeout=harness.WAITS["php_cli"].seconds)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout

    def serves_the_probe(self):
        self.site.start(self.data, env=self.env)
        try:
            self.assertIn(MARKER, self.site.http("/"))
        finally:
            self.site.stop()

    def test_a_written_theme_serves(self):
        self.site.start(self.data, "--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD, env=self.env)
        self.site.stop()
        app = self.data / "app"
        for directory in harness.SITE["writable"]:
            self.assertTrue((app / directory).is_dir(), f"the first start laid no {directory}")

        theme = app / self.themes / "probe"
        (theme / "templates").mkdir(parents=True)
        (theme / "probe.info.yml").write_text(INFO)
        (theme / "templates" / "page.html.twig").write_text(PAGE)
        for command in (("theme:install", "probe"), ("config:set", "system.theme", "default", "probe", "--yes")):
            result = self.dr(*command)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.serves_the_probe()

        shared = self.shared_application()
        self.assertNotEqual(shared, str(app), "php-cli ran the site's own application")
        self.assertFalse(os.path.exists(os.path.join(shared, self.themes, "probe")),
                         "the write reached the release's shared application")

        cleaned = harness.run([str(harness.BINARY), "clean"], cwd=self.case_dir, env=self.env,
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=harness.WAITS["dr"].seconds)
        self.assertEqual(cleaned.returncode, 0)
        self.assertTrue((theme / "probe.info.yml").is_file(), "clean removed the site's application")

        # Another release laid this application: `dr` refuses, and a start lays this release
        # over it, keeping the theme the site wrote.
        release = app / ".release"
        current = release.read_text()
        release.write_text("an-earlier-release")
        refused = self.dr("status")
        self.assertNotEqual(refused.returncode, 0, "dr ran on another release's application")
        self.assertIn("Start", refused.stderr)
        self.serves_the_probe()
        self.assertEqual(release.read_text(), current)
        self.assertFalse((self.data / ".previous-app").exists(), "the upgrade left the old application")
