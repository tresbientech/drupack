#!/usr/bin/env python3
"""Moves every pinned upstream to its newest version inside its line, and prints
what it did as a Markdown summary. It edits the working tree and never commits.

    python3 build/upstream.py
"""

import json
import os
import re
import sys
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BUILDER_INPUTS = "runtime/builder-inputs.sh"
WINDOWS_BUILD = "build/windows/build.ps1"
DEMO_COMPOSER = "examples/drupacked-demo/composer.json"
DEMO_SITE = "examples/drupacked-demo/drupack.yml"

PHP_RELEASES = "https://www.php.net/releases/index.php?json&version="
WINDOWS_CHECKSUMS = "https://downloads.php.net/~windows/releases/sha256sum.txt"
FRANKENPHP_API = "https://api.github.com/repos/php/frankenphp"
NODE_RELEASES = "https://nodejs.org/dist/index.json"


def fetch(url):
    request = urllib.request.Request(url)
    # A GitHub runner's token lifts the API's per-address rate limit.
    if url.startswith("https://api.github.com/") and os.environ.get("GITHUB_TOKEN"):
        request.add_header("Authorization", f"Bearer {os.environ['GITHUB_TOKEN']}")
    with urllib.request.urlopen(request, timeout=60) as response:
        return response.read().decode()


def version_key(version):
    return tuple(int(part) for part in version.split("."))


class Summary:
    def __init__(self):
        self.rows = []
        self.notes = []

    def row(self, source, current, found, action):
        self.rows.append((source, current, found, action))

    def render(self):
        lines = ["| Source | Current | Found | Action |", "|---|---|---|---|"]
        lines += [f"| {' | '.join(row)} |" for row in self.rows]
        return "\n".join(lines + [""] + self.notes) + "\n"


def read(path, pattern):
    """The single value `pattern` captures in `path`."""
    values = re.findall(pattern, (ROOT / path).read_text(), re.MULTILINE)
    if len(values) != 1:
        sys.exit(f"{path}: {pattern} matches {len(values)} times, not once")
    return values[0]


def rewrite(path, pattern, value):
    """Replaces the one value `pattern` captures in `path` with `value`."""
    file = ROOT / path
    text = file.read_text()
    matches = list(re.finditer(pattern, text, re.MULTILINE))
    if len(matches) != 1:
        sys.exit(f"{path}: {pattern} matches {len(matches)} times, not once")
    start, end = matches[0].span(1)
    file.write_text(text[:start] + value + text[end:])


def newest_php(line):
    # php.net answers for any version line it knows; its answer is remote input.
    version = json.loads(fetch(PHP_RELEASES + line))["version"]
    if not re.fullmatch(rf"{re.escape(line)}\.\d+(\.\d+)?", version):
        sys.exit(f"php.net named {version!r} as the newest PHP {line}")
    return version


def windows_hashes(version, toolset):
    """The SHA-256 of each Windows zip of `version`, or None while php.net lists either one missing."""
    listed = {name: sha256 for sha256, name in re.findall(r"^([0-9a-f]{64}) \*(\S+)$", fetch(WINDOWS_CHECKSUMS), re.MULTILINE)}
    names = [f"php-{version}-Win32-{toolset}.zip", f"php-devel-pack-{version}-Win32-{toolset}.zip"]
    if not all(name in listed for name in names):
        return None
    return [listed[name] for name in names]


def php(summary):
    current = read(BUILDER_INPUTS, r"^php_version=(.+)$")
    line = ".".join(current.split(".")[:2])
    newest = newest_php(line)
    major = newest_php(line.split(".")[0])
    if not major.startswith(line + "."):
        summary.notes.append(f"MAJOR PHP {major} is out, outside the {line} line")
    if version_key(newest) <= version_key(current):
        summary.row("PHP", current, newest, "current")
        return
    hashes = windows_hashes(newest, read(WINDOWS_BUILD, r"^\$phpToolset = '(.+)'$"))
    if hashes is None:
        summary.row("PHP", current, newest, "waits for the Windows zips")
        return
    rewrite(BUILDER_INPUTS, r"^php_version=(.+)$", newest)
    rewrite(WINDOWS_BUILD, r"^\$phpVersion = '(.+)'$", newest)
    for name, sha256 in zip(["php.zip", "php-devel.zip"], hashes):
        rewrite(WINDOWS_BUILD, rf"^  '{re.escape(name)}' = @\{{\n.*\n    Sha256 = '([0-9a-f]{{64}})'$", sha256)
    rewrite(DEMO_COMPOSER, r'"platform": \{"php": "([^"]+)"\}', newest)
    summary.row("PHP", current, newest, "bumped")


def frankenphp(summary):
    current = read(BUILDER_INPUTS, r"^frankenphp_version=(.+)$")
    line = current.split(".")[0]
    releases = [release["tag_name"] for release in json.loads(fetch(f"{FRANKENPHP_API}/releases?per_page=100"))
                if not release["draft"] and not release["prerelease"]]
    # GitHub release tags are remote input.
    versions = [tag[1:] for tag in releases if re.fullmatch(r"v\d+\.\d+\.\d+", tag)]
    newest = max((v for v in versions if v.split(".")[0] == line), key=version_key)
    above = max(versions, key=version_key)
    if above.split(".")[0] != line:
        summary.notes.append(f"MAJOR FrankenPHP {above} is out, outside the {line} line")
    if version_key(newest) <= version_key(current):
        summary.row("FrankenPHP", current, newest, "current")
        return
    target = json.loads(fetch(f"{FRANKENPHP_API}/git/ref/tags/v{newest}"))["object"]
    if target["type"] == "tag":
        target = json.loads(fetch(f"{FRANKENPHP_API}/git/tags/{target['sha']}"))["object"]
    if target["type"] != "commit" or not re.fullmatch(r"[0-9a-f]{40}", target["sha"]):
        sys.exit(f"FrankenPHP v{newest} names {target!r}, not a commit")
    rewrite(BUILDER_INPUTS, r"^frankenphp_version=(.+)$", newest)
    rewrite(BUILDER_INPUTS, r"^frankenphp_commit=(.+)$", target["sha"])
    rewrite(WINDOWS_BUILD, r"^\$frankenphpVersion = '(.+)'$", newest)
    rewrite(WINDOWS_BUILD, r"^\$frankenphpCommit = '(.+)'$", target["sha"])
    summary.row("FrankenPHP", current, newest, "bumped")


def node(summary):
    current = read(DEMO_SITE, r"^node: (.+)$")
    line = current.split(".")[0]
    # nodejs.org's index is remote input; lts is false or the line's name.
    versions = [release["version"][1:] for release in json.loads(fetch(NODE_RELEASES))
                if release["lts"] and re.fullmatch(r"v\d+\.\d+\.\d+", release["version"])]
    newest = max((v for v in versions if v.split(".")[0] == line), key=version_key)
    above = max(versions, key=version_key)
    if above.split(".")[0] != line:
        summary.notes.append(f"MAJOR Node {above} LTS is out, outside the {line} line")
    if version_key(newest) <= version_key(current):
        summary.row("Node", current, newest, "current")
        return
    rewrite(DEMO_SITE, r"^node: (.+)$", newest)
    summary.row("Node", current, newest, "bumped")


def main():
    summary = Summary()
    php(summary)
    frankenphp(summary)
    node(summary)
    sys.stdout.write(summary.render())


if __name__ == "__main__":
    main()
