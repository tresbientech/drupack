#!/usr/bin/env python3
"""Moves the PHP, FrankenPHP, Windows PECL, Composer, Go and Go tool pins to their newest version inside their line,
and prints what it did as a Markdown summary. Go's line is its own or the one FrankenPHP needs,
whichever is newer. It edits the working tree and never commits.

    python3 build/release/upstream.py
"""

import base64
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BUILDER_INPUTS = "runtime/builder/builder-inputs.sh"
WINDOWS_BUILD = "build/windows/build.ps1"
RELEASE_WORKFLOW = ".github/workflows/release.yml"
GO_MODS = ("launcher/go.mod", "runtime/frankenphp/watch/go.mod")
DOCKERFILE = "Dockerfile"

PHP_RELEASES = "https://www.php.net/releases/index.php?json&version="
WINDOWS_CHECKSUMS = "https://downloads.php.net/~windows/releases/sha256sum.txt"
FRANKENPHP_API = "https://api.github.com/repos/php/frankenphp"
# static-php-cli v3 has no tagged release. Its branch head moves with each FrankenPHP
# bump, since FrankenPHP's own builder runs that branch's nightly.
SPC_BRANCH = "https://api.github.com/repos/crazywhalecc/static-php-cli/branches/v3"
# FrankenPHP's vcpkg.json names a vcpkg baseline, which names the vcpkg-tool release
# that reads it. The Windows build checks out vcpkg at that baseline.
FRANKENPHP_RAW = "https://raw.githubusercontent.com/php/frankenphp"
VCPKG_RAW = "https://raw.githubusercontent.com/microsoft/vcpkg"
VCPKG_TOOL_RELEASES = "https://api.github.com/repos/microsoft/vcpkg-tool/releases/tags/"
PECL_RELEASES = "https://pecl.php.net/rest/r/"
PECL_WINDOWS = "https://downloads.php.net/~windows/pecl/releases/"
GO_RELEASES = "https://go.dev/dl/?mode=json&include=all"
DOCKER_TOKEN = "https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/golang:pull"
DOCKER_MANIFESTS = "https://registry-1.docker.io/v2/library/golang/manifests/"
GO_PROXY = "https://proxy.golang.org/"
COMPOSER_VERSIONS = "https://getcomposer.org/versions"
COMPOSER_DOWNLOAD = "https://getcomposer.org/download/"
# Composer's Tags Public Key, from https://composer.github.io/pubkeys.html.
COMPOSER_KEY = "build/release/composer-releases.pub"

# Each pin's line in its file, its value captured.
PHP_PIN = r"^php_version=(.+)$"
PHP_WINDOWS_PIN = r"^\$phpVersion = '(.+)'$"
PHP_TOOLSET = r"^\$phpToolset = '(.+)'$"
FRANKENPHP_PIN = r"^frankenphp_version=(.+)$"
FRANKENPHP_COMMIT_PIN = r"^frankenphp_commit=(.+)$"
FRANKENPHP_WINDOWS_PIN = r"^\$frankenphpVersion = '(.+)'$"
FRANKENPHP_WINDOWS_COMMIT_PIN = r"^\$frankenphpCommit = '(.+)'$"
SPC_PIN = r"^spc_commit=(.+)$"
VCPKG_PIN = r"^\$vcpkgCommit = '(.+)'$"
VCPKG_TOOL_PIN = r"^    Urls = @\('https://github\.com/microsoft/vcpkg-tool/releases/download/(.+)/vcpkg\.exe'\)$"
# A PECL extension the Windows build downloads, with its version and checksum.
PECL_ENTRY = r"^  '{name}' = @\{{ Version = '{version}'; Sha256 = '{sha256}' \}}$"
VCPKG_TOOL_SHA_PIN = r"^  'vcpkg\.exe' = @\{\n.*\n    Sha256 = '([0-9a-f]{64})'$"
GO_PIN = r"^  GO_VERSION: '(.+)'$"
GO_LINE = r"^go (\d+\.\d+(?:\.\d+)?)$"
GO_IMAGE_PIN = r"^ARG JOB_BASE=golang:(.+)-bookworm@sha256:[0-9a-f]{64}$"
GO_IMAGE_DIGEST_PIN = r"^ARG JOB_BASE=golang:.+-bookworm@sha256:([0-9a-f]{64})$"
COMPOSER_PIN = r"^RUN wget -q -O /opt/composer\.phar https://getcomposer\.org/download/(.+)/composer\.phar \\$"
COMPOSER_SHA_PIN = r"^    && echo '([0-9a-f]{64})  /opt/composer\.phar' \| sha256sum -c -$"
GO_RELEASE = re.compile(r"go\d+\.\d+\.\d+")
# Each Go tool the checks install as MODULE/cmd/NAME@vX.Y.Z: its name, its module and the
# file pinning it. The module path names the major, so its newest release stays inside it.
GO_TOOLS = (
    ("golangci-lint", "github.com/golangci/golangci-lint/v2", "build/test/go-tests.sh"),
    ("govulncheck", "golang.org/x/vuln", RELEASE_WORKFLOW),
)
STABLE_TAG = re.compile(r"v\d+\.\d+\.\d+")
PACKAGIST_METADATA = "https://repo.packagist.org/p2/"

