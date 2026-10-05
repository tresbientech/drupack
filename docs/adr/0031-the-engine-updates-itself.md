# The engine executable updates itself

Accepted on 2026-10-05.

## Context

A reader who installed `drupack` with an install script learned of a new
release only from GitHub, and updated by running the script again. A release
already publishes `release.json`, which lists each asset with its target, URL,
size and SHA-256.

## Decision

- `drupack self-update` reads `release.json` from GitHub's `latest` link, a
  constant in the launcher. It downloads this file's target beside it, checks
  the size and SHA-256, and renames it over the file. `--check` reports alone.
- The launcher handles the word before routing, so it never reaches `serve.php`.
- A site executable, a Composer copy and a local build refuse before any
  request. Composer and the site's publisher own those updates.
- A version is newer by `X.Y.Z`, then by prerelease, with digit runs compared as
  numbers. A release older than the running file is never installed.
- `pack -musl` stamps a musl file, so an update fetches the build the reader
  runs. The launcher cannot tell its libc at run time.
- Windows moves the running file to `.old`, and a later run removes it.
- Once a day an engine run starts `self-update --check` detached. The
  `update-check` file in the cache root holds the newest version, and its
  modification time is the last check. Each run prints one line while that
  version is newer. The check stays off in CI, without a terminal, with
  `DRUPACK_NO_UPDATE_CHECK=1` and on a local build.

## Considered options

- Detecting glibc or musl at run time from the loader path. That is a third
  copy of the test `install.sh` and `drupack-install` carry, and it can pick a
  build the reader did not install.
- A goroutine inside the `detach` relay in place of a child process.
  Foreground starts would never check.
- A source type for GitLab, Gitea and tokens. `release.json` is host-neutral,
  so another host changes the URL alone.

## Consequences

- The checksum and the asset come from one origin over TLS. No signature
  covers `release.json`.
- A Composer copy and a site executable keep their own update paths.
- A background check writes only the `update-check` file, which the cache
  sweep skips.
