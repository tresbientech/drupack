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

# A component's example image sits under its own components/<name>/assets/
# directory; the fault under test prefixes this address with the reader's
# disk path instead of leaving it root-relative.
COMPONENT_ASSET = re.compile(r'src="(/[^"]*components/[^"]*assets/[^"]+)"')


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

    def test_addresses_carry_no_drive_letter(self):
        data = self.case_dir / "data"
        self.site.start(data, *CREDENTIALS)
        page = self.site.http("/card-components")
        self.assertNotIn("%3A", page, "a rendered address carried an encoded drive letter")

    def test_addresses_carry_no_backslash(self):
        data = self.case_dir / "data"
        self.site.start(data, *CREDENTIALS)
        page = self.site.http("/card-components")
        self.assertNotIn("%5C", page, "a rendered address carried an encoded backslash")

    def test_component_asset_answers_with_a_success_status(self):
        data = self.case_dir / "data"
        self.site.start(data, *CREDENTIALS)
        page = self.site.http("/card-components")
        assets = COMPONENT_ASSET.findall(page)
        self.assertTrue(assets, "expected at least one component asset address on /card-components")
        status, _, _ = self.site.fetch(assets[0])
        self.assertEqual(200, status, f"component asset {assets[0]!r} did not answer with a success status")