RELEASE = re.compile(r"v?(\d+)\.(\d+)\.(\d+)(?:-(alpha|beta|rc)(\d+))?", re.IGNORECASE)
STABILITY = {"alpha": 0, "beta": 1, "rc": 2, None: 3}


def download(url):
    request = urllib.request.Request(url)
    # A GitHub runner's token lifts the API's per-address rate limit.
    if url.startswith("https://api.github.com/") and os.environ.get("GITHUB_TOKEN"):
        request.add_header("Authorization", f"Bearer {os.environ['GITHUB_TOKEN']}")
    with urllib.request.urlopen(request, timeout=60) as response:
        return response.read()


def fetch(url):
    return download(url).decode()


def head(url, headers):
    """The response headers of a HEAD request to url."""
    request = urllib.request.Request(url, method="HEAD", headers=headers)
    with urllib.request.urlopen(request, timeout=60) as response:
        return response.headers


def version_key(version):
    return tuple(int(part) for part in version.split("."))


def release_key(version):
    """Orders a release by number, then stability, then prerelease number; None for any other version."""
    match = RELEASE.fullmatch(version)
    if match is None:
        return None
    major, minor, patch, stability, number = match.groups()
    return (int(major), int(minor), int(patch), STABILITY[stability and stability.lower()], int(number or 0))


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
    current = read(BUILDER_INPUTS, PHP_PIN)
    line = ".".join(current.split(".")[:2])
    newest = newest_php(line)
    major = newest_php(line.split(".")[0])
    if not major.startswith(line + "."):
        summary.notes.append(f"MAJOR PHP {major} is out, outside the {line} line")
    if version_key(newest) <= version_key(current):
        summary.row("PHP", current, newest, "current")
        return
    hashes = windows_hashes(newest, read(WINDOWS_BUILD, PHP_TOOLSET))
    if hashes is None:
        summary.row("PHP", current, newest, "waits for the Windows zips")
        return
    rewrite(BUILDER_INPUTS, PHP_PIN, newest)
    rewrite(WINDOWS_BUILD, PHP_WINDOWS_PIN, newest)
    for name, sha256 in zip(["php.zip", "php-devel.zip"], hashes):
        rewrite(WINDOWS_BUILD, rf"^  '{re.escape(name)}' = @\{{\n.*\n    Sha256 = '([0-9a-f]{{64}})'$", sha256)
    summary.row("PHP", current, newest, "bumped")


def pecl_pin(name="([a-z_]+)", version="([^']+)", sha256="[0-9a-f]{64}"):
    """PECL_ENTRY with each field's pattern; the defaults capture name and version."""
    return PECL_ENTRY.format(name=name, version=version, sha256=sha256)


def pecl(summary):
    php_minor = ".".join(read(WINDOWS_BUILD, PHP_WINDOWS_PIN).split(".")[:2])
    toolset = read(WINDOWS_BUILD, PHP_TOOLSET)
    for name, current in re.findall(pecl_pin(), (ROOT / WINDOWS_BUILD).read_text(), re.MULTILINE):
        line = current.split(".")[0]
        # PECL's release list is remote input.
        stable = re.findall(r"<v>([^<]+)</v><s>stable</s>", fetch(f"{PECL_RELEASES}{name}/allreleases.xml"))
        if not stable or not all(re.fullmatch(r"\d+\.\d+\.\d+", version) for version in stable):
            sys.exit(f"PECL lists the stable {name} releases {stable!r}")
        newest = max((v for v in stable if v.split(".")[0] == line), key=version_key)
        above = max(stable, key=version_key)
        if above.split(".")[0] != line:
            summary.notes.append(f"MAJOR php_{name} {above} is out, outside the {line} line")
        if version_key(newest) <= version_key(current):
            summary.row(f"php_{name}", current, newest, "current")
            continue
        try:
            archive = download(f"{PECL_WINDOWS}{name}/{newest}/php_{name}-{newest}-{php_minor}-ts-{toolset}.zip")
        except urllib.error.HTTPError as error:
            if error.code != 404:
                raise
            summary.row(f"php_{name}", current, newest, "waits for the Windows DLL")
            continue
        rewrite(WINDOWS_BUILD, pecl_pin(name), newest)
        rewrite(WINDOWS_BUILD, pecl_pin(name, version="[^']+", sha256="([0-9a-f]{64})"), hashlib.sha256(archive).hexdigest())
        summary.row(f"php_{name}", current, newest, "bumped")


