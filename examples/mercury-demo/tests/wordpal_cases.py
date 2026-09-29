"""WordPal converts a WordPress theme on a fresh demo, and the site serves it across a restart.

The conversion runs on the demo's Bundled Node. It downloads the theme from wordpress.org
and WordPress Playground from npm.
"""

import re
import socket
import unittest

import harness
import node_cases

ADMIN_USER = "wordpal-admin"
ADMIN_PASSWORD = "Wordpal.conversion.test.2026"
THEME = "twentytwentyfour"

# No product deadline: the first conversion downloads Playground, WordPress and the theme.
CONVERT_SECONDS = 900

STYLESHEET = re.compile(r'<link rel="stylesheet"[^>]*href="([^"]+)"')


class WordPalConversion(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX,)
    WRITABLE = True

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        if node_cases.musl():
            raise unittest.SkipTest(node_cases.MUSL_SKIP)
        try:
            socket.create_connection(("wordpress.org", 443), timeout=10).close()
        except OSError:
            raise unittest.SkipTest("wordpress.org is unreachable")

    def setUp(self):
        super().setUp()
        self.data = self.case_dir / "data"
        self.site = harness.Site(harness.BINARY, self.case_dir / "site")

    def tearDown(self):
        self.site.stop()

    def serves_the_theme(self):
        status, _, page = self.site.fetch("/")
        self.assertEqual(200, status)
        stylesheets = [href for href in STYLESHEET.findall(page)
                       if f"/themes/custom/{THEME}/" in href or f"theme={THEME}" in href]
        self.assertTrue(stylesheets, f"/ links no stylesheet of the {THEME} theme")
        status, _, _ = self.site.fetch(stylesheets[0].replace("&amp;", "&"))
        self.assertEqual(200, status)

    def test_a_converted_theme_serves_across_a_restart(self):
        self.site.start(self.data, "--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD)
        self.site.stop()

        converted = harness.run([str(harness.BINARY), "drush", "--data-dir", str(self.data),
                                 "wordpal:convert", THEME, "--target=canvas"],
                                cwd=self.case_dir, capture_output=True, text=True, timeout=CONVERT_SECONDS)
        self.assertEqual(converted.returncode, 0, converted.stdout + converted.stderr)

        self.site.start(self.data)
        self.serves_the_theme()
        self.site.stop()
        self.site.start(self.data)
        self.serves_the_theme()
