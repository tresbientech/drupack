#!/usr/bin/env python3
"""Moves the PHP, FrankenPHP, Composer and Go pins to their newest version inside their line, moves the
CA bundle to curl's newest extract, moves libaom to its newest release tag and SQLite to its newest release, and
records the release, file and SHA-256 of each source static-php-cli downloads for the builds.
It prints what it did as a Markdown summary. The executables carry all of these, so a move of one starts a release.
The Go check tools and the launcher's Go modules move only alongside such a move. Go's line is its own or the one
FrankenPHP needs, whichever is newer. It edits the working tree and never commits.

static-php-cli is third-party code. One command picks the PHP, FrankenPHP and SQLite pins in its own checkout,
downloads on them what the builds compile, and prints its answers. The other takes them as data:

    python3 build/release/upstream.py --probe > ANSWERS
    python3 build/release/upstream.py --answers ANSWERS [--output FILE]
"""

import argparse
import base64
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
import urllib.request
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BUILDER_INPUTS = "runtime/builder/builder-inputs.sh"
WINDOWS_BUILD = "build/windows/build.ps1"
RELEASE_WORKFLOW = ".github/workflows/release.yml"
LAUNCHER = "launcher"
LAUNCHER_MOD = "launcher/go.mod"
LAUNCHER_SUM = "launcher/go.sum"
GO_MODS = (LAUNCHER_MOD, "runtime/frankenphp/watch/go.mod")
DOCKERFILE = "Dockerfile"
CA_BUNDLE = "runtime/cacert.pem"
LIBRARY_VERSIONS = "runtime/builder/library-versions.txt"
BRANCH_PINS = "runtime/builder/branch-pins.txt"
# gitiles prefixes each JSON answer with this line, which stops a script include of it.
GITILES_PREFIX = ")]}'\n"
FETCH_SPC = "runtime/builder/fetch-spc.sh"
EXTENSIONS_LIST = "runtime/extensions/extensions-list.sh"
# The builders hand static-php-cli the first list as extensions and the second as libraries.
EXTENSION_LISTS = ("runtime/extensions/php-extensions.txt", "runtime/extensions/php-extension-libs.txt")

PHP_RELEASES = "https://www.php.net/releases/index.php?json&version="
FRANKENPHP_API = "https://api.github.com/repos/php/frankenphp"
# static-php-cli v3 has no tagged release. Its branch head moves with each FrankenPHP
# bump, since FrankenPHP's own builder runs that branch's nightly.
SPC_BRANCH = "https://api.github.com/repos/crazywhalecc/static-php-cli/branches/v3"
FRANKENPHP_RAW = "https://raw.githubusercontent.com/php/frankenphp"
GO_RELEASES = "https://go.dev/dl/?mode=json&include=all"
DOCKER_AUTH = "https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/golang:pull"
DOCKER_MANIFESTS = "https://registry-1.docker.io/v2/library/golang/manifests/"
GO_PROXY = "https://proxy.golang.org/"
COMPOSER_VERSIONS = "https://getcomposer.org/versions"
COMPOSER_DOWNLOAD = "https://getcomposer.org/download/"
# Composer's Tags Public Key, from https://composer.github.io/pubkeys.html.
COMPOSER_KEY = "build/release/composer-releases.pub"
CA_RELEASE = "https://curl.se/ca/cacert.pem"
CA_CHECKSUM = CA_RELEASE + ".sha256"
SQLITE_DOWNLOADS = "https://www.sqlite.org/download.html"
WATCHER_RELEASES = "https://api.github.com/repos/e-dant/watcher/releases/tags/"
# The Windows build downloads this file of a watcher release. The other builds compile the watcher.
WATCHER_ASSET = "x86_64-pc-windows-msvc.tar"
SQLITE_PATCH = "runtime/builder/spc-sqlite.patch"

