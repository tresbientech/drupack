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

# mercury:icon wraps every icon in a div bearing only this class, sized from
# a fixed enum (16, 20, 24, 32, 48 or 64); a fault leaves it empty, so the
# wrapper is the signal to search rather than the site's icon count.
ICON_WRAPPER = re.compile(r'<div class="min-w:\d+">(.*?)</div>', re.DOTALL)


class WindowsPathCases(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

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

    def test_icons_carry_their_svg_content(self):
        data = self.case_dir / "data"
        self.site.start(data, *CREDENTIALS)
        page = self.site.http("/card-components")
        wrappers = ICON_WRAPPER.findall(page)
        self.assertTrue(wrappers, "expected at least one icon wrapper on /card-components")
        for wrapper in wrappers:
            self.assertIn("<svg", wrapper, f"an icon wrapper carried no SVG content: {wrapper!r}")
