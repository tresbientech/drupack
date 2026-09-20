"""A start with a bad argument must refuse fast and never write Site data."""

import subprocess

import harness


class ArgumentCases(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def refuse(self, data, *args):
        """Run a start expected to refuse within the refusal budget; assert no Site data."""
        log = self.case_dir / "run.log"
        with open(log, "wb") as log_handle:
            result = harness.run(
                [str(harness.BINARY), *args], cwd=self.case_dir,
                stdout=log_handle, stderr=subprocess.STDOUT,
                timeout=harness.WAITS["refusal"].seconds,
            )
        self.assertNotEqual(result.returncode, 0, f"expected a refusal: inspect {log}")
        self.assertFalse(data.exists(), f"a refused start wrote persistent data to {data}")
        return log.read_text(errors="replace")

    def _refuses_without_connection_details(self, backend):
        data = self.case_dir / "data"
        diagnostic = self.refuse(data, "--data-dir", str(data), "--database", backend)
        self.assertIn(f"Missing database connection details for {backend}", diagnostic.splitlines())

    def test_mysql_start_without_connection_details_refuses(self):
        self._refuses_without_connection_details("mysql")

    def test_pgsql_start_without_connection_details_refuses(self):
        self._refuses_without_connection_details("pgsql")

    def test_unsupported_database_backend_refuses(self):
        data = self.case_dir / "data"
        self.refuse(data, "--data-dir", str(data), "--database", "invalid")

    def test_quoted_data_dir_refuses(self):
        data = self.case_dir / 'quoted"dir'
        diagnostic = self.refuse(data, "--data-dir", str(data))
        self.assertIn("double quote", diagnostic)

    def test_dr_without_a_site_refuses(self):
        data = self.case_dir / "drush"
        diagnostic = self.refuse(data, "dr", "--data-dir", str(data), "status")
        self.assertIn(str(data), diagnostic)