def composer(summary):
    current = read(DOCKERFILE, COMPOSER_PIN)
    line = current.split(".")[0]
    # getcomposer.org's version list is remote input.
    stable = [entry.get("version") for entry in json.loads(fetch(COMPOSER_VERSIONS)).get("stable", [])]
    if not stable or not all(isinstance(v, str) and re.fullmatch(r"\d+\.\d+\.\d+", v) for v in stable):
        sys.exit(f"getcomposer.org lists the stable Composer releases {stable!r}")
    newest = max((v for v in stable if v.split(".")[0] == line), key=version_key, default=current)
    above = max(stable, key=version_key)
    if above.split(".")[0] != line:
        summary.notes.append(f"MAJOR Composer {above} is out, outside the {line} line")
    if version_key(newest) <= version_key(current):
        summary.row("Composer", current, newest, "current")
        return
    phar = download(f"{COMPOSER_DOWNLOAD}{newest}/composer.phar")
    verify_composer(newest, phar, fetch(f"{COMPOSER_DOWNLOAD}{newest}/composer.phar.sig"))
    rewrite(DOCKERFILE, COMPOSER_PIN, newest)
    rewrite(DOCKERFILE, COMPOSER_SHA_PIN, hashlib.sha256(phar).hexdigest())
    summary.row("Composer", current, newest, "bumped")


def verify_composer(version, phar, sig):
    """Stops the run unless sig, a composer.phar.sig, signs phar under COMPOSER_KEY. composer
    self-update runs the same SHA-384 check."""
    try:
        signature = base64.b64decode(json.loads(sig)["sha384"], validate=True)
    except (ValueError, KeyError, TypeError) as error:
        sys.exit(f"Composer {version}'s composer.phar.sig holds no SHA-384 signature: {error!r}")
    with tempfile.NamedTemporaryFile() as file:
        file.write(signature)
        file.flush()
        result = subprocess.run(["openssl", "dgst", "-sha384", "-verify", str(ROOT / COMPOSER_KEY), "-signature", file.name],
                                input=phar, capture_output=True, check=False)
    if result.returncode != 0:
        sys.exit(f"Composer {version}'s composer.phar.sig fails against {COMPOSER_KEY}: {result.stderr.decode().strip()}")


def frankenphp(summary):
    current = read(BUILDER_INPUTS, FRANKENPHP_PIN)
    line = current.split(".")[0]
    releases = [release["tag_name"] for release in json.loads(fetch(f"{FRANKENPHP_API}/releases?per_page=100"))
                if not release["draft"] and not release["prerelease"]]
    # GitHub release tags are remote input.
    versions = [tag[1:] for tag in releases if STABLE_TAG.fullmatch(tag)]
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
    rewrite(BUILDER_INPUTS, FRANKENPHP_PIN, newest)
    rewrite(BUILDER_INPUTS, FRANKENPHP_COMMIT_PIN, target["sha"])
    rewrite(WINDOWS_BUILD, FRANKENPHP_WINDOWS_PIN, newest)
    rewrite(WINDOWS_BUILD, FRANKENPHP_WINDOWS_COMMIT_PIN, target["sha"])
    summary.row("FrankenPHP", current, newest, "bumped")
    spc_current = read(BUILDER_INPUTS, SPC_PIN)
    # The branch head is remote input.
    spc_head = json.loads(fetch(SPC_BRANCH))["commit"]["sha"]
    if not re.fullmatch(r"[0-9a-f]{40}", spc_head):
        sys.exit(f"static-php-cli v3 names {spc_head!r}, not a commit")
    rewrite(BUILDER_INPUTS, SPC_PIN, spc_head)
    summary.row("static-php-cli", spc_current[:12], spc_head[:12], "current" if spc_head == spc_current else "bumped")
    vcpkg(summary, target["sha"])


