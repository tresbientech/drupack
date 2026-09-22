"""Crawls a fixed page set after a real install: every rendered href, src and inline
style must carry no drive letter, no backslash and no application-root prefix.

Both Drupal-side path defects found so far would have failed this crawl. The page set is
fixed: the front page, /card-components, /admin/dashboard, /admin/modules, and one node
carrying an uploaded image, so an image style derivative address appears. See
docs/adr/0010-one-canonical-path-form.md.
"""

import re
from http.cookiejar import CookieJar
from urllib.parse import unquote
from urllib.request import HTTPCookieProcessor, build_opener

import harness

ADMIN_USER = "path-crawl-admin"
ADMIN_PASSWORD = "Path.crawl.test.password.2026"
CREDENTIALS = ("--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD)
LINK_PREFIX = "  Login:     "

# The Mercury Demo recipe seeds exactly one node (an unpublished Privacy policy page
# with no image field set) but six image media entities, already uploaded by the
# recipe's own content import. The crawl's fifth page reuses one of those rather than
# uploading a file of its own.
IMAGE_NODE_ALIAS = "/phase-4-crawl-target"

# The recipe's own Canvas content template for node.page's "full" view mode wires up
# only the breadcrumb, title and body text; it never places field_featured_image, so a
# page node's own canonical route renders no image regardless of what the node carries.
# Deleting it drops the route back to the classic node.page.default display, which the
# recipe already wires to show field_featured_image — the same fallback Drupal takes
# for any view mode with no display of its own.
CANVAS_FULL_TEMPLATE = "canvas.content_template.node.page.full"

CREATE_IMAGE_NODE = (
    r"$mid = (int) reset(\Drupal::entityQuery('media')"
    r"->condition('bundle', 'image')->accessCheck(FALSE)->range(0, 1)->execute());"
    r"$node = \Drupal\node\Entity\Node::create(['type' => 'page',"
    r" 'title' => 'Phase 4 crawl target', 'moderation_state' => 'published',"
    r" 'field_featured_image' => ['target_id' => $mid],"
    rf" 'path' => ['alias' => '{IMAGE_NODE_ALIAS}']]);"
    r"$node->save(); print $mid;"
)
APP_ROOT_PHP = r"print \Drupal::root();"

HREF = re.compile(r'href="([^"]*)"')
SRC = re.compile(r'src="([^"]*)"')
STYLE = re.compile(r'style="([^"]*)"')
ATTRIBUTES = (("href", HREF), ("src", SRC), ("style", STYLE))
IMAGE_DERIVATIVE = re.compile(r'src="([^"]*/styles/[^"]*)"')

# A letter immediately followed by ':' immediately followed by a path separator, not
# itself preceded by another letter or digit: matches "C:/" or "C:\" wherever it starts,
# without matching an ordinary scheme like "http:" or "mailto:".
DRIVE_LETTER = re.compile(r'(?<![A-Za-z0-9])[A-Za-z]:[\\/]')


class PathCrawlCases(harness.ConformanceCase):
    # No macOS acceptance criterion: the RFC's crawl proves the Drupal-layer fix on the
    # two platforms that ever disagree on path form.
    PLATFORMS = (harness.LINUX, harness.WINDOWS)

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

    def _authenticated_opener(self):
        """Follow the start's one-time login link with a cookie jar, so the returned
        opener carries a session signed in as uid 1 for every request that follows.
        """
        link = harness.wait_for_line(self.site.log_path, 0, LINK_PREFIX, harness.WAITS["start"].seconds)
        opener = build_opener(HTTPCookieProcessor(CookieJar()))
        with opener.open(link, timeout=harness.WAITS["http_request"].seconds) as response:
            landing = response.geturl()
        self.assertIn("/admin/dashboard", landing, "the login link did not land on the dashboard")
        return opener

    def _get(self, opener, path):
        url = f"http://localhost:{self.site.port}{path}"
        with opener.open(url, timeout=harness.WAITS["http_request"].seconds) as response:
            return response.status, response.read().decode(errors="replace")

    # The RFC states one site start for this whole crawl, so every page, the image
    # node's own creation and the derivative fetch all share the one install below.
    def test_crawl_finds_no_reader_disk_path(self):
        data = self.case_dir / "data"
        self.site.start(data, *CREDENTIALS)

        dropped = harness.run_dr(harness.BINARY, self.case_dir, data, "config:delete",
                                  CANVAS_FULL_TEMPLATE, "--yes")
        self.assertEqual(dropped.returncode, 0, dropped.stderr)

        created = harness.run_dr(harness.BINARY, self.case_dir, data, "php:eval", CREATE_IMAGE_NODE)
        self.assertEqual(created.returncode, 0, created.stderr)
        self.assertNotEqual(created.stdout.strip(), "0",
                             "no seeded image media found to attach to the crawl's own node")

        root = harness.run_dr(harness.BINARY, self.case_dir, data, "php:eval", APP_ROOT_PHP)
        self.assertEqual(root.returncode, 0, root.stderr)
        app_root = root.stdout.strip()
        self.assertTrue(app_root, "Drupal::root() reported no application root")

        opener = self._authenticated_opener()
        pages = ("/", "/card-components", "/admin/dashboard", "/admin/modules", IMAGE_NODE_ALIAS)
        failures = []
        derivative = None
        for page in pages:
            status, body = self._get(opener, page)
            self.assertEqual(200, status, f"{page} did not answer with a success status")
            for attribute, pattern in ATTRIBUTES:
                for value in pattern.findall(body):
                    decoded = unquote(value)
                    if DRIVE_LETTER.search(decoded):
                        failures.append(f"{page} {attribute}={value!r} carries a drive letter")
                    if "\\" in decoded:
                        failures.append(f"{page} {attribute}={value!r} carries a backslash")
                    if app_root in decoded:
                        failures.append(f"{page} {attribute}={value!r} carries the application root prefix")
            if page == IMAGE_NODE_ALIAS:
                found = IMAGE_DERIVATIVE.findall(body)
                self.assertTrue(found, f"expected an image style derivative address on {page}")
                derivative = found[0]
        self.assertFalse(failures, "\n".join(failures))

        status, _ = self._get(opener, derivative)
        self.assertEqual(200, status, f"image derivative {derivative!r} did not answer with a success status")
