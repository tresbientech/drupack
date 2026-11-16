"""DRUPACK_SHARD's dealing of test classes, checked against a table the test writes. Run with:

    python3 -m unittest discover -s tests/conformance -p test_shard.py
"""

import importlib.util
import json
import sys
import tempfile
import unittest
import unittest.mock
from pathlib import Path

CONFORMANCE = Path(__file__).resolve().parent
# __main__.py imports harness, which sits beside it.
sys.path.insert(0, str(CONFORMANCE))
spec = importlib.util.spec_from_file_location("conformance_main", CONFORMANCE / "__main__.py")
conformance_main = importlib.util.module_from_spec(spec)
spec.loader.exec_module(conformance_main)


def case_class(name):
    return type(name, (unittest.TestCase,), {"__module__": "fake_cases", "test_it": lambda self: None})


class Shards(unittest.TestCase):
    def setUp(self):
        # shard() reads class-seconds.json beside its own file.
        directory = Path(tempfile.mkdtemp())
        self.addCleanup(lambda: [path.unlink() for path in directory.iterdir()] or directory.rmdir())
        classes = {"fake_cases.Alpha": 100, "fake_cases.Beta": 100, "fake_cases.Delta": 10}
        self.table = directory / "class-seconds.json"
        self.table.write_text(
            json.dumps({sys.platform: {"extra": 0, "classes": classes}}))
        original = conformance_main.__file__
        conformance_main.__file__ = str(directory / "__main__.py")
        self.addCleanup(setattr, conformance_main, "__file__", original)
        self.suite = unittest.TestSuite(case_class(name)("test_it") for name in ("Alpha", "Beta", "Gamma", "Delta"))

    def test_extra_starts_the_last_share_with_the_seconds_of_its_other_work(self):
        # The last share starts at 200: Alpha, Beta and Gamma (70, the mean) fill the first
        # share before it passes 200, and Delta alone goes to the last.
        table = json.loads(self.table.read_text())
        table[sys.platform]["extra"] = 200
        self.table.write_text(json.dumps(table))
        self.assertEqual(self.kept("1/2+extra"), ["Alpha", "Beta", "Gamma"])
        self.assertEqual(self.kept("2/2+extra"), ["Delta"])

    def kept(self, spec):
        return sorted(type(test).__name__ for test in conformance_main.shard(self.suite, spec))

    def test_a_class_missing_from_the_table_is_dealt_at_the_mean(self):
        # Gamma counts as 70, the mean of 100, 100 and 10: it goes after Alpha and Beta and
        # before Delta, and lands with Alpha on the tied shares. Counted as 0 it would pair with Beta.
        self.assertEqual(self.kept("1/2"), ["Alpha", "Gamma"])
        self.assertEqual(self.kept("2/2"), ["Beta", "Delta"])

    def test_every_class_lands_in_exactly_one_share(self):
        both = self.kept("1/2") + self.kept("2/2")
        self.assertEqual(sorted(both), ["Alpha", "Beta", "Delta", "Gamma"])


def named_case(module, name, *methods):
    body = {"__module__": module, **{method: lambda self: None for method in methods}}
    return type(name, (unittest.TestCase,), body)


class Smoke(unittest.TestCase):
    def setUp(self):
        engine = named_case("engine_cases", "EngineExecutable",
                            "test_a_start_serves_the_folder_and_writes_only_its_files", "test_dr_runs_drupal_cores_command_line")
        site = named_case("site_cases", "SeededSite", "test_seeded_sqlite_site_and_drush", "test_extensions", "test_protected_files")
        self.suite = unittest.TestSuite([
            unittest.defaultTestLoader.loadTestsFromTestCase(engine),
            unittest.defaultTestLoader.loadTestsFromTestCase(site),
        ])

    def selected(self, suite):
        loader = conformance_main.CaseLoader(None)
        with unittest.mock.patch.object(unittest.TestLoader, "discover", return_value=self.suite), \
                unittest.mock.patch.dict("os.environ", {"DRUPACK_SUITE": suite}):
            return sorted(test.id() for test in conformance_main.flatten(loader.discover("unused")))

    def test_the_smoke_suite_keeps_the_start_cases_alone(self):
        self.assertEqual(self.selected("smoke"), sorted(conformance_main.SMOKE))

    def test_the_full_suite_keeps_every_case(self):
        self.assertEqual(len(self.selected("full")), 5)

    def test_another_suite_stops_the_run(self):
        with self.assertRaises(SystemExit):
            self.selected("quick")


if __name__ == "__main__":
    unittest.main()
