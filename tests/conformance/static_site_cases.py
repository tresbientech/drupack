"""The static port a site's contract turns on: before any export it answers 404, a page written
into the static directory answers on its path and its index with no redirect, a miss answers
the export's own 404 page, the Listener record keeps the static address and `drush` sees the
directory, a start without --static-listen takes the port after the site's, the drupack TLS
source covers the static port, and a site without the key serves no second port. `export`
runs Tome at the static URL and the Drush entry reruns itself on the runtime's php.
"""

import http.client
import json
import os
import socket
import ssl
import unittest
from pathlib import Path

import harness
from tls_cases import trusted_environment, write_root


def request(port, path, context=None):
    """The status, headers and body of one request to localhost, a redirect unfollowed."""
    timeout = harness.WAITS["http_request"].seconds
    if context is None:
        connection = http.client.HTTPConnection("localhost", port, timeout=timeout)
    else:
        connection = http.client.HTTPSConnection("localhost", port, timeout=timeout, context=context)
    try:
        connection.request("GET", path)
        response = connection.getresponse()
        return response.status, response.headers, response.read().decode(errors="replace")
    finally:
        connection.close()


def refuses(port):
    """Whether nothing accepts a connection on the loopback port."""
    with socket.socket() as probe:
        probe.settimeout(1)
        return probe.connect_ex(("127.0.0.1", port)) != 0


