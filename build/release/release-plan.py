"""Plans a release run: the version, the image tags, the candidate run to reuse, the build
matrices and the Linux, macOS and Windows test rows. The gate job passes the event and reads the eleven
key=value lines this appends to --output. The flags it prints reproduce the plan where `gh` is logged in.
"""

import argparse
import json
import re
import shlex
import subprocess
import sys
import time
from pathlib import Path

# The workflow's run-name formats the same title for a candidate run.
TITLE = "Release candidate {version}"
ZERO = "0" * 40
# Documentation reaches no executable. A path this pattern does not name builds.
DOCS = re.compile(r"^docs/|^LICENSE$|^[^/]+\.md$")
# The macOS test rows per architecture, one per share of the test classes. An architecture's last
# test row also runs the variant cases. An architecture without rows runs the start cases in its build job.
SHARDS = {"arm64": 2}
# The Linux test rows per architecture. The first runs the musl start and uploads the engine
# executables; amd64's first also runs the unit tests and the lint.
LINUX_SHARDS = 2
# The Windows test rows per runner, one per share. amd64's last share also runs the variant cases.
WINDOWS_SHARDS = 5


def listed(path, body, key, fields):
    """The entries of body[key], each an object holding the typed fields."""
    entries = body.get(key) if isinstance(body, dict) else None
    if not (isinstance(entries, list) and all(
            isinstance(entry, dict) and all(type(entry.get(name)) is kind for name, kind in fields.items())
            for entry in entries)):
        raise ValueError(f"{path}: {key} is not a list of objects holding {', '.join(fields)} of the right type")
    return entries


def candidate(event, fetch):
    """The id of the successful candidate run for this commit, unless its files expired. A commit
    holds one version's pin, so its candidate needs no version to be found."""
    path = f"repos/{event['repo']}/actions/workflows/release.yml/runs?head_sha={event['sha']}&event=workflow_dispatch&status=success"
    runs = listed(path, fetch(path), "workflow_runs", {"display_title": str, "id": int})
    run = next((run["id"] for run in runs if run["display_title"].startswith(TITLE.format(version=""))), None)
    if run is None:
        return None
    path = f"repos/{event['repo']}/actions/runs/{run}/artifacts?per_page=100"
    artifacts = listed(path, fetch(path), "artifacts", {"expired": bool})
    return None if any(artifact["expired"] for artifact in artifacts) else run


def platform_matrix(targets, system, platform):
    return [{"arch": name.removeprefix(system + "-")} for name in sorted(targets["platforms"])
            if name.startswith(system + "-") and platform in ("all", name)]


def linux_runtime(targets, linux):
    return [{"arch": row["arch"], "libc": libc["name"], "builder": libc["builder"], "suffix": libc["suffix"]}
            for row in linux for libc in targets["libcs"]]


def linux_tests(linux, gate):
    """A gate run tests arm64 with the smoke set; every other run tests each architecture in full."""
    rows = []
    for row in linux:
        if gate and row["arch"] != "amd64":
            rows.append({"arch": row["arch"], "shard": "", "smoke": True, "first": True, "units": False})
            continue
        rows += [{"arch": row["arch"], "shard": f"{index}/{LINUX_SHARDS}", "smoke": False, "first": index == 1,
                  "units": index == 1 and row["arch"] == "amd64"} for index in range(1, LINUX_SHARDS + 1)]
    return rows


def windows_tests(windows, gate):
    """Both runners test the amd64 build: arm64 runs it under x64 emulation, and a gate run
    gives arm64 the smoke set alone."""
    if not windows:
        return []
    rows = [{"name": f"Windows amd64 tests ({index}/{WINDOWS_SHARDS})", "runner": "windows-2025",
             "results": f"windows-amd64-{index}", "shard": f"{index}/{WINDOWS_SHARDS}+extra",
             "variant": index == WINDOWS_SHARDS, "smoke": False} for index in range(1, WINDOWS_SHARDS + 1)]
    if gate:
        return rows + [{"name": "Windows arm64 start cases (amd64 emulated)", "runner": "windows-11-arm",
                        "results": "windows-arm64-smoke", "shard": "", "variant": False, "smoke": True}]
    rows += [{"name": f"Windows arm64 tests (amd64 emulated, {index}/{WINDOWS_SHARDS})", "runner": "windows-11-arm",
              "results": f"windows-arm64-{index}", "shard": f"{index}/{WINDOWS_SHARDS}", "variant": False,
              "smoke": False} for index in range(1, WINDOWS_SHARDS + 1)]
    return rows + [{"name": "Windows arm64 variant tests (amd64 emulated)", "runner": "windows-11-arm",
                    "results": "windows-arm64-variant", "shard": "", "variant": True, "smoke": False}]


