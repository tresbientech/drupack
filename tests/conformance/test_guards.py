"""The Caddy snippets both Caddyfiles import, checked without a built file. Run with:

    python3 -m unittest discover -s tests/conformance -p test_guards.py
"""

import os
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class CaddySnippets(unittest.TestCase):
    def test_the_engine_links_every_application_snippet(self):
        # Each executable packs its own directory; a copy in place of the link would drift.
        for snippet in sorted((ROOT / "application").glob("*.caddy")):
            with self.subTest(snippet=snippet.name):
                link = ROOT / "engine" / snippet.name
                self.assertTrue(link.is_symlink(), f"{link} is not a symbolic link")
                self.assertEqual(os.readlink(link), f"../application/{snippet.name}")


if __name__ == "__main__":
    unittest.main()
