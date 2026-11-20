"""runtime/extensions/check-extensions.py, run against hand-written locks. Run with:

    python3 -m unittest discover -s tests/conformance -p test_check_extensions.py
"""

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[2] / "runtime" / "extensions" / "check-extensions.py"


class CheckExtensions(unittest.TestCase):
    def check(self, lock, *arguments):
        site = tempfile.TemporaryDirectory()
        self.addCleanup(site.cleanup)
        (Path(site.name) / "composer.lock").write_text(json.dumps(lock))
        return subprocess.run([sys.executable, str(SCRIPT), site.name, *arguments],
                              capture_output=True, text=True, check=False)

    def test_a_lock_inside_the_allowlist_passes(self):
        lock = {"packages": [{"name": "drupal/core", "require": {"php": ">=8.3", "ext-PDO": "*", "ext-json": "*"}}],
                "platform": {"ext-zend-opcache": "*", "ext-SPL": "*"}}
        result = self.check(lock)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, "2 declared extensions, all in runtime/extensions/php-extensions.txt"
                                        " or drupack.yml's extensions\n")

    def test_an_extension_outside_the_lists_fails_and_names_who_requires_it(self):
        lock = {"packages": [{"name": "vendor/a", "require": {"ext-imagick": "*"}},
                             {"name": "vendor/b", "require": {"ext-imagick": "*", "ext-pdo": "*"}},
                             {"name": "vendor/c"}],
                "packages-dev": [{"name": "vendor/dev", "require": {"ext-xdebug": "*"}}],
                "platform": {"ext-imagick": "*"},
                "platform-dev": {"ext-pcov": "*"}}
        result = self.check(lock)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stderr.splitlines()[1:], ["  imagick: composer.json, vendor/a, vendor/b"])

    def test_a_site_addition_covers_its_extension(self):
        lock = {"packages": [{"name": "vendor/a", "require": {"ext-imagick": "*"}}]}
        result = self.check(lock, "--extensions=xmlwriter,imagick")
        self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == "__main__":
    unittest.main()
