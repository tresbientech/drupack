"""Windows path handling: a started site's icon elements carry SVG content, and
its rendered addresses carry no reader-disk path. Every case here runs on all
three platforms; the defects these fixes cover are Windows-only, so a Linux or
macOS run exercises the same shipped code path as an identity operation.
"""

import re

import harness

ADMIN_USER = "windows-paths-admin"
ADMIN_PASSWORD = "Windows.paths.test.password.2026"
CREDENTIALS = ("--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD)

# The fixture pack's template wraps each icon in this span; a fault in reading the
# icon file leaves the svg inside it empty.
ICON_WRAPPER = re.compile(r'<span class="fixture-icon">(.*?)</span>', re.DOTALL)


class WindowsPathCases(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

    def setUp(self):
        super().setUp()
        self.site = harness.Site(harness.BINARY, self.case_dir)

    def tearDown(self):
        self.site.stop()

    def test_icons_carry_their_svg_content(self):
        data = self.case_dir / "data"
        self.site.start(data, *CREDENTIALS)
        page = self.site.http("/icons")
        wrappers = ICON_WRAPPER.findall(page)
        self.assertTrue(wrappers, "expected at least one icon wrapper on /icons")
        for wrapper in wrappers:
            self.assertIn("<path", wrapper, f"an icon wrapper carried no SVG content: {wrapper!r}")

    def test_addresses_carry_no_drive_letter(self):
        data = self.case_dir / "data"
        self.site.start(data, *CREDENTIALS)
        page = self.site.http("/icons")
        self.assertNotIn("%3A", page, "a rendered address carried an encoded drive letter")

    def test_addresses_carry_no_backslash(self):
        data = self.case_dir / "data"
        self.site.start(data, *CREDENTIALS)
        page = self.site.http("/icons")
        self.assertNotIn("%5C", page, "a rendered address carried an encoded backslash")