COMMIT = r"[0-9a-f]{40}"
SHA256 = r"[0-9a-f]{64}"
# A downloaded file's name in static-php-cli's downloads folder, with no path in it.
FILE_NAME = re.compile(r"[A-Za-z0-9][A-Za-z0-9._+-]{0,127}")
# The musl set holds every source the Linux, macOS and Windows builds share.
SPC_TARGET = "x86_64-linux-musl"
# Each pin's lines in its file, their values captured.
PHP_PIN = r"^php_version=(.+)$"
FRANKENPHP_PIN = r"^frankenphp_version=(.+)\nfrankenphp_commit=(.+)$"
SPC_PIN = r"^spc_commit=(.+)$"
GO_PIN = r"^  GO_VERSION: '(.+)'$"
WATCHER_PIN = (rf"^\$watcherUrl = 'https://github\.com/e-dant/watcher/releases/download/(.+)/{re.escape(WATCHER_ASSET)}'\n"
               rf"\$watcherSha256 = '({SHA256})'$")
# A sqlite-autoconf release as its path under sqlite.org: the year, then the release in seven digits.
SQLITE_PATH = r"\d{4}/sqlite-autoconf-\d{7}\.tar\.gz"
# The SQLite release the patch gives static-php-cli.
SQLITE_PIN = rf"^\+    source: 'https://www\.sqlite\.org/({SQLITE_PATH})'$"
# The line of sqlite.org's product list that names the newest sqlite-autoconf: its release and its path.
SQLITE_PRODUCT = rf"^PRODUCT,(\d+\.\d+\.\d+),({SQLITE_PATH}),\d+,[0-9a-f]+$"
GO_LINE = r"^go (\d+\.\d+(?:\.\d+)?)$"
GO_IMAGE_PIN = rf"^ARG JOB_BASE=golang:(.+)-bookworm@sha256:({SHA256})$"
COMPOSER_PIN = (r"^RUN wget -q -O /opt/composer\.phar https://getcomposer\.org/download/(.+)/composer\.phar \\\n"
                rf"    && echo '({SHA256})  /opt/composer\.phar' \| sha256sum -c -$")
CA_DATE = r"^## Certificate data from Mozilla as of: (.+) GMT$"
CA_PIN = rf"^# runtime/cacert\.pem comes from \S+, sha256\n# ({SHA256}), (\d+)$"
# The libaom line of BRANCH_PINS: its URL, its tag and the commit the tag names.
LIBAOM_PIN = rf"^libaom all (\S+) (\S+) ({COMMIT})$"
GO_REQUIREMENT = r"^\t(\S+) (v\S+)(?: // indirect)?$"
GO_RELEASE = re.compile(r"go\d+\.\d+\.\d+")
# Each Go tool the checks install as MODULE/cmd/NAME@vX.Y.Z: its name, its module and the
# file pinning it. The module path names the major, so its newest release stays inside it.
GO_TOOLS = (
    ("golangci-lint", "github.com/golangci/golangci-lint/v2", "build/test/go-tests.sh"),
    ("govulncheck", "golang.org/x/vuln", RELEASE_WORKFLOW),
)
STABLE_TAG = re.compile(r"v\d+\.\d+\.\d+")
# An artifact's name or release in static-php-cli's words, such as postgresql and REL_18_7.
# Each reaches the versions file, the commit message, the release notes and a URL of the Windows build.
ARTIFACT_WORD = re.compile(r"[A-Za-z0-9][A-Za-z0-9._+-]{0,63}")
PACKAGIST_METADATA = "https://repo.packagist.org/p2/"

RELEASE = re.compile(r"v?(\d+)\.(\d+)\.(\d+)(?:-(alpha|beta|rc)(\d+))?", re.IGNORECASE)
STABILITY = {"alpha": 0, "beta": 1, "rc": 2, None: 3}