class StaticSite(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

    @classmethod
    def setUpClass(cls):
        if harness.SITE.get("static") is None:
            raise unittest.SkipTest("the site's contract has no static export")
        cls.data = cls.class_dir / "data"
        cls.static_port = harness.pick_port()
        cls.site = harness.Site(harness.BINARY, cls.class_dir / "site")
        cls.site.start(cls.data, "--static-listen", f"127.0.0.1:{cls.static_port}")

    @classmethod
    def tearDownClass(cls):
        cls.site.stop()

    def write(self, path, body):
        page = self.data / "static" / path
        page.parent.mkdir(parents=True, exist_ok=True)
        page.write_text(body)

    def test_the_static_port_answers_404_before_an_export(self):
        self.assertEqual(request(self.static_port, "/")[0], 404)

    def test_a_page_answers_on_its_path_and_its_index_with_no_redirect(self):
        self.write("hello/index.html", "<p>hello</p>")
        for path in ("/hello", "/hello/", "/hello/index.html"):
            with self.subTest(path):
                status, _, body = request(self.static_port, path)
                self.assertEqual((status, body), (200, "<p>hello</p>"))

    def test_the_exports_404_page_is_the_body_of_a_miss(self):
        self.write("404/index.html", "<p>lost</p>")
        status, _, body = request(self.static_port, "/missing")
        self.assertEqual((status, body), (404, "<p>lost</p>"))

    def test_the_listener_record_keeps_the_static_address(self):
        record = json.loads((self.data / "listener").read_text())
        self.assertEqual(record["static-listen"], f"127.0.0.1:{self.static_port}")

    def test_a_dynamic_path_reaches_drupal_ahead_of_the_export(self):
        self.write("search/node/index.html", "exported copy")
        status, headers, body = request(self.static_port, "/search/node?keys=drupal")
        self.assertEqual(status, 200, body[:300])
        self.assertTrue(headers.get("X-Generator", "").startswith("Drupal"), dict(headers))
        self.assertNotIn("exported copy", body)

    def test_a_path_outside_the_dynamic_list_answers_the_export(self):
        self.assertEqual(request(self.static_port, "/user/login")[0], 404)

    def test_a_php_file_under_a_dynamic_path_meets_the_guards(self):
        self.assertEqual(request(self.static_port, "/search/x.php")[0], 403)

    def test_drush_sees_the_static_directory(self):
        code = "echo getenv('DRUPACK_RUNTIME_STATIC_ROOT');"
        result = harness.run([str(harness.BINARY), "drush", "--data-dir", str(self.data), "php:eval", code],
                             cwd=self.class_dir, capture_output=True, text=True, timeout=harness.WAITS["drush"].seconds)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(Path(result.stdout.strip()), self.data / "static")

    def test_a_start_without_the_option_serves_the_static_site_on_the_next_port(self):
        port = harness.pick_port_pair()
        site = harness.Site(harness.BINARY, self.case_dir / "site")
        site.start(self.case_dir / "data", port=port)
        self.addCleanup(site.stop)
        self.assertEqual(request(port + 1, "/")[0], 404)
        self.assertIn(f"Static:    http://localhost:{port + 1}/", site.log_path.read_text(errors="replace"))

    def test_a_taken_static_port_refuses_and_names_the_option(self):
        with socket.socket() as holder:
            holder.bind(("127.0.0.1", 0))
            holder.listen()
            taken = holder.getsockname()[1]
            output = harness.refuse(self, self.case_dir, "taken", "--data-dir", str(self.case_dir / "data"),
                                    "--static-listen", f"127.0.0.1:{taken}")
        self.assertIn(f"Another program is listening on 127.0.0.1:{taken}", output)
        self.assertIn("--static-listen", output)

    def test_a_static_address_that_is_not_ip_port_refuses(self):
        output = harness.refuse(self, self.case_dir, "invalid", "--data-dir", str(self.case_dir / "data"),
                                "--static-listen", "nowhere")
        self.assertIn("--static-listen requires IP:PORT", output)

    def test_the_sites_own_port_refuses_as_the_static_port(self):
        port = harness.pick_port()
        result = harness.run([str(harness.BINARY), "--foreground", "--data-dir", str(self.case_dir / "data"),
                              "--listen", f"127.0.0.1:{port}", "--static-listen", f"127.0.0.1:{port}"],
                             cwd=self.case_dir, capture_output=True, text=True, timeout=harness.WAITS["bootstrap_refusal"].seconds)
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn(f"--static-listen must name a port other than the site's {port}", result.stdout + result.stderr)


class StaticSiteOff(harness.ConformanceCase):
    """A site whose contract has no static export, the no-node build of build/qa.sh."""

    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

    @classmethod
    def setUpClass(cls):
        if harness.SITE.get("static") is not None:
            raise unittest.SkipTest("the site's contract has a static export")
        cls.data = cls.class_dir / "data"
        cls.port = harness.pick_port_pair()
        cls.site = harness.Site(harness.BINARY, cls.class_dir / "site")
        cls.site.start(cls.data, port=cls.port)

    @classmethod
    def tearDownClass(cls):
        cls.site.stop()

    def test_the_next_port_refuses_connections(self):
        self.assertTrue(refuses(self.port + 1))

    def test_site_data_holds_no_static_directory(self):
        self.assertFalse((self.data / "static").exists())

    def test_the_option_refuses_and_names_the_key(self):
        output = harness.refuse(self, self.case_dir, "option", "--data-dir", str(self.case_dir / "data"),
                                "--static-listen", "127.0.0.1:1")
        self.assertIn("--static-listen needs a site whose composer.json sets extra.drupack.static", output)

    def test_export_refuses_and_names_the_key(self):
        result = harness.run([str(harness.BINARY), "export", "--data-dir", str(self.data)], cwd=self.case_dir,
                             capture_output=True, text=True, timeout=harness.WAITS["drush"].seconds)
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("export needs a site whose composer.json sets extra.drupack.static", result.stdout + result.stderr)


class StaticSiteTls(harness.ConformanceCase):
    """The static port under the drupack TLS source, as tls_cases.TlsSources starts a site."""

    PLATFORMS = (harness.LINUX,)
    TOOLS = ("openssl",)

    @classmethod
    def setUpClass(cls):
        if harness.SITE.get("static") is None:
            raise unittest.SkipTest("the site's contract has no static export")
        cls.data = cls.class_dir / "data"
        root = write_root(cls.class_dir / "tls")
        bundle = cls.class_dir / "system.pem"
        bundle.write_text(root.read_text())
        cls.static_port = harness.pick_port()
        cls.site = harness.Site(harness.BINARY, cls.class_dir / "site", root=root)
        cls.site.start(cls.data, "--static-listen", f"127.0.0.1:{cls.static_port}",
                       env=trusted_environment(cls.class_dir / "tls", bundle))
        cls.context = ssl.create_default_context(cafile=str(root))

    @classmethod
    def tearDownClass(cls):
        cls.site.stop()

    def test_the_static_port_serves_https_from_the_sites_root(self):
        self.assertEqual(request(self.static_port, "/", self.context)[0], 404)
        self.assertRegex(self.site.log_path.read_text(errors="replace"), rf"Static:    https://[^/]+:{self.static_port}/")


class StaticSiteExport(harness.ConformanceCase):
    """`export` on Site data of its own: Tome writes the front page and the static port serves it,
    a --uri the reader names wins, empty Site data refuses, and Drush's children run on the
    runtime's php with no php on PATH.
    """

    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

    @classmethod
    def setUpClass(cls):
        if harness.SITE.get("static") is None:
            raise unittest.SkipTest("the site's contract has no static export")
        cls.data = cls.class_dir / "data"
        cls.static_port = harness.pick_port()
        cls.site = harness.Site(harness.BINARY, cls.class_dir / "site")
        cls.site.start(cls.data, "--static-listen", f"127.0.0.1:{cls.static_port}")

    @classmethod
    def tearDownClass(cls):
        cls.site.stop()

    def export(self, data, *words):
        return harness.run([str(harness.BINARY), "export", "--data-dir", str(data), *words], cwd=self.class_dir,
                           capture_output=True, text=True, timeout=harness.WAITS["export"].seconds)

    def drush_eval(self, code, path):
        """php:eval under PATH set to path alone, so a child finds no php but the runtime's shim."""
        return harness.run([str(harness.BINARY), "drush", "--data-dir", str(self.data), "php:eval", code],
                           cwd=self.class_dir, capture_output=True, text=True, timeout=harness.WAITS["drush"].seconds,
                           env=dict(os.environ, PATH=str(path)))

    def test_an_export_writes_the_front_page_and_the_static_port_serves_it(self):
        result = self.export(self.data)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertTrue((self.data / "static" / "index.html").is_file(), result.stdout)
        status, _, body = request(self.static_port, "/")
        self.assertEqual(status, 200)
        self.assertIn(f"http://localhost:{self.static_port}/", body)

    def test_a_uri_the_reader_names_replaces_the_static_url(self):
        result = self.export(self.data, "--uri", "https://example.test/")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        page = (self.data / "static" / "index.html").read_text(errors="replace")
        self.assertIn("https://example.test/", page)
        self.assertNotIn(f"http://localhost:{self.static_port}/", page)

    def test_an_export_on_empty_site_data_refuses_naming_the_directory(self):
        data = self.case_dir / "empty"
        result = self.export(data)
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn(str(data), result.stdout + result.stderr)
        self.assertFalse(data.exists(), "a refused export wrote Site data")

    def test_an_export_without_tome_static_refuses_naming_the_module(self):
        uninstall = harness.run_drush(harness.BINARY, self.class_dir, self.data, "pm:uninstall", "tome_static", "--yes")
        self.assertEqual(uninstall.returncode, 0, uninstall.stderr)
        self.addCleanup(harness.run_drush, harness.BINARY, self.class_dir, self.data, "pm:install", "tome_static", "--yes")
        result = self.export(self.data)
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("no tome_static module enabled. Enable it: " + harness.SITE["name"] + " drush pm:install tome_static",
                      result.stdout + result.stderr)

    def test_a_drush_child_runs_php_from_the_runtime_with_no_php_on_path(self):
        empty = harness.fresh_dir(self.case_dir / "empty-path")
        result = self.drush_eval("passthru('php -r \"echo 1;\"');", empty)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), "1")

    def test_the_drush_entry_reruns_itself_as_tomes_children_do(self):
        empty = harness.fresh_dir(self.case_dir / "empty-path")
        code = 'passthru(escapeshellarg($_SERVER["argv"][0]) . " --version", $status); exit($status);'
        result = self.drush_eval(code, empty)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("Drush Commandline Tool", result.stdout)