def plan(event, targets, fetch):
    tag = event["ref_type"] == "tag"
    # A gate candidate tests one native platform per system in full; a tag run and a push take no suite.
    gate = event["suite"] == "gate"
    version = event["ref_name"] if tag else (event["version"] or "dev-" + event["sha"][:12])
    # build.yml reads sha-COMMIT at a tag and dev-COMMIT on a branch, and a tag run publishes its candidate's sha-COMMIT.
    image_tags = "sha" if tag else "dev sha" if event["version"] and event["platform"] == "all" else "dev"
    linux = macos = windows = []
    # A release pushes its tested commit to main and to the tag, and both runs reuse its candidate.
    run = candidate(event, fetch) if event["event_name"] == "push" else None
    # The weekly schedule plans like a dispatch of every platform with no version.
    if run is None and (tag or event["event_name"] in ("workflow_dispatch", "schedule")):
        linux, macos, windows = (platform_matrix(targets, system, event["platform"])
                                 for system in ("linux", "macos", "windows"))
    # A new branch reports an all-zero before, which compares against nothing.
    elif run is None and event["before"] == ZERO:
        linux = [{"arch": "amd64"}]
    elif run is None:
        path = f"repos/{event['repo']}/compare/{event['before']}...{event['sha']}"
        files = listed(path, fetch(path), "files", {"filename": str})
        linux = [{"arch": "amd64"}] if any(not DOCS.search(file["filename"]) for file in files) else []
    # Every platform packs the payload job's output, so any target calls for it.
    build = bool(linux or macos or windows)
    runtimes = linux_runtime(targets, linux)
    if build:
        # The payload job runs PHP from the amd64 musl runtime, so any build compiles it.
        payload = [row for row in linux_runtime(targets, [{"arch": "amd64"}]) if row["libc"] == "musl"]
        runtimes += [row for row in payload if row not in runtimes]
        runtimes.sort(key=lambda row: (row["arch"], row["builder"], row["libc"], row["suffix"]))
    tests = [{"arch": row["arch"], "shard": f"{index}/{SHARDS[row['arch']]}", "results": f"macos-{row['arch']}-{index}",
              "variant": index == SHARDS[row["arch"]]} for row in macos if row["arch"] in SHARDS
             for index in range(1, SHARDS[row["arch"]] + 1)]
    return {"version": version, "build": build, "candidate": run, "image_tags": image_tags, "linux": linux,
            "linux_tests": linux_tests(linux, gate), "linux_runtime": runtimes, "macos": macos, "macos_tests": tests,
            "windows": windows, "windows_tests": windows_tests(windows, gate)}


def encode(key, value):
    if key in ("version", "image_tags"):
        return value
    return "" if value is None else json.dumps(value, separators=(",", ":"))


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__)
    for flag in ("ref-type", "ref-name", "event-name", "sha", "before", "platform", "version", "repo", "targets"):
        parser.add_argument("--" + flag, required=True)
    # Empty on a push, a tag and the schedule, which test in full.
    parser.add_argument("--suite", required=True, choices=("", "full", "gate"))
    parser.add_argument("--output")
    args = parser.parse_args(argv)
    print("Flags:", " ".join(f"--{name.replace('_', '-')} {shlex.quote(value)}" for name, value in vars(args).items() if name != "output"))
    fetched = []

    def fetch(path):
        print("Fetching", path)
        fetched.append(path)
        # GitHub answers 500 for a compare under load, so a failed read gets two more tries.
        for pause in (10, 30):
            result = subprocess.run(["gh", "api", path], stdout=subprocess.PIPE)
            if result.returncode == 0:
                return json.loads(result.stdout)
            time.sleep(pause)
        return json.loads(subprocess.run(["gh", "api", path], check=True, stdout=subprocess.PIPE).stdout)

    result = plan(vars(args), json.loads(Path(args.targets).read_text()), fetch)
    # Artifacts are fetched only for a matching candidate, so a plan without one dropped it.
    expired = [path for path in fetched if "/artifacts?" in path]
    if expired and result["candidate"] is None:
        print(f"The files of candidate run {expired[0].split('/')[5]} expired.")
    print(f"Reusing the files of candidate run {result['candidate']}." if result["candidate"] is not None
          else "Building." if result["build"] else "Nothing to build.")
    lines = "".join(f"{key}={encode(key, value)}\n" for key, value in result.items())
    print(lines, end="")
    if args.output:
        with open(args.output, "a") as output:
            output.write(lines)


if __name__ == "__main__":
    main(sys.argv[1:])
