# Release files carry their version, and install scripts pick one

Accepted on 2026-09-26.

## Context

A release publishes each executable twice, as `NAME-VERSION-TARGET` and as
`NAME-TARGET`. The unversioned copy serves the README's
`releases/latest/download/` links. A reader picks one of five downloads by
hand, clears the macOS quarantine mark, and passes SmartScreen on Windows.

winget pins each installer URL to one SHA-256. A URL whose file changes with
every release cannot serve a manifest.

## Decision

- Every executable ships under its versioned name alone: `NAME-VERSION-TARGET`,
  with `.exe` on Windows.
- A release carries `install-NAME.sh` and `install-NAME.ps1` for each
  executable. Beside `checksums.txt`, `release.json` and the SBOM, they are its
  only files without a version in their name.
- The publish step writes the version, the release's download URL and each
  build's SHA-256 into the scripts. `releases/latest/download/install-NAME.sh`
  installs the latest release, and `releases/download/VERSION/` pins one.
- A script finds the host's target, downloads that build into the current
  directory and checks its SHA-256. It then prints the command that moves the
  file onto PATH.
- `build/release-files.py` names the files, writes `checksums.txt` and
  `release.json`, and writes the scripts. Drupack's release workflow and the
  reusable `build.yml` both run it.

## Considered options

- A script on `main` asking the GitHub API for the latest release. The API
  allows 60 unauthenticated requests an hour per IP, and a conference network
  shares one IP.
- A script on `main` following GitHub's latest-release redirect. A change on
  `main` would then reach every past release.
- One script taking the executable's name as an argument. PowerShell's
  `irm | iex` passes no argument.
- Installing into a user bin directory. The script would edit PATH, which the
  printed move command leaves to the reader.

## Consequences

- A `releases/latest/download/NAME-TARGET` link from before 0.5.0 returns 404
  once 0.5.0 is the latest release.
- `release.json` loses `latest_url`.
- `curl` sets no macOS quarantine mark, and `Invoke-WebRequest` sets no Mark of
  the Web. Neither Gatekeeper nor SmartScreen stops an installed file.
- The GitLab template keeps unversioned names until it runs
  `release-files.py` with its own download URL.
- A winget manifest can name a versioned URL.

## Amendment, 2026-09-27

`drupack.tresbien.tech` redirects short paths to the latest release's scripts:
`/install.sh` and `/install.ps1` to `install-drupack`, and `/demo.sh` and
`/demo.ps1` to `install-mercury-demo`. The README shows these. The redirect
lives in the fleet's NPM, as INFRA's npm ADR 0004 records, and the release
workflow does not depend on it.

## Amendment, 2026-09-30

A release carries `install-NAME.ps1` only when it holds a Windows build of
NAME. A site release built by `build.yml` holds Linux builds alone, and its
PowerShell script had no build to install.