def download(url):
    request = urllib.request.Request(url)
    # A GitHub runner's token lifts the API's per-address rate limit. urllib copies a plain
    # header to a redirect's target, which may be another host.
    if url.startswith("https://api.github.com/") and os.environ.get("GITHUB_TOKEN"):
        request.add_unredirected_header("Authorization", f"Bearer {os.environ['GITHUB_TOKEN']}")
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
    return tuple(int(part) for part in version.removeprefix("v").split("."))


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

    def moves(self, source, current, newest):
        """Writes the bumped row when `newest` is newer than `current`, else the current row, and says which."""
        moved = version_key(newest) > version_key(current)
        self.row(source, current, newest, "bumped" if moved else "current")
        return moved

    def moved(self):
        return any(action == "bumped" for *_, action in self.rows)

    def render(self):
        lines = ["| Source | Current | Found | Action |", "|---|---|---|---|"]
        lines += [f"| {' | '.join(row)} |" for row in self.rows]
        return "\n".join(lines + [""] + self.notes) + "\n"


def bumped(rendered):
    """The source, old value and new value of each row a rendered summary marks bumped."""
    rows = (line.strip("| ").split(" | ") for line in rendered.splitlines() if line.startswith("| "))
    return [tuple(row[:3]) for row in rows if row[3:] == ["bumped"]]


def read(path, pattern):
    """What the one match of `pattern` in `path` captures: a value, or a tuple of several."""
    values = re.findall(pattern, (ROOT / path).read_text(), re.MULTILINE)
    if len(values) != 1:
        sys.exit(f"{path}: {pattern} matches {len(values)} times, not once")
    return values[0]


def rewrite(path, pattern, *values):
    """Replaces each value the one match of `pattern` in `path` captures, in order, with `values`."""
    file = ROOT / path
    text = file.read_text()
    matches = list(re.finditer(pattern, text, re.MULTILINE))
    if len(matches) != 1:
        sys.exit(f"{path}: {pattern} matches {len(matches)} times, not once")
    # The last group first, so the spans before it stay valid.
    for group, value in reversed(list(enumerate(values, 1))):
        text = text[:matches[0].start(group)] + value + text[matches[0].end(group):]
    file.write_text(text)


def newest_in_line(summary, source, line, versions, current):
    """The newest of `versions` inside `line`, or `current`. The newest of all, outside the line, gets a note."""
    above = max(versions, key=version_key, default=current)
    if not above.startswith(line + "."):
        summary.notes.append(f"MAJOR {source} {above} is out, outside the {line} line")
    return max((v for v in versions if v.startswith(line + ".")), key=version_key, default=current)


def newest_php(line):
    # php.net answers for any version line it knows; its answer is remote input.
    version = json.loads(fetch(PHP_RELEASES + line))["version"]
    if not re.fullmatch(rf"{re.escape(line)}\.\d+(\.\d+)?", version):
        sys.exit(f"php.net named {version!r} as the newest PHP {line}")
    return version


def php(summary):
    current = read(BUILDER_INPUTS, PHP_PIN)
    line = ".".join(current.split(".")[:2])
    newest = newest_php(line)
    # The major's answer feeds the note alone; the line's answer is the pick.
    newest_in_line(summary, "PHP", line, [newest_php(line.split(".")[0])], current)
    if summary.moves("PHP", current, newest):
        rewrite(BUILDER_INPUTS, PHP_PIN, newest)