def vcpkg(summary, frankenphp_commit):
    current = read(WINDOWS_BUILD, VCPKG_PIN)
    # The manifest, the tool metadata and the release digest are remote input.
    baseline = json.loads(fetch(f"{FRANKENPHP_RAW}/{frankenphp_commit}/vcpkg.json")).get("builtin-baseline")
    if not isinstance(baseline, str) or not re.fullmatch(r"[0-9a-f]{40}", baseline):
        sys.exit(f"FrankenPHP {frankenphp_commit[:12]}'s vcpkg.json names baseline {baseline!r}, not a commit")
    metadata = fetch(f"{VCPKG_RAW}/{baseline}/scripts/vcpkg-tool-metadata.txt")
    tool = re.search(r"^VCPKG_TOOL_RELEASE_TAG=(\d{4}-\d{2}-\d{2})$", metadata, re.MULTILINE)
    if not tool:
        sys.exit(f"vcpkg {baseline[:12]} names no vcpkg-tool release")
    assets = json.loads(fetch(VCPKG_TOOL_RELEASES + tool[1]))["assets"]
    digest = next((asset.get("digest") for asset in assets if asset["name"] == "vcpkg.exe"), None)
    if not isinstance(digest, str) or not re.fullmatch(r"sha256:[0-9a-f]{64}", digest):
        sys.exit(f"vcpkg-tool {tool[1]} gives vcpkg.exe the digest {digest!r}")
    rewrite(WINDOWS_BUILD, VCPKG_PIN, baseline)
    rewrite(WINDOWS_BUILD, VCPKG_TOOL_PIN, tool[1])
    rewrite(WINDOWS_BUILD, VCPKG_TOOL_SHA_PIN, digest.removeprefix("sha256:"))
    summary.row("vcpkg", current[:12], baseline[:12], "current" if baseline == current else "bumped")


def go(summary):
    current = read(RELEASE_WORKFLOW, GO_PIN)
    frankenphp_commit = read(BUILDER_INPUTS, FRANKENPHP_COMMIT_PIN)
    # The macOS and Windows builds compile FrankenPHP's caddy module with GOTOOLCHAIN=local.
    # Its go.mod is remote input.
    lines = re.findall(GO_LINE, fetch(f"{FRANKENPHP_RAW}/{frankenphp_commit}/caddy/go.mod"), re.MULTILINE)
    if len(lines) != 1:
        sys.exit(f"FrankenPHP {frankenphp_commit[:12]}'s caddy/go.mod names the go lines {lines!r}")
    # A go line without a patch names the line's first release, X.Y.0.
    floor = lines[0] if lines[0].count(".") == 2 else lines[0] + ".0"
    line = max(current, floor, key=version_key).rsplit(".", 1)[0]
    # go.dev's release list is remote input.
    versions = [release["version"][2:] for release in json.loads(fetch(GO_RELEASES))
                if release.get("stable") is True and GO_RELEASE.fullmatch(release.get("version", ""))]
    newest = max((v for v in versions if v.rsplit(".", 1)[0] == line), key=version_key, default=None)
    if newest is None or version_key(newest) < version_key(floor):
        sys.exit(f"go.dev lists no Go {line} release at or above {floor}")
    if version_key(newest) <= version_key(current):
        summary.row("Go", current, newest, "current")
        return
    digest = golang_digest(newest)
    rewrite(RELEASE_WORKFLOW, GO_PIN, newest)
    for path in GO_MODS:
        rewrite(path, GO_LINE, newest)
    rewrite(DOCKERFILE, GO_IMAGE_PIN, newest)
    rewrite(DOCKERFILE, GO_IMAGE_DIGEST_PIN, digest)
    summary.row("Go", current, newest, "bumped")


def golang_digest(version):
    """The digest of the multi-platform index Docker Hub serves as golang:VERSION-bookworm."""
    # Docker Hub's token and digest are remote input.
    token = json.loads(fetch(DOCKER_TOKEN)).get("token")
    if not isinstance(token, str) or not token:
        sys.exit("Docker Hub gave no pull token for library/golang")
    digest = head(f"{DOCKER_MANIFESTS}{version}-bookworm", {
        "Authorization": f"Bearer {token}",
        "Accept": "application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json",
    }).get("Docker-Content-Digest")
    if not isinstance(digest, str) or not re.fullmatch(r"sha256:[0-9a-f]{64}", digest):
        sys.exit(f"Docker Hub gives golang:{version}-bookworm the digest {digest!r}")
    return digest.removeprefix("sha256:")


def tool_pin(name, module):
    return rf"{re.escape(module)}/cmd/{re.escape(name)}@v(\d+\.\d+\.\d+)$"


def tools(summary):
    for name, module, path in GO_TOOLS:
        pattern = tool_pin(name, module)
        current = read(path, pattern)
        # The module proxy's answer is remote input.
        version = json.loads(fetch(f"{GO_PROXY}{module}/@latest")).get("Version")
        if not isinstance(version, str) or not STABLE_TAG.fullmatch(version):
            sys.exit(f"proxy.golang.org gives {module} the latest version {version!r}")
        newest = version[1:]
        if version_key(newest) <= version_key(current):
            summary.row(name, current, newest, "current")
            continue
        rewrite(path, pattern, newest)
        summary.row(name, current, newest, "bumped")


def main():
    summary = Summary()
    php(summary)
    pecl(summary)
    composer(summary)
    frankenphp(summary)
    go(summary)
    tools(summary)
    sys.stdout.write(summary.render())


if __name__ == "__main__":
    main()
