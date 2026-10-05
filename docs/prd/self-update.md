# PRD: The Engine executable updates itself

Source: `/clarify` of 2026-10-05, design chosen on 2026-10-05 from four
competing designs.

## Problem Statement

A reader who installed `drupack` with `install.sh` or `install.ps1` learns of a
new release only from GitHub. Updating means running the install script again
by hand. Nothing tells a reader that their copy is behind.

## Solution

`drupack self-update` replaces the running Engine executable with the newest
release, after checking its SHA-256. `drupack self-update --check` reports
without replacing anything.

An Engine run checks for a new release at most once a day, in a detached child,
and prints one line on later runs while a newer release exists. The check stays
off in CI, without a terminal, or when the reader turns it off.

A site executable and a copy installed by Composer refuse the word, and each
names where its update comes from.

## User Stories

1. As a reader, I want `drupack self-update` to install the newest release over my copy, so that I need not rerun the install script.
2. As a reader, I want `drupack self-update --check` to tell me whether a newer release exists, without changing my copy.
3. As a reader, I want a start to tell me in one line when a newer release exists, so that I learn of it without looking.
4. As a reader, I want that line to cost no wait, so that a start stays as fast as today.
5. As a reader, I want the check off in CI and in scripts, so that a pipeline's output stays the same.
6. As a reader, I want `DRUPACK_NO_UPDATE_CHECK=1` to turn the check off, so that a machine with no network stays quiet.
7. As a reader, I want a download that fails its SHA-256 to leave my copy untouched.
8. As a reader, I want a copy in a directory I cannot write refused before any download, with the directory named.
9. As a reader on musl Linux, I want the update to install the musl build, the one I run.
10. As a reader on Windows, I want the update to work while `drupack.exe` runs, and the old file gone by a later run.
11. As a reader behind a re-signing proxy, I want `DRUPACK_CA_FILE` to cover the update as it covers PHP.
12. As a reader of a site executable, I want `SITE self-update` to say that the site's publisher ships its new release.
13. As a Composer user, I want `./drupack self-update` to name the two Composer commands, so that my lock and my copy stay in step.
14. As a reader running a local `dev` build, I want `self-update` refused, so that a release never replaces my own build silently.
15. As a reader with a newer prerelease than the latest release, I want no downgrade offered.
16. As a maintainer, I want the replace, the checksum and the version order under Go unit tests.

## Implementation Decisions

The word:

- D1. `launcher/update.go`, package main, holds the feature. The release URL
  `https://github.com/tresbientech/drupack/releases/latest/download/release.json`
  is one constant there. Functions take the URL as a parameter.
- D2. `run()` handles `self-update` before routing, beside `detach`. A word
  `runtimeArguments` would otherwise send to `serve.php`, which reads it as `DIR`.
  It takes `--check` alone, as `clean` takes `--dry-run`.
- D3. Refusals run before any network call, in this order:
  - a site executable (`!engine`) names the Drupack engine and the site's
    publisher, exit 1
  - a copy beside `vendor/drupal/drupack` names `composer update drupal/drupack`
    then `vendor/bin/drupack-install`, exit 1
  - a `dev` build says self-update replaces releases alone, exit 1
- D4. The word reads `release.json` (`version`, `assets[]` with `name`,
  `target`, `url`, `sha256`, `size`) and picks the asset named `drupack` for this
  file's target. A missing target lists the targets the release has.

Replacing the file:

- D5. The download goes to `os.CreateTemp` in the executable's directory, hashed
  as it is written. A temp file that cannot be created refuses with the
  directory named. A SHA-256 or size mismatch removes the temp file and fails.
- D6. `replaceExecutable(path, staged)` lives in `launch_unix.go` and
  `launch_windows.go`. Unix renames over the executable. Windows renames it to
  `PATH.old`, renames the new file in, and renames back if that fails.
- D7. An Engine run removes `PATH.old` before anything else, ignoring a missing
  file.

Deciding newer:

- D8. `newer(candidate, current)` compares `X.Y.Z` numerically. A release
  outranks any prerelease of it. Prerelease parts compare digit runs as numbers,
  so `alpha10` follows `alpha3`. An older or equal candidate is never offered.
- D9. `launcher/cmd/pack` takes `-musl` and stamps `musl bool` in the payload.
  `drupack-build` and the musl pack in `release.yml` pass it. The target is
  `GOOS-GOARCH`, with `macos` for `darwin`, plus `-musl` when stamped. This
  matches `build.Target`.
- D10. HTTPS uses the system roots plus the bundle `DRUPACK_CA_FILE` names.

The background check:

- D11. An Engine run that reaches the runtime calls `updateNotice` after
  `runtime.Root`. It returns at once when `CI` is set, when
  `DRUPACK_NO_UPDATE_CHECK=1`, or when stderr is no terminal.
- D12. `<cache root>/update-check` holds the newest version seen. Its mtime is
  the last check. A version newer than this file's prints one stderr line:
  the new version, this version, and `Run: drupack self-update`. The line
  repeats on each run until the reader updates.
- D13. A missing file, or an mtime older than 24 hours, makes `updateNotice`
  touch the file, then start `self-update --check` with no stdio under
  `detachedProcess()`, unwaited. The touch throttles failures and concurrent
  starts.
- D14. `--check` fetches with a 5 second timeout and writes the newest version
  to `update-check` through a temp file and a rename. It prints the result and
  exits 0, newer or not. A refusal from D3 still exits 1.
- D15. The old runtime's cache entry goes through the existing sweep, which
  skips the plain `update-check` file.

Docs:

- D16. `docs/cli.md` gains `self-update`, `--check`, `DRUPACK_NO_UPDATE_CHECK`
  and the check's off cases. `serve.php` USAGE lists the word. ADR 0031 records
  the self-update decisions, and ADR 0030 lists `self-update` among the words
  the launcher keeps.

## Testing Decisions

A good test builds the release a server would publish, runs the word against
it, and reads the file, the output and the exit.

Go unit tests in `launcher/update_test.go`, against `httptest` servers:

- `newer`: `alpha10` over `alpha3`, a release over its prerelease, equal,
  older, and `0.9.0` against `1.0.0-alpha3`
- the target for each `GOOS`, `GOARCH` and musl stamp, and a missing target
- a matching download replaces the file and keeps it executable
- a wrong SHA-256 leaves the file byte-identical and no temp file behind
- an unwritable directory refuses before a request, skipped as root
- the three refusals of D3
- `--check` writes `update-check` and leaves the executable alone
- `updateNotice`: each off case, a fresh file sends no request, a stale one
  spawns, a newer cached version prints the line

`launcher/cmd/pack` tests assert the `-musl` stamp. Conformance cases in
`engine_cases.py` run offline: a site executable's refusal, and the Composer
refusal beside a `vendor/drupal/drupack` fixture. `route_test.go` is the prior
art for table tests in package main.

## Considered options

- One file with glibc or musl detected at runtime from the loader path. A third
  copy of the test `install.sh` and `drupack-install` already carry.
- A `launcher/internal/update` package with a hidden `update-check` word. The
  same behaviour split into a package and glue, with a word only the launcher
  types.
- A `Source` type with seams for GitLab, Gitea and tokens. `release.json` is
  host-neutral, so another host changes only the URL.
- No child process: a goroutine inside the `detach` relay. Foreground starts
  never check, and the line prints on the same start, not the next.

## Out of Scope

- Site executables updating themselves, and sources other than GitHub.
- A signature on `release.json`. The checksum and TLS come from one origin.
- Updating a Composer copy.