def composer(summary):
    current = read(DOCKERFILE, COMPOSER_PIN)[0]
    # getcomposer.org's version list is remote input.
    stable = [entry.get("version") for entry in json.loads(fetch(COMPOSER_VERSIONS)).get("stable", [])]
    if not stable or not all(isinstance(v, str) and re.fullmatch(r"\d+\.\d+\.\d+", v) for v in stable):
        sys.exit(f"getcomposer.org lists the stable Composer releases {stable!r}")
    newest = newest_in_line(summary, "Composer", current.split(".")[0], stable, current)
    if not summary.moves("Composer", current, newest):
        return
    phar = download(f"{COMPOSER_DOWNLOAD}{newest}/composer.phar")
    verify_composer(newest, phar, fetch(f"{COMPOSER_DOWNLOAD}{newest}/composer.phar.sig"))
    rewrite(DOCKERFILE, COMPOSER_PIN, newest, hashlib.sha256(phar).hexdigest())


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
    current = read(BUILDER_INPUTS, FRANKENPHP_PIN)[0]
    releases = [release["tag_name"] for release in json.loads(fetch(f"{FRANKENPHP_API}/releases?per_page=100"))
                if not release["draft"] and not release["prerelease"]]
    # GitHub release tags are remote input.
    versions = [tag[1:] for tag in releases if STABLE_TAG.fullmatch(tag)]
    newest = newest_in_line(summary, "FrankenPHP", current.split(".")[0], versions, current)
    if not summary.moves("FrankenPHP", current, newest):
        return
    target = json.loads(fetch(f"{FRANKENPHP_API}/git/ref/tags/v{newest}"))["object"]
    if target["type"] == "tag":
        target = json.loads(fetch(f"{FRANKENPHP_API}/git/tags/{target['sha']}"))["object"]
    if target["type"] != "commit" or not re.fullmatch(COMMIT, target["sha"]):
        sys.exit(f"FrankenPHP v{newest} names {target!r}, not a commit")
    rewrite(BUILDER_INPUTS, FRANKENPHP_PIN, newest, target["sha"])
    spc_current = read(BUILDER_INPUTS, SPC_PIN)
    # The branch head is remote input.
    spc_head = json.loads(fetch(SPC_BRANCH))["commit"]["sha"]
    if not re.fullmatch(COMMIT, spc_head):
        sys.exit(f"static-php-cli v3 names {spc_head!r}, not a commit")
    rewrite(BUILDER_INPUTS, SPC_PIN, spc_head)
    summary.row("static-php-cli", spc_current[:12], spc_head[:12], "current" if spc_head == spc_current else "bumped")


def go(summary):
    current = read(RELEASE_WORKFLOW, GO_PIN)
    frankenphp_commit = read(BUILDER_INPUTS, FRANKENPHP_PIN)[1]
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
    if not summary.moves("Go", current, newest):
        return
    digest = golang_digest(newest)
    rewrite(RELEASE_WORKFLOW, GO_PIN, newest)
    for path in GO_MODS:
        rewrite(path, GO_LINE, newest)
    rewrite(DOCKERFILE, GO_IMAGE_PIN, newest, digest)


def golang_digest(version):
    """The digest of the multi-platform index Docker Hub serves as golang:VERSION-bookworm."""
    # Docker Hub's token and digest are remote input.
    token = json.loads(fetch(DOCKER_AUTH)).get("token")
    if not isinstance(token, str) or not token:
        sys.exit("Docker Hub gave no pull token for library/golang")
    digest = head(f"{DOCKER_MANIFESTS}{version}-bookworm", {
        "Authorization": f"Bearer {token}",
        "Accept": "application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json",
    }).get("Docker-Content-Digest")
    if not isinstance(digest, str) or not re.fullmatch(rf"sha256:{SHA256}", digest):
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
        if summary.moves(name, current, version[1:]):
            rewrite(path, pattern, version[1:])


def ca_date(text):
    """The date of the Mozilla data a CA bundle holds."""
    dates = re.findall(CA_DATE, text, re.MULTILINE)
    if len(dates) != 1:
        sys.exit(f"a CA bundle names the dates {dates!r}")
    try:
        return datetime.strptime(dates[0], "%a %b %d %H:%M:%S %Y")
    except ValueError:
        sys.exit(f"a CA bundle names the date {dates[0]!r}")


def ca_bundle(summary):
    current = ca_date((ROOT / CA_BUNDLE).read_bytes().decode())
    # curl.se's bundle and checksum are remote input.
    bundle = download(CA_RELEASE)
    checksum = fetch(CA_CHECKSUM).partition(" ")[0]
    if hashlib.sha256(bundle).hexdigest() != checksum:
        sys.exit(f"{CA_RELEASE} does not match the checksum {checksum!r}")
    text = bundle.decode()
    newest = ca_date(text)
    certificates = text.count("-----BEGIN CERTIFICATE-----")
    if certificates == 0 or certificates != text.count("-----END CERTIFICATE-----"):
        sys.exit(f"{CA_RELEASE} holds no whole list of certificates")
    versions = current.date().isoformat(), newest.date().isoformat()
    if newest <= current:
        summary.row("CA bundle", *versions, "current")
        return
    (ROOT / CA_BUNDLE).write_bytes(bundle)
    rewrite(DOCKERFILE, CA_PIN, checksum, str(certificates))
    summary.row("CA bundle", *versions, "bumped")


