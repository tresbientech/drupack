#!/usr/bin/env python3
"""Exercise the embedded executable through Chromium without network access."""

import base64
import json
import os
import re
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlparse
from urllib.request import Request, urlopen


BINARY = Path(sys.argv.pop(1)).resolve()
RESULTS = Path(sys.argv.pop(1)).resolve()
ORIGIN = "http://localhost:8080"
PASSWORD = "Offline.test.administrator.2026!"
ELEMENT = "element-6066-11e4-a52e-4f735466cecf"
PHASE = int(os.environ.get("PORTABLE_TEST_PHASE", "6"))
EXTRA = os.environ.get("PORTABLE_TEST_EXTRA", "")


def http(url, method="GET", payload=None):
    data = None if payload is None else json.dumps(payload).encode()
    request = Request(url, data, {"Content-Type": "application/json"}, method=method)
    with urlopen(request, timeout=120) as response:
        return response.read()


def wait_until(check, timeout=60):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            result = check()
            if result:
                return result
        except (URLError, ConnectionError):
            pass
        time.sleep(0.25)
    raise AssertionError(f"Timed out after {timeout}s: {check}")


class Browser:
    def __init__(self):
        self.session = ""
        capabilities = {"alwaysMatch": {"browserName": "chrome", "goog:chromeOptions": {
            "binary": "/usr/bin/chromium",
            "args": ["--headless=new", "--no-sandbox", "--disable-dev-shm-usage",
                     "--window-size=1440,1000", "--disable-background-networking", "--disable-gpu"],
        }}}
        self.session = self.command("POST", "/session", {"capabilities": capabilities})["sessionId"]

    def command(self, method, path, payload=None):
        prefix = f"/session/{self.session}" if self.session else ""
        try:
            result = json.loads(http("http://127.0.0.1:9515" + prefix + path, method, payload))
        except HTTPError as error:
            raise AssertionError(error.read().decode()) from error
        return result["value"]

    def visit(self, path):
        self.command("POST", "/url", {"url": urljoin(ORIGIN, path)})

    def script(self, script, *args):
        return self.command("POST", "/execute/sync", {"script": script, "args": list(args)})

    def elements(self, selector):
        return self.command("POST", "/elements", {"using": "css selector", "value": selector})

    def fill(self, selector, value):
        element = self.elements(selector)[0][ELEMENT]
        self.command("POST", f"/element/{element}/clear", {})
        self.command("POST", f"/element/{element}/value", {"text": value})

    def click(self, selector):
        element = self.elements(selector)[0][ELEMENT]
        self.command("POST", f"/element/{element}/click", {})

    def submit(self, selector='input[type="submit"], button[type="submit"]'):
        invalid = self.script("const f=document.querySelector(arguments[0]).form; return f ? Array.from(f.querySelectorAll(':invalid')).map(e=>e.name) : [];", selector)
        if invalid:
            raise AssertionError(f"Invalid form fields: {invalid}")
        self.script("window.portableTestNavigationPending=true")
        self.click(selector)
        wait_until(lambda: self.script("return !window.portableTestNavigationPending"), timeout=120)

    def select(self, selector, value):
        self.script("const e=document.querySelector(arguments[0]); e.value=arguments[1]; e.dispatchEvent(new Event('change',{bubbles:true}));", selector, value)

    def check(self, selector):
        self.script("const e=document.querySelector(arguments[0]); if(!e.checked) e.click();", selector)

    def wait_batch(self):
        wait_until(lambda: not self.elements('.progress, [data-drupal-selector="update-progress"]'), timeout=600)

    def text(self):
        return self.script("return document.body.innerText")

    def save(self, name):
        RESULTS.joinpath(name + ".html").write_text(self.command("GET", "/source"))
        RESULTS.joinpath(name + ".txt").write_text(self.text())


