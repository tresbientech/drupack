"""DRUPACK_SHARD's dealing of test classes, checked against a table the test writes. Run with:

    python3 -m unittest discover -s tests/conformance -p test_shard.py
"""

import importlib.util
import json
import sys
import tempfile
import unittest
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
        (directory / "class-seconds.json").write_text(
            json.dumps({sys.platform: {"extra": 0, "classes": classes}}))
        original = conformance_main.__file__
        conformance_main.__file__ = str(directory / "__main__.py")
        self.addCleanup(setattr, conformance_main, "__file__", original)
        self.suite = unittest.TestSuite(case_class(name)("test_it") for name in ("Alpha", "Beta", "Gamma", "Delta"))

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


if __name__ == "__main__":
    unittest.main()