def run_go(*arguments):
    # Standard output carries the summary.
    subprocess.run(["go", *arguments], cwd=ROOT / LAUNCHER, check=True, stdout=sys.stderr)


def go_requirements():
    return dict(re.findall(GO_REQUIREMENT, (ROOT / LAUNCHER_MOD).read_text(), re.MULTILINE))


def go_modules(summary):
    """Moves the launcher's modules inside their majors."""
    files = {path: (ROOT / path).read_bytes() for path in (LAUNCHER_MOD, LAUNCHER_SUM)}
    before = go_requirements()
    line = read(LAUNCHER_MOD, GO_LINE)
    run_go("get", "-u", "-t", "./...")
    run_go("mod", "tidy")
    needed = read(LAUNCHER_MOD, GO_LINE)
    if needed != line:
        for path, content in files.items():
            (ROOT / path).write_bytes(content)
        summary.row("Go modules", line, needed, "waits for that Go")
        return
    for module, version in sorted(go_requirements().items()):
        if before.get(module) != version:
            summary.row(module, before.get(module, "none"), version, "bumped")


def sqlite_release(path):
    """The release a sqlite-autoconf path names: 3540100 is 3.54.1."""
    return ".".join(str(int(part)) for part in re.search(r"-(\d)(\d\d)(\d\d)\d\d\.tar\.gz$", path).groups())


def sqlite(summary):
    """Moves the SQLite release spc-sqlite.patch gives static-php-cli, inside the 3 line."""
    current = sqlite_release(read(SQLITE_PATCH, SQLITE_PIN))
    # sqlite.org's product list is remote input, and its release must be the one its path names.
    products = re.findall(SQLITE_PRODUCT, fetch(SQLITE_DOWNLOADS), re.MULTILINE)
    if len(products) != 1 or sqlite_release(products[0][1]) != products[0][0]:
        sys.exit(f"sqlite.org lists the sqlite-autoconf releases {products!r}")
    newest, path = products[0]
    if summary.moves("SQLite", current, newest_in_line(summary, "SQLite", "3", [newest], current)):
        rewrite(SQLITE_PATCH, SQLITE_PIN, path)


def run_spc(directory, *arguments):
    """What the pinned static-php-cli prints for `arguments`. The first call checks it out into `directory`."""
    if not (directory / "bin" / "spc").exists():
        # Standard output carries the summary.
        subprocess.run(["bash", str(ROOT / FETCH_SPC), str(directory)], check=True, stdout=sys.stderr)
        subprocess.run(["composer", "install", f"--working-dir={directory}", "--no-dev", "--no-interaction", "--prefer-dist",
                        "--no-scripts", "--no-plugins"], check=True, stdout=sys.stderr)
    # static-php-cli writes its cache and its logs into the directory it runs from.
    return subprocess.run(["php", "bin/spc", *arguments], cwd=directory, check=True, stdout=subprocess.PIPE, text=True,
                          env=os.environ | {"SPC_TARGET": SPC_TARGET}).stdout


def windows_watcher(release):
    """Gives the Windows build the watcher release static-php-cli names for the other builds."""
    # The release's assets are remote input.
    assets = json.loads(fetch(WATCHER_RELEASES + release))["assets"]
    digest = next((asset.get("digest") for asset in assets if asset["name"] == WATCHER_ASSET), None)
    if not isinstance(digest, str) or not re.fullmatch(rf"sha256:{SHA256}", digest):
        sys.exit(f"watcher {release} gives {WATCHER_ASSET} the digest {digest!r}")
    rewrite(WINDOWS_BUILD, WATCHER_PIN, release, digest.removeprefix("sha256:"))


