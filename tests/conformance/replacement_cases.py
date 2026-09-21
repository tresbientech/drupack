"""Replacing the executable in place must leave the site it already runs untouched."""

import glob
import shutil

import harness

ADMIN_USER = "replacement-admin"
ADMIN_PASSWORD = "Replacement.test.password.2026"
SITE_NAME = "Drupack replacement check"


class ReplacementCases(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)
        self.data = self.case_dir / "data"
        self.old_binary = self._copy_binary("old")
        self.old_site = harness.Site(self.old_binary, self.case_dir / "old")
        self.new_site = None  # created once a new copy of the binary exists

    def tearDown(self):
        self.old_site.stop()
        if self.new_site is not None:
            self.new_site.stop()

    def _copy_binary(self, name):
        directory = self.case_dir / name
        directory.mkdir(parents=True, exist_ok=True)
        # BINARY's own suffix carries the platform's naming rule: none on Linux and macOS,
        # ".exe" on Windows, where a copy named plainly "drupack" would not run.
        binary = directory / f"drupack{harness.BINARY.suffix}"
        shutil.copyfile(harness.BINARY, binary)
        binary.chmod(0o700)
        return binary

    def test_replacement_preserves_site_state(self):
        self.old_site.start(self.data, "--admin-user", ADMIN_USER, "--admin-password", ADMIN_PASSWORD)
        self.old_site.stop()

        renamed = harness.run_dr(self.old_binary, self.case_dir, self.data,
                                  "config:set", "system.site", "name", SITE_NAME, "--yes")
        self.assertEqual(renamed.returncode, 0, renamed.stderr)

        (self.data / "files" / "replacement.txt").write_text("public-replacement-sentinel")
        (self.data / "private" / "replacement.txt").write_text("private-replacement-sentinel")
        before = self.case_dir / "before"
        before.mkdir(exist_ok=True)
        shutil.copyfile(self.data / "settings.php", before / "settings.php")
        shutil.copyfile(self.data / "hash_salt", before / "hash_salt")

        new_binary = self._copy_binary("new")
        # The old binary's own directory holds nothing the running site depends
        # on, so removing it leaves the new binary serving what follows.
        shutil.rmtree(self.case_dir / "old")

        self.new_site = harness.Site(new_binary, self.case_dir / "new")
        self.new_site.start(self.data)
        self.assertIn(SITE_NAME, self.new_site.http("/"))
        self.new_site.stop()

        name = harness.run_dr(new_binary, self.case_dir, self.data,
                               "config:get", "system.site", "name", "--format=string")
        self.assertEqual(name.returncode, 0, name.stderr)
        self.assertEqual(name.stdout.strip(), SITE_NAME)

        admin = harness.run_dr(new_binary, self.case_dir, self.data,
                                "user:information", ADMIN_USER, "--field=name")
        self.assertEqual(admin.returncode, 0, admin.stderr)
        self.assertEqual(admin.stdout.strip(), ADMIN_USER)

        self.assertEqual((self.data / "files" / "replacement.txt").read_text(), "public-replacement-sentinel")
        self.assertEqual((self.data / "private" / "replacement.txt").read_text(), "private-replacement-sentinel")
        self.assertEqual((before / "settings.php").read_bytes(), (self.data / "settings.php").read_bytes())
        self.assertEqual((before / "hash_salt").read_bytes(), (self.data / "hash_salt").read_bytes())
        # The application lives in the user cache, one directory per release, so
        # Site data holds none of it whichever executable ran.
        self.assertEqual(
            glob.glob(str(self.data / "runtime" / "frankenphp_*")), [],
            "an executable left a copy of the application inside Site data",
        )
        self.assertFalse((self.data / "web").exists(),
                         "an executable left the site tree inside Site data")
