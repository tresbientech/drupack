#!/usr/bin/env python3
"""Plans a release run: the version, the image tags, the candidate run to reuse,
the build matrices and the macOS test rows.

Usage: release-plan.py --ref-type TYPE --ref-name NAME --event-name EVENT --sha SHA
           --before SHA --platform PLATFORM --version VERSION --repo OWNER/NAME
           --targets targets.json [--output FILE]

The release workflow's gate job passes the event it runs for and reads the nine
key=value lines this appends to FILE. The same flags reproduce a run's plan on
any machine where `gh` is logged in.
"""

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

# The workflow's run-name formats the same title for a candidate run.
TITLE = "Release candidate {version}"
ZERO = "0" * 40
# Documentation reaches no executable. A path this pattern does not name builds.
DOCS = re.compile(r"^docs/|^LICENSE$|^[^/]+\.md$")
# The macOS test classes split into this many shards per architecture.
SHARDS = {"amd64": 4, "arm64": 1}
KEYS = ("version", "build", "candidate", "image_tags", "linux", "linux_runtime", "macos", "macos_tests", "windows")


def gh(path):
    return json.loads(subprocess.run(["gh", "api", path], check=True, capture_output=True).stdout)


def listed(path, body, key, fields):
    """The entries of body[key], each an object holding the typed fields."""
    entries = body.get(key) if isinstance(body, dict) else None
    if not isinstance(entries, list):
        raise ValueError(f"{path}: {key} is not a list")
    for entry in entries:
        if not isinstance(entry, dict) or not all(
                isinstance(entry.get(name), kind) and not (kind is int and isinstance(entry[name], bool))
                for name, kind in fields.items()):
            raise ValueError(f"{path}: an entry of {key} lacks {', '.join(fields)} of the right type")
    return entries


def version(event):
    if event["ref_type"] == "tag":
        return event["ref_name"]
    return event["version"] or "dev-" + event["sha"][:12]


def image_tags(event):
    if event["ref_type"] == "tag":
        return "sha"
    return "dev sha" if event["version"] and event["platform"] == "all" else "dev"


def candidate(event, fetch):
    """The id of the successful candidate run for this commit, unless its files expired."""
    if event["ref_type"] != "tag":
        return None
    title = TITLE.format(version=version(event))
    path = f"repos/{event['repo']}/actions/workflows/release.yml/runs?head_sha={event['sha']}&event=workflow_dispatch&status=success"
    runs = listed(path, fetch(path), "workflow_runs", {"display_title": str, "id": int})
    run = next((run["id"] for run in runs if run["display_title"] == title), None)
    if run is None:
        return None
    path = f"repos/{event['repo']}/actions/runs/{run}/artifacts?per_page=100"
    artifacts = listed(path, fetch(path), "artifacts", {"expired": bool})
    return None if any(artifact["expired"] for artifact in artifacts) else run


def platform_matrix(targets, system, platform):
    return [{"arch": name.removeprefix(system + "-")} for name in sorted(targets["platforms"])
            if name.startswith(system + "-") and platform in ("all", name)]


def changed_outside_docs(event, fetch):
    path = f"repos/{event['repo']}/compare/{event['before']}...{event['sha']}"
    files = listed(path, fetch(path), "files", {"filename": str})
    return [file["filename"] for file in files if not DOCS.search(file["filename"])]


def macos_tests(macos):
    return [{"arch": row["arch"], "shard": f"{index}/{SHARDS[row['arch']]}",
             "results": f"macos-{row['arch']}-{index}", "variant": index == SHARDS[row["arch"]]}
            for row in macos for index in range(1, SHARDS[row["arch"]] + 1)]


def linux_runtime(targets, linux):
    return [{"arch": row["arch"], "libc": libc["name"], "builder": libc["builder"], "suffix": libc["suffix"]}
            for row in linux for libc in targets["libcs"]]


def plan(event, targets, fetch):
    linux = macos = windows = []
    run = candidate(event, fetch)
    if run is None and (event["ref_type"] == "tag" or event["event_name"] == "workflow_dispatch"):
        linux, macos, windows = (platform_matrix(targets, system, event["platform"])
                                 for system in ("linux", "macos", "windows"))
    elif run is None and (event["before"] == ZERO or changed_outside_docs(event, fetch)):
        linux = [{"arch": "amd64"}]
    build = bool(linux or macos or windows)
    runtimes = linux_runtime(targets, linux)
    if build:
        payload = [row for row in linux_runtime(targets, [{"arch": "amd64"}]) if row["libc"] == "musl"]
        unique = {tuple(sorted(row.items())): row for row in runtimes + payload}.values()
        runtimes = sorted(unique, key=lambda row: (row["arch"], row["builder"], row["libc"], row["suffix"]))
    return dict(zip(KEYS, (version(event), build, run, image_tags(event), linux, runtimes, macos,
                           macos_tests(macos), windows)))


def reason(result):
    if result["candidate"] is not None:
        return f"Publishing the files of candidate run {result['candidate']}."
    return "Building." if result["build"] else "Nothing to build."


def encode(key, value):
    if key == "build":
        return str(value).lower()
    if key == "candidate":
        return "" if value is None else str(value)
    if isinstance(value, list):
        return json.dumps(value, separators=(",", ":"))
    return value


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    for flag in ("ref-type", "ref-name", "event-name", "sha", "before", "platform", "version", "repo", "targets"):
        parser.add_argument("--" + flag, required=True)
    parser.add_argument("--output")
    args = parser.parse_args(argv)
    print("Flags:", " ".join(f"--{name.replace('_', '-')} '{value}'" for name, value in vars(args).items() if value is not None))

    def fetch(path):
        print("Fetching", path)
        return gh(path)

    event = {name: getattr(args, name) for name in ("ref_type", "ref_name", "event_name", "sha", "before", "platform", "version", "repo")}
    result = plan(event, json.loads(Path(args.targets).read_text()), fetch)
    print(reason(result))
    lines = "".join(f"{key}={encode(key, result[key])}\n" for key in KEYS)
    print(lines, end="")
    if args.output:
        with open(args.output, "a") as output:
            output.write(lines)


if __name__ == "__main__":
    main(sys.argv[1:])