def probe():
    """Picks the PHP, FrankenPHP and SQLite pins in this checkout, then downloads on them what the builds compile.
    It runs third-party code, so upstream.yml calls it in a job that holds no secret and pushes nothing."""
    summary = Summary()
    php(summary)
    frankenphp(summary)
    sqlite(summary)
    extensions, libs = (subprocess.run(["bash", str(ROOT / EXTENSIONS_LIST), str(ROOT / path)], check=True,
                                       stdout=subprocess.PIPE, text=True).stdout.strip() for path in EXTENSION_LISTS)
    packages = ",".join(["php-embed", *(f"ext-{name}" for name in extensions.split(",")), libs])
    picked = pins()
    branch_pins = [line.split()[0] for line in (ROOT / BRANCH_PINS).read_text().splitlines()
                   if not line.startswith("#") and line.split()[1] == "all"]
    with tempfile.TemporaryDirectory() as scratch:
        spc, empty = Path(scratch) / "spc", Path(scratch) / "empty"
        empty.mkdir()
        # A branch pin compiles from the checkout fetch-branch-pins.sh makes, so the download takes an empty folder.
        run_spc(spc, "download", f"--with-php={read(BUILDER_INPUTS, PHP_PIN)}", f"--for-packages={packages}", "--source-only",
                "--no-alt", "--retry=5", *(f"--custom-local={name}:{empty}" for name in branch_pins))
        found = downloads(spc / "downloads")
    return {"pins": picked, "libraries": found}


def pins():
    """The PHP, FrankenPHP, static-php-cli and SQLite pins the probe downloads on, as the pin files hold them."""
    frankenphp_version, frankenphp_commit = read(BUILDER_INPUTS, FRANKENPHP_PIN)
    return {"php": read(BUILDER_INPUTS, PHP_PIN), "frankenphp": frankenphp_version, "frankenphp_commit": frankenphp_commit,
            "spc": read(BUILDER_INPUTS, SPC_PIN), "sqlite": read(SQLITE_PATCH, SQLITE_PIN)}


def downloads(folder):
    """The release, file and SHA-256 of each source static-php-cli downloaded into `folder`, by artifact.
    A local source is a branch pin, which compiles from its own checkout."""
    found = {}
    # static-php-cli writes the cache, so its entries are remote input.
    for name, entry in sorted(json.loads((folder / ".cache.json").read_text()).items()):
        source = entry["source"]
        if source["cache_type"] == "local":
            continue
        if source["cache_type"] not in ("archive", "file"):
            sys.exit(f"static-php-cli fetched {name} as {source['cache_type']}, and branch-pins.txt pins no commit for it")
        if not FILE_NAME.fullmatch(source["filename"]):
            sys.exit(f"static-php-cli stored {name} as {source['filename']!r}, not a file of its downloads folder")
        found[name] = {"release": source["version"] or "-", "file": source["filename"],
                       "sha256": hashlib.sha256((folder / source["filename"]).read_bytes()).hexdigest()}
    return found


def shown(record, hashed):
    """A record's release, or its file when it has none, with its SHA-256 prefix when `hashed`."""
    release, file, sha256 = record
    label = file if release == "-" else release
    return f"{label} (sha256:{sha256[:12]})" if hashed else label


def libraries(summary, found):
    """Records the release, file and SHA-256 of each source in `found`, which probe gave."""
    recorded = {name: tuple(rest) for name, *rest in (line.split() for line in (ROOT / LIBRARY_VERSIONS).read_text().splitlines())}
    # static-php-cli named each artifact and its release, and fetched its file, in another job, so they are remote input.
    if not isinstance(found, dict):
        sys.exit(f"the probe found the sources {found!r}")
    records = {}
    for name, answer in sorted(found.items()):
        if (not ARTIFACT_WORD.fullmatch(name) or not isinstance(answer, dict) or answer.keys() != {"release", "file", "sha256"}
                or not all(isinstance(value, str) for value in answer.values())
                or not (answer["release"] == "-" or ARTIFACT_WORD.fullmatch(answer["release"]))
                or not FILE_NAME.fullmatch(answer["file"]) or not re.fullmatch(SHA256, answer["sha256"])):
            sys.exit(f"the probe found {answer!r} for the artifact {name!r}")
        records[name] = (answer["release"], answer["file"], answer["sha256"])
    for name, new in records.items():
        old = recorded.get(name)
        if name == "watcher" and (old or ("none",))[0] != new[0]:
            windows_watcher(new[0])
        # A source whose release stays and whose file changed shows both hashes.
        hashed = old is not None and old != new and shown(old, False) == shown(new, False)
        summary.row(name, shown(old, hashed) if old else "none", shown(new, hashed), "current" if old == new else "bumped")
    # The file changes, so a release starts, and this row says why.
    for name in sorted(recorded.keys() - records.keys()):
        summary.row(name, shown(recorded[name], False), "none", "removed")
    (ROOT / LIBRARY_VERSIONS).write_text("".join(f"{name} {' '.join(record)}\n" for name, record in records.items()))


