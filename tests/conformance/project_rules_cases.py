"""A packaged site serves the drupack.caddy its build packed. build/variant-fixture.sh writes
the snippet; a build's own run skips the class on a site without one."""

import unittest
from urllib.error import HTTPError

import harness


class ProjectRules(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        if not (cls.site_application() / "drupack.caddy").is_file():
            raise unittest.SkipTest("the packaged site has no drupack.caddy")
        cls.site = harness.Site(harness.BINARY, cls.class_dir / "site")
        cls.site.start(cls.class_dir / "data")

    @classmethod
    def tearDownClass(cls):
        cls.site.stop()

    def test_the_packed_snippet_sets_its_header(self):
        for path in ("/user/login", "/core/misc/drupal.js"):
            with self.subTest(path=path):
                self.assertEqual(self.site.fetch(path)[1].get("X-Project-Rule"), "applied")

    def test_the_guards_answer_before_the_snippet(self):
        with self.assertRaises(HTTPError) as error:
            self.site.fetch("/sites/default/settings.php")
        self.assertEqual(error.exception.code, 403)
