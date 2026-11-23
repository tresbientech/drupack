"""Crawls a fixed page set after a real install: every rendered href, src and inline
style must carry no drive letter, no backslash and no application-root prefix.

The page set: the front page, which lists the image article's teaser with an image style
derivative, /icons, /admin/dashboard, /admin/modules and the image article itself.
See docs/adr/0004-paths-and-shared-folders.md.
"""

import re
from http.cookiejar import CookieJar
from urllib.parse import unquote
from urllib.request import HTTPCookieProcessor, build_opener

import harness

ADMIN_USER = "path-crawl-admin"
ADMIN_PASSWORD = "Path.crawl.test.password.2026"
CREDENTIALS = ("--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD)

# The recipe's content imports one promoted article with an uploaded image.
IMAGE_NODE_ALIAS = "/fixture-image"
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
    # Linux and Windows are the two platforms that ever disagree on path form.
    PLATFORMS = (harness.LINUX, harness.WINDOWS)

    def setUp(self):
        super().setUp()
        self.site = harness.Site(harness.BINARY, self.case_dir)

    def tearDown(self):
        self.site.stop()

    def _authenticated_opener(self, data):
        """Follow a one-time login link with a cookie jar, so the returned opener
        carries a session signed in as uid 1 for every request that follows. A start
        nobody watches prints no link, so Drush mints this one.
        """
        minted = harness.run_drush(harness.BINARY, self.case_dir, data, "user:login", "--no-browser",
                                   "/admin/dashboard")
        self.assertEqual(minted.returncode, 0, minted.stderr)
        link = minted.stdout.strip()
        opener = build_opener(HTTPCookieProcessor(CookieJar()))
        with opener.open(link, timeout=harness.WAITS["http_request"].seconds) as response:
            landing = response.geturl()
        self.assertIn("/admin/dashboard", landing, "the login link did not land on the dashboard")
        return opener

    def _get(self, opener, path):
        url = f"http://localhost:{self.site.port}{path}"
        with opener.open(url, timeout=harness.WAITS["http_request"].seconds) as response:
            return response.status, response.read().decode(errors="replace")

    # One site start serves every page and the derivative fetch.
    def test_crawl_finds_no_reader_disk_path(self):
        data = self.case_dir / "data"
        self.site.start(data, *CREDENTIALS)

        root = harness.run_drush(harness.BINARY, self.case_dir, data, "php:eval", APP_ROOT_PHP)
        self.assertEqual(root.returncode, 0, root.stderr)
        app_root = root.stdout.strip()
        self.assertTrue(app_root, "Drupal::root() reported no application root")

        opener = self._authenticated_opener(data)
        pages = ("/", "/icons", "/admin/dashboard", "/admin/modules", IMAGE_NODE_ALIAS)
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
            if page == "/":
                found = IMAGE_DERIVATIVE.findall(body)
                self.assertTrue(found, "expected an image style derivative address on the front page")
                derivative = found[0]
        self.assertFalse(failures, "\n".join(failures))

        status, _ = self._get(opener, derivative)
        self.assertEqual(200, status, f"image derivative {derivative!r} did not answer with a success status")