def libaom(summary):
    """Moves libaom's line of BRANCH_PINS to the newest release tag gitiles lists, and the commit it names.
    static-php-cli builds libaom from its main branch, so the probe records no release for it."""
    url, current, _ = read(BRANCH_PINS, LIBAOM_PIN)
    answer = fetch(url + "/+refs/tags?format=JSON")
    # The tags and the commits gitiles names are remote input.
    if not answer.startswith(GITILES_PREFIX):
        sys.exit(f"gitiles answers {answer[:40]!r} for libaom's tags")
    tags = json.loads(answer.removeprefix(GITILES_PREFIX))
    if not isinstance(tags, dict):
        sys.exit(f"gitiles lists libaom's tags as {tags!r}")
    newest = max((tag for tag in tags if STABLE_TAG.fullmatch(tag)), key=version_key)
    if not summary.moves("libaom", current, newest):
        return
    entry = tags[newest]
    # An annotated tag names its tag object and peels to the commit; a lightweight tag names the commit.
    commit = (entry["peeled"] if "peeled" in entry else entry.get("value")) if isinstance(entry, dict) else None
    if not isinstance(commit, str) or not re.fullmatch(COMMIT, commit):
        sys.exit(f"gitiles names {entry!r} for libaom {newest}, not a commit")
    rewrite(BRANCH_PINS, LIBAOM_PIN, url, newest, commit)

def bump(summary, answers):
    """Moves every pin, records the sources of `answers`, which probe gave, and returns the suite the candidate runs."""
    if not isinstance(answers, dict):
        sys.exit(f"the probe answered {answers!r}")
    php(summary)
    composer(summary)
    frankenphp(summary)
    go(summary)
    ca_bundle(summary)
    # A move above changes PHP, FrankenPHP or the launcher on every platform, so it tests each in full.
    # The libraries moving alone take the suite a named version takes.
    suite = "full" if summary.moved() else "gate"
    sqlite(summary)
    # The probe runs third-party code, so its pins only confirm the ones this job picked.
    if answers.get("pins") != pins():
        sys.exit(f"the probe downloaded on the pins {answers.get('pins')!r}, and the bump picked {pins()!r}")
    libraries(summary, answers.get("libraries"))
    libaom(summary)
    # upstream.yml releases when this script changed a file. No executable carries the check
    # tools, and the modules move too often to pay for a run on every platform each time.
    if summary.moved():
        tools(summary)
        go_modules(summary)
    return suite


def main():
    parser = argparse.ArgumentParser(description="Moves the pins and prints a Markdown summary.")
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--probe", action="store_true", help="pick the pins in this checkout and print static-php-cli's answers as JSON")
    mode.add_argument("--answers", metavar="FILE", help="the answers --probe printed")
    parser.add_argument("--output", metavar="FILE", help="the file suite=NAME is appended to, a step's $GITHUB_OUTPUT")
    args = parser.parse_args()
    if args.probe:
        print(json.dumps(probe()))
        return
    summary = Summary()
    suite = bump(summary, json.loads(Path(args.answers).read_text()))
    sys.stdout.write(summary.render())
    if args.output:
        with open(args.output, "a") as output:
            output.write(f"suite={suite}\n")


if __name__ == "__main__":
    main()