class OfflineSite(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        RESULTS.mkdir(parents=True, exist_ok=True)
        cls.work = Path(tempfile.mkdtemp(prefix="site-", dir=RESULTS))
        cls.binary = cls.work / "portable-drupal"
        shutil.copyfile(BINARY, cls.binary)
        cls.binary.chmod(0o700)
        cls.driver_log = open(RESULTS / "chromedriver.log", "w")
        cls.driver = subprocess.Popen(["chromedriver", "--port=9515"],
                                      stdout=cls.driver_log, stderr=subprocess.STDOUT)
        wait_until(lambda: http("http://127.0.0.1:9515/status"))
        cls.browser = Browser()
        cls.server = None
        cls.server_log = open(RESULTS / "server.log", "w")

    @classmethod
    def tearDownClass(cls):
        cls.stop()
        cls.browser.command("DELETE", "")
        cls.driver.terminate()
        cls.driver.wait(timeout=20)
        cls.driver_log.close()
        cls.server_log.close()

    @classmethod
    def start(cls, *arguments):
        cls.server = subprocess.Popen([str(cls.binary), *arguments],
                                      cwd=cls.work, stdout=cls.server_log,
                                      stderr=subprocess.STDOUT, start_new_session=True)
        wait_until(lambda: cls.ready())

    @classmethod
    def ready(cls):
        if cls.server.poll() is not None:
            raise AssertionError(f"Runtime exited: inspect {RESULTS / 'server.log'}")
        with socket.create_connection(("127.0.0.1", 8080), timeout=1):
            return True

    @classmethod
    def stop(cls):
        if cls.server is not None and cls.server.poll() is None:
            os.killpg(cls.server.pid, signal.SIGTERM)
            try:
                cls.server.wait(timeout=20)
            except subprocess.TimeoutExpired:
                os.killpg(cls.server.pid, signal.SIGKILL)
                cls.server.wait()

    def install(self, language="en"):
        browser = self.browser
        browser.visit("/")
        if language != "en":
            browser.select('[name="langcode"]', language)
            wait_until(lambda: browser.script("return document.documentElement.lang") == language)
        deadline = time.monotonic() + 600
        step = 0
        configured = False
        while time.monotonic() < deadline:
            url = browser.command("GET", "/url")
            browser.save(f"installer-{step:02d}")
            self.assertNotIn("The installation has encountered an error", browser.text())
            if "/core/install.php" not in url:
                self.assertTrue(configured, f"Installer was not shown: {url}\n{browser.text()}")
                self.assertNotIn("unexpected error", browser.text().lower())
                return
            if browser.elements('[name="site_name"]'):
                browser.fill('[name="site_name"]', "Portable Byte Test")
                browser.submit()
                wait_until(lambda: not browser.elements('[name="site_name"]'))
            elif browser.elements('[name="account[mail]"]'):
                browser.fill('[name="account[mail]"]', "admin@example.test")
                browser.fill('[name="account[pass]"]', PASSWORD)
                browser.submit()
                configured = True
                wait_until(lambda: not browser.elements('[name="account[mail]"]'))
            elif browser.elements('[name="langcode"]'):
                browser.script("const e=document.querySelector('[name=langcode]'); e.value='en'; e.dispatchEvent(new Event('change',{bubbles:true}));")
                browser.submit()
            elif browser.elements('[name="add_ons"]'):
                choices = browser.script("return Array.from(document.querySelectorAll('[name=add_ons]')).map(e=>e.value)")
                self.assertEqual(choices, ["byte"])
                browser.click('[name="add_ons"][value="byte"]')
                browser.submit()
                wait_until(lambda: not browser.elements('[name="add_ons"]'))
            elif browser.elements('.progress, [data-drupal-selector="update-progress"]'):
                time.sleep(2)
            else:
                self.fail(f"Unexpected installer page: {url}\n{browser.text()}")
            step += 1
        self.fail("Offline installation exceeded 600 seconds")

    def login(self):
        self.browser.command("DELETE", "/cookie")
        self.browser.visit("/user/login")
        self.browser.fill('[name="name"]', "admin")
        self.browser.fill('[name="pass"]', PASSWORD)
        self.browser.submit()
        self.browser.visit("/admin/content")
        self.assertNotIn("Access denied", self.browser.text())
        self.assertFalse(self.browser.elements('[name="pass"]'))
        self.assertEqual(str(self.browser.script("return drupalSettings.user.uid")), "1")

    def assert_protected(self, data):
        secret = "private-offline-test-content"
        (data / "private" / "probe.txt").write_text(secret)
        for path in ["/site.sqlite", "/data/site.sqlite", "/private/probe.txt",
                     "/sites/default/settings.php", "/sites/default/settings.php/probe",
                     "/sites/default/files/.htaccess", "/composer.json", "/vendor/autoload.php"]:
            with self.subTest(path=path):
                try:
                    content = http(ORIGIN + path).decode(errors="replace")
                except HTTPError as error:
                    self.assertIn(error.code, [403, 404])
                    error.close()
                    continue
                self.assertNotIn(secret, content)
                self.assertNotIn("SQLite format", content)
                self.assertNotIn("<?php", content)
                self.fail(f"Protected path returned success: {path}")

    def assert_assets(self):
        self.browser.visit("/")
        assets = self.browser.script("return Array.from(document.querySelectorAll('script[src],link[rel=stylesheet],img[src]')).map(e=>e.src||e.href)")
        self.assertTrue(assets)
        for asset in assets:
            if asset.startswith("data:"):
                continue
            with self.subTest(asset=asset):
                self.assertEqual(urlparse(asset).netloc, urlparse(ORIGIN).netloc)
                self.assertTrue(http(asset))

    def create_content_and_upload(self, data):
        self.browser.visit("/node/add/page")
        self.browser.fill('[name="title[0][value]"]', "Offline persistent page")
        self.browser.fill('[name="field_description[0][value]"]', "Content created without an internet connection.")
        self.browser.submit()
        wait_until(lambda: self.browser.script("return /^node\\/\\d+$/.test(drupalSettings.path.currentPath)"))
        self.assertIn("Offline persistent page", self.browser.text())
        node = self.browser.script("return drupalSettings.path.currentPath")
        self.assertRegex(node, r"^node/\d+$")
        picture = self.work / "offline-pixel.png"
        picture.write_bytes(base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII="))
        self.browser.visit("/media/add/image")
        self.browser.fill('[name="name[0][value]"]', "Offline image")
        upload = self.browser.elements('input[type="file"]')[0][ELEMENT]
        self.browser.command("POST", f"/element/{upload}/value", {"text": str(picture)})
        wait_until(lambda: self.browser.elements('[name="field_media_image[0][alt]"]'))
        self.browser.fill('[name="field_media_image[0][alt]"]', "Offline test pixel")
        self.browser.submit()
        wait_until(lambda: "/media/add/image" not in self.browser.command("GET", "/url"))
        uploaded = list((data / "files").rglob("offline-pixel.png"))
        self.assertEqual(len(uploaded), 1)
        self.assertEqual(uploaded[0].read_bytes(), picture.read_bytes())
        image_url = "/sites/default/files/" + uploaded[0].relative_to(data / "files").as_posix()
        self.assertEqual(http(ORIGIN + image_url), picture.read_bytes())
        return node, image_url

    def enable_modules(self, modules):
        self.browser.visit("/admin/modules")
        for module in modules:
            self.browser.check(f'[name="modules[{module}][enable]"]')
        self.browser.submit()
        for _ in range(2):
            if self.browser.elements('[data-drupal-selector="system-modules-confirm-form"], [data-drupal-selector="system-modules-non-stable-confirm-form"]'):
                self.browser.submit()
        self.browser.wait_batch()
        self.browser.visit("/admin/modules")
        for module in modules:
            self.assertTrue(self.browser.script("return document.querySelector(arguments[0]).checked", f'[name="modules[{module}][enable]"]'))

    def configure_languages(self):
        self.enable_modules(["language", "locale", "content_translation"])
        self.browser.visit("/admin/config/regional/language")
        for code in ["fr", "zh-hans", "es", "hi", "ar"]:
            self.assertFalse(self.browser.elements(f'[name="languages[{code}][weight]"]'))
        coverage = {}
        for code in ["fr", "zh-hans", "es", "hi", "ar"]:
            self.browser.visit("/admin/config/regional/language/add")
            self.browser.select('[name="predefined_langcode"]', code)
            self.browser.submit('[data-drupal-selector="edit-predefined-submit"]')
            self.browser.wait_batch()
            self.browser.visit("/admin/config/regional/language")
            selector = f'[name="languages[{code}][weight]"]'
            self.assertTrue(self.browser.elements(selector))
            row = self.browser.script("return document.querySelector(arguments[0]).closest('tr').innerText", selector)
            translated = re.search(r"(\d+)/(\d+) \(([\d.]+)%\)", row)
            self.assertIsNotNone(translated, row)
            self.assertGreater(int(translated[1]), 0)
            coverage[code] = {"translated": int(translated[1]), "total": int(translated[2])}
            RESULTS.joinpath("translation-coverage.json").write_text(json.dumps(coverage, indent=2))
        self.browser.visit("/ar/admin/content")
        self.assertEqual(self.browser.script("return document.documentElement.dir"), "rtl")
        self.assertEqual(self.browser.script("return document.documentElement.lang"), "ar")

    def translate_content(self, node):
        self.browser.visit("/admin/config/regional/content-language")
        self.browser.check('[name="entity_types[node]"]')
        self.browser.check('[name="settings[node][page][translatable]"]')
        self.browser.check('[name="settings[node][page][fields][title]"]')
        self.browser.submit()
        self.browser.visit(f"/{node}/translations/add/en/fr")
        self.browser.fill('[name="title[0][value]"]', "Page hors ligne persistante")
        self.browser.submit()
        wait_until(lambda: "/translations/add/" not in self.browser.command("GET", "/url"))
        self.browser.visit(f"/fr/{node}")
        self.assertIn("Page hors ligne persistante", self.browser.text())
        self.browser.visit(f"/{node}")
        self.assertIn("Offline persistent page", self.browser.text())

    def exercise_arabic_editor(self):
        self.browser.visit("/ar/node/add/page")
        self.assertEqual(self.browser.script("return document.documentElement.dir"), "rtl")
        self.browser.fill('[name="title[0][value]"]', "صفحة اختبار دون اتصال")
        self.browser.fill('[name="field_description[0][value]"]', "محتوى محفوظ من واجهة عربية دون اتصال بالإنترنت.")
        self.browser.submit()
        wait_until(lambda: self.browser.script("return /^node\\/\\d+$/.test(drupalSettings.path.currentPath)"))
        self.assertIn("صفحة اختبار دون اتصال", self.browser.text())

    def activate_bundled_components(self, node):
        self.enable_modules(["contact"])
        self.browser.visit("/admin/structure/contact")
        self.assertIn("Contact forms", self.browser.text())
        self.browser.visit("/admin/appearance")
        self.browser.click('a[href*="theme=stark"][title*="default"]')
        self.browser.visit("/" + node)
        self.assertEqual(self.browser.script("return drupalSettings.ajaxPageState.theme"), "stark")

        self.stop()
        self.start()
        self.login()
        self.browser.visit("/admin/structure/contact")
        self.assertIn("Contact forms", self.browser.text())
        self.browser.visit("/" + node)
        self.assertEqual(self.browser.script("return drupalSettings.ajaxPageState.theme"), "stark")

    def exercise_devel_fixture(self):
        self.enable_modules(["devel"])
        self.browser.visit("/admin/config/development/devel")
        self.assertTrue(self.browser.elements('[data-drupal-selector="devel-admin-settings-form"]'))
        self.browser.submit()
        self.assertIn("The configuration options have been saved", self.browser.text())

    def test_command_line(self):
        help_result = subprocess.run([str(self.binary), "--help"], cwd=self.work,
                                     capture_output=True, text=True, timeout=30)
        self.assertEqual(help_result.returncode, 0, help_result.stderr)
        for option in ["--data-dir", "--listen", "--host"]:
            self.assertIn(option, help_result.stdout)
        self.assertNotIn("php-cli", help_result.stdout)
        self.assertNotIn("launch.php", help_result.stdout)
        invalid = subprocess.run([str(self.binary), "--not-an-option"], cwd=self.work,
                                 capture_output=True, text=True, timeout=30)
        self.assertNotEqual(invalid.returncode, 0)
        self.assertIn("--not-an-option", invalid.stdout + invalid.stderr)
        self.assertFalse((self.work / "data").exists())

    def test_extensions(self):
        with tempfile.NamedTemporaryFile(mode="w", suffix=".php", dir=self.work) as probe:
            probe.write("<?php echo json_encode([get_loaded_extensions(), PDO::getAvailableDrivers()]);")
            probe.flush()
            result = subprocess.run([str(self.binary), "php-cli", probe.name], cwd=self.work,
                                    capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        extensions, drivers = json.loads(result.stdout)
        required = {"ctype", "curl", "dom", "exif", "fileinfo", "filter", "gd", "iconv",
                    "intl", "mbstring", "mysqli", "mysqlnd", "openssl", "pcntl", "pdo",
                    "pdo_mysql", "pdo_sqlite", "phar", "session", "simplexml", "sodium",
                    "tokenizer", "xml", "xmlreader", "xmlwriter", "zip", "zlib", "zend opcache"}
        self.assertFalse(required - {extension.lower() for extension in extensions})
        self.assertTrue({"mysql", "sqlite"} <= set(drivers), drivers)

    def assert_package_contents(self):
        roots = list((self.work / "data" / "runtime").glob("frankenphp_*"))
        self.assertEqual(len(roots), 1)
        root = roots[0]
        excluded_names = {"tests", "Tests", ".github", ".gitlab", "package-lock.json",
                          "yarn.lock", "pnpm-lock.yaml", "npm-shrinkwrap.json"}
        excluded = [str(path.relative_to(root)) for path in root.rglob("*")
                    if path.name in excluded_names or path.name.endswith((".js.map", ".css.map", ".pcss.css"))]
        self.assertEqual(excluded, [])
        for path in ["web/modules/contrib/canvas/ui/lib", "web/modules/contrib/canvas/ui/assets/videos",
                     "web/modules/contrib/canvas/packages/cli/src", "web/modules/contrib/canvas/packages/workbench/src",
                     "web/modules/contrib/canvas/packages/eslint-config/src", "web/modules/contrib/modeler_api/ui/src",
                     "web/modules/contrib/project_browser/sveltejs/src", "web/modules/contrib/project_browser/sveltejs/scripts",
                     "vendor/html2text/html2text/test"]:
            self.assertFalse((root / path).exists(), path)
        source = root / "web/modules/contrib/canvas/ui/src"
        licenses = [str(path.relative_to(source)) for path in source.rglob("*") if path.is_file()]
        self.assertEqual(licenses, ["local_packages/hyperscriptify/LICENSE"])
        self.assertIn("Permission is hereby granted", (source / licenses[0]).read_text())
        for path in ["launch.php", "web/index.php", "vendor/autoload.php",
                     "web/modules/contrib/canvas/ui/dist/assets/index.js", "web/modules/contrib/canvas/ui/dist/assets/index.css",
                     "web/modules/contrib/project_browser/sveltejs/public/build/bundle.js",
                     "web/modules/contrib/modeler_api/js/template-token-selector.js"]:
            self.assertGreater((root / path).stat().st_size, 0, path)
        self.assertEqual(len(list((root / "translations").glob("*.po"))), 301 if EXTRA == "devel" else 296)

    def test_install_restart_and_custom_directory(self):
        self.assertIsNone(shutil.which("php"))
        self.assertIsNone(shutil.which("composer"))
        self.start()
        try:
            self.assert_package_contents()
            self.install()
            data = self.work / "data"
            self.assertTrue((data / "site.sqlite").is_file())
            self.assertTrue((data / "settings.php").is_file())
            self.login()
            if EXTRA == "devel":
                self.exercise_devel_fixture()
            self.assert_assets()
            self.assert_protected(data)
            node, image_url = self.create_content_and_upload(data)
            self.stop()
            replacement = self.work / "replacement"
            shutil.copyfile(BINARY, replacement)
            replacement.chmod(0o700)
            os.replace(replacement, self.binary)
            self.start()
            self.login()
            self.browser.visit("/" + node)
            self.assertIn("Offline persistent page", self.browser.text())
            self.assertTrue(http(ORIGIN + image_url))
            self.assert_assets()
            if PHASE >= 3:
                self.configure_languages()
                self.exercise_arabic_editor()
            if PHASE >= 4:
                self.translate_content(node)
                self.stop()
                self.start()
                self.login()
                self.browser.visit(f"/fr/{node}")
                self.assertIn("Page hors ligne persistante", self.browser.text())
                self.browser.visit("/ar/admin/content")
                self.assertEqual(self.browser.script("return document.documentElement.dir"), "rtl")
            if PHASE >= 6:
                self.activate_bundled_components(node)
            self.stop()
            custom = self.work / "another site"
            self.start("--data-dir", str(custom))
            self.install("fr" if PHASE >= 3 else "en")
            self.login()
            if PHASE >= 3:
                self.assertEqual(self.browser.script("return document.documentElement.lang"), "fr")
            self.assertTrue((custom / "site.sqlite").is_file())
            self.assert_protected(custom)
            self.assertNotEqual((data / "hash_salt").read_text(), (custom / "hash_salt").read_text())
        finally:
            self.browser.save("last-page")
            self.stop()


if __name__ == "__main__":
    unittest.main(verbosity=2)
