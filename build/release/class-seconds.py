"""Writes tests/conformance/class-seconds.json from the test jobs of one release run.

Usage: python3 build/release/class-seconds.py RUN_ID [--repo OWNER/NAME]

`gh` reads the run's jobs and their logs. Each result line of the suite carries the time its
test ended, so a class's seconds are the gaps before its lines. A platform's table keeps the
slower architecture's figure. Each platform's `extra` stays as it is.
"""

import argparse
import json
import re
import subprocess
from datetime import datetime
from pathlib import Path

# The jobs that run the suite, per sys.platform.
JOBS = {"linux": re.compile(r"^Linux (amd64|arm64)"), "darwin": re.compile(r"^macOS (amd64|arm64) tests"),
        "win32": re.compile(r"^Windows (amd64|arm64) tests")}
STAMP = re.compile(r"^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)\.\d+Z (.*)$")
# unittest names a test "method (module.Class.method)" and a class-level skip "setUpClass (module.Class)".
NAMED = re.compile(r"^(?:test\w+ \((\w+\.\w+)\.test\w+\)|setUpClass \((\w+\.\w+)\))")
ENDED = re.compile(r" \.\.\. (ok|FAIL|ERROR|skipped|expected failure|unexpected success)")


def class_seconds(log):
    """The seconds per class of the job's longest suite run: a job also runs smaller ones."""
    runs, current, previous, named = [], {}, None, None
    for line in log.splitlines():
        match = STAMP.match(line)
        if not match:
            continue
        stamp, text = datetime.fromisoformat(match[1]), match[2]
        name = NAMED.match(text)
        if name:
            named = name[1] or name[2]
        # A test with a docstring prints its result on the line after its name.
        if named and ENDED.search(text):
            current[named] = current.get(named, 0) + (stamp - previous).total_seconds()
            named = None
        elif text.startswith("Ran ") and current:
            runs.append(current)
            current = {}
        previous = stamp
    return max(runs, key=len, default={})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("run")
    parser.add_argument("--repo", default="tresbientech/drupack")
    args = parser.parse_args()

    def gh(path):
        return subprocess.run(["gh", "api", "--paginate", path], check=True, stdout=subprocess.PIPE, text=True).stdout

    jobs = json.loads(gh(f"repos/{args.repo}/actions/runs/{args.run}/jobs?per_page=100"))["jobs"]
    path = Path(__file__).resolve().parents[2] / "tests" / "conformance" / "class-seconds.json"
    table = json.loads(path.read_text())
    for platform, pattern in JOBS.items():
        architectures = {}
        for job in jobs:
            match = pattern.match(job["name"])
            if match and job["conclusion"] == "success":
                seconds = class_seconds(gh(f"repos/{args.repo}/actions/jobs/{job['id']}/logs"))
                architectures.setdefault(match[1], {}).update(seconds)
        if not architectures:
            print(f"{platform}: the run has no green test job, table kept")
            continue
        names = sorted(set().union(*architectures.values()))
        table[platform]["classes"] = {name: round(max(seconds.get(name, 0) for seconds in architectures.values()))
                                      for name in names}
        print(f"{platform}: {len(names)} classes, {sum(table[platform]['classes'].values())} seconds")
    path.write_text(json.dumps(table, indent=2) + "\n")


main()
