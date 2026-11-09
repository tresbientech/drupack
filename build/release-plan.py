"""Plans a release run: the version, the image tags, the candidate run to reuse, the build
matrices and the macOS test rows. The gate job passes the event and reads the nine key=value
lines this appends to --output. The flags it prints reproduce the plan where `gh` is logged in.
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


def listed(path, body, key, fields):
    """The entries of body[key], each an object holding the typed fields."""
    entries = body.get(key) if isinstance(body, dict) else None
    if not (isinstance(entries, list) and all(
            isinstance(entry, dict) and all(type(entry.get(name)) is kind for name, kind in fields.items())
            for entry in entries)):
        raise ValueError(f"{path}: {key} is not a list of objects holding {', '.join(fields)} of the right type")
    return entries


def candidate(event, name, fetch):
    """The id of the successful candidate run for this commit, unless its files expired."""
    title = TITLE.format(version=name)
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


def linux_runtime(targets, linux):
    return [{"arch": row["arch"], "libc": libc["name"], "builder": libc["builder"], "suffix": libc["suffix"]}
            for row in linux for libc in targets["libcs"]]


def plan(event, targets, fetch):
    tag = event["ref_type"] == "tag"
    name = event["ref_name"] if tag else event["version"] or "dev-" + event["sha"][:12]
    image_tags = "sha" if tag else "dev sha" if event["version"] and event["platform"] == "all" else "dev"
    linux = macos = windows = []
    run = candidate(event, name, fetch) if tag else None
    if run is None and (tag or event["event_name"] == "workflow_dispatch"):
        linux, macos, windows = (platform_matrix(targets, system, event["platform"])
                                 for system in ("linux", "macos", "windows"))
    elif run is None and event["before"] == ZERO:
        linux = [{"arch": "amd64"}]
    elif run is None:
        path = f"repos/{event['repo']}/compare/{event['before']}...{event['sha']}"
        files = listed(path, fetch(path), "files", {"filename": str})
        linux = [{"arch": "amd64"}] if any(not DOCS.search(file["filename"]) for file in files) else []
    build = bool(linux or macos or windows)
    runtimes = linux_runtime(targets, linux)
    if build:
        payload = [row for row in linux_runtime(targets, [{"arch": "amd64"}]) if row["libc"] == "musl"]
        runtimes += [row for row in payload if row not in runtimes]
        runtimes.sort(key=lambda row: (row["arch"], row["builder"], row["libc"], row["suffix"]))
    tests = [{"arch": row["arch"], "shard": f"{index}/{SHARDS[row['arch']]}", "results": f"macos-{row['arch']}-{index}",
              "variant": index == SHARDS[row["arch"]]} for row in macos for index in range(1, SHARDS[row["arch"]] + 1)]
    return dict(zip(KEYS, (name, build, run, image_tags, linux, runtimes, macos, tests, windows)))


def encode(key, value):
    if key in ("version", "image_tags"):
        return value
    return "" if value is None else json.dumps(value, separators=(",", ":"))


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__)
    for flag in ("ref-type", "ref-name", "event-name", "sha", "before", "platform", "version", "repo", "targets"):
        parser.add_argument("--" + flag, required=True)
    parser.add_argument("--output")
    args = parser.parse_args(argv)
    print("Flags:", " ".join(f"--{name.replace('_', '-')} '{value}'" for name, value in vars(args).items() if value is not None))

    def fetch(path):
        print("Fetching", path)
        return json.loads(subprocess.run(["gh", "api", path], check=True, stdout=subprocess.PIPE).stdout)

    result = plan(vars(args), json.loads(Path(args.targets).read_text()), fetch)
    print(f"Publishing the files of candidate run {result['candidate']}." if result["candidate"] is not None
          else "Building." if result["build"] else "Nothing to build.")
    lines = "".join(f"{key}={encode(key, result[key])}\n" for key in KEYS)
    print(lines, end="")
    if args.output:
        with open(args.output, "a") as output:
            output.write(lines)


if __name__ == "__main__":
    main(sys.argv[1:])
