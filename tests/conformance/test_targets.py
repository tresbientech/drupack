"""The release workflow's platform choices, checked against launcher/internal/runtime/targets.json.
GitHub reads a workflow_dispatch input's options before any step runs, so the workflow
spells them out. Run with:

    python3 -m unittest discover -s tests/conformance -p test_targets.py
"""

import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TARGETS = ROOT / "launcher" / "internal" / "runtime" / "targets.json"
WORKFLOW = ROOT / ".github" / "workflows" / "release.yml"


class PlatformChoices(unittest.TestCase):
    def test_the_dispatch_offers_all_and_each_platform_of_the_table(self):
        block = re.search(r"^      platform:\n(?:^        .*\n)*?^        options:\n((?:^          - .*\n)+)",
                          WORKFLOW.read_text(), re.MULTILINE)
        self.assertIsNotNone(block, f"no platform options in {WORKFLOW}")
        offered = re.findall(r"- (\S+)", block[1])
        platforms = json.loads(TARGETS.read_text())["platforms"]
        self.assertEqual("all", offered[0])
        self.assertEqual(sorted(platforms), sorted(offered[1:]))


if __name__ == "__main__":
    unittest.main()
