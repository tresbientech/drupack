"""Two sites of one release, served at the same time on Windows from separate cache roots.

PHP keys its opcache segment per user account there. Windows loads two copies of one
executable at different paths as distinct images, so the second server finds opcode
handlers from the first one's image and aborts with "Opcode handlers are unusable due to
ASLR" before it binds, unless each start carries its own cache id. Separate cache roots are
what produce the two paths: two sites sharing one root load one image and never collide,
so a case without them passes whether or not the product carries the fix.

The first site keeps serving through the second one's abort, so only a fetch of both sites
tells the two states apart.
"""

import os

import harness

ADMIN_USER = "concurrent-admin"
ADMIN_PASSWORD = "Concurrent.test.password.2026"


class ConcurrentSites(harness.ConformanceCase):
    PLATFORMS = (harness.WINDOWS,)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def test_two_sites_of_one_release_serve_at_once(self):
        first = harness.Site(harness.BINARY, self.case_dir / "first")
        second = harness.Site(harness.BINARY, self.case_dir / "second")
        first_env = dict(os.environ,
                         DRUPACK_CACHE_DIR=str(harness.reserved_dir(self.case_dir / "first-cache")))
        second_env = dict(os.environ,
                          DRUPACK_CACHE_DIR=str(harness.reserved_dir(self.case_dir / "second-cache")))
        try:
            first.start(self.case_dir / "first-data", "--admin-user", ADMIN_USER,
                        "--admin-password", ADMIN_PASSWORD, env=first_env)
            second.start(self.case_dir / "second-data", "--admin-user", ADMIN_USER,
                         "--admin-password", ADMIN_PASSWORD, env=second_env)
            self.assertEqual(second.fetch("/user/login")[0], 200,
                             f"the second site never answered: inspect {second.log_path}")
            self.assertEqual(first.fetch("/user/login")[0], 200,
                             f"the first site stopped answering: inspect {first.log_path}")
        finally:
            second.stop()
            first.stop()
