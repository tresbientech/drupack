"""The release workflow's platform choices, checked against launcher/internal/payload/targets.json.
GitHub reads a workflow_dispatch input's options before any step runs, so the workflow
spells them out. Run with:

    python3 -m unittest discover -s tests/conformance -p test_targets.py
"""

import importlib.util
import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TARGETS = ROOT / "launcher" / "internal" / "payload" / "targets.json"
WORKFLOW = ROOT / ".github" / "workflows" / "release.yml"
spec = importlib.util.spec_from_file_location("release_plan", ROOT / "build" / "release" / "release-plan.py")
release_plan = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release_plan)


class PlatformChoices(unittest.TestCase):
    def test_the_dispatch_offers_all_and_each_platform_of_the_table(self):
        block = re.search(r"^      platform:\n(?:^        .*\n)*?^        options:\n((?:^          - .*\n)+)",
                          WORKFLOW.read_text(), re.MULTILINE)
        self.assertIsNotNone(block, f"no platform options in {WORKFLOW}")
        offered = re.findall(r"- (\S+)", block[1])
        platforms = json.loads(TARGETS.read_text())["platforms"]
        self.assertEqual("all", offered[0])
        self.assertEqual(sorted(platforms), sorted(offered[1:]))

    def test_the_run_name_formats_to_the_title_the_plan_looks_up(self):
        line = re.search(r"^run-name:.*$", WORKFLOW.read_text(), re.MULTILINE)
        self.assertIsNotNone(line, f"no run-name in {WORKFLOW}")
        format_string = re.search(r"format\('((?:''|[^'])*)',\s*inputs\.version\)", line[0])
        self.assertIsNotNone(format_string, f"no format() over inputs.version in {line[0]}")
        sample = "1.2.3-test"
        title = format_string[1].replace("''", "'").replace("{0}", sample)
        self.assertEqual(release_plan.TITLE.format(version=sample), title)


if __name__ == "__main__":
    unittest.main()
