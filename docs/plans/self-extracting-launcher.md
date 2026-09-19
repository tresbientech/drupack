# Plan: Self-extracting launcher

> Source PRD: [docs/prd/self-extracting-launcher.md](../prd/self-extracting-launcher.md)
> Source RFC: [docs/rfc/self-extracting-launcher.md](../rfc/self-extracting-launcher.md)

## Architectural decisions

These hold across every phase.

- Payload: a tar stream compressed with `github.com/klauspost/compress/zstd` at
  its best level, carried by `go:embed`. No external compressor at build time.
- Manifest: JSON with a version, the entry executable's name, and a path, size
  and SHA-256 per file. Also carried by `go:embed`, and written into the cache
  entry at staging.
- Cache layout: `<os.UserCacheDir()>/Drupack/runtime/`, holding one directory per
  cache key, an `active` file naming the current key, and a `lock` file.
- Cache key: the version plus a prefix of the payload digest, so two builds that
  share the version string `dev` stay separate.
- The launcher is a Go module under `packaging/launcher`, with `go.mod` and
  `go.sum`. The repository pins every other download by checksum, and `go.sum`
  does the same for the one dependency. The build fetches it, so no vendor
  directory enters the tree.
- Modules: Cache, Extractor, Packer, Launch. Launch splits by platform and holds
  no cache logic.
- Launch on Linux and macOS: `syscall.Exec`, with `argv[0]` set to the path the
  reader invoked.
- Launch on Windows: a child process with `PHPRC` set, the console-ownership
  check kept, and the child's exit code returned.
- Environment: `DRUPACK_CACHE_DIR` overrides the cache root. An unwritable root
  falls back to `TMPDIR`.
- Release assets keep their versioned names, their checksums file and
  `release.json`. Each asset also ships without its version, under a second
  `checksums.txt` line and a `latest_url` field, so the README can point at
  `releases/latest/download`. Nothing else about asset naming changes.

The suite for every phase is the CONTRIBUTING.md list, run against a built
executable. Phases 1 and 2 run the Linux suites. Phase 3 adds the macOS subset.
Phase 4 adds the two Pester suites.

---

## Phase 1: Linux launcher, cold and warm start

**User stories**: 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 16, 17, 18, 21, 23, 24, 25, 26, 27

### What to build

A Linux download that runs. The published executable carries the compressed
runtime. Its first run prints one line to standard error, unpacks into the user's
cache, and replaces itself with the unpacked executable. Later runs compare the
stored manifest and file sizes, then start.

The Dockerfile's `artifact` target produces the launcher, built from the
`uncompressed` target's output. UPX leaves with everything that served it.

### Acceptance criteria

- [ ] `docker build --target artifact --output type=local,dest=dist .` produces an
      executable under 125 MB.
- [ ] A first run of that executable prints one line naming the version to
      standard error, and standard output carries nothing new.
- [ ] `drupack --help` on a warm cache runs within 0.1 s of the same command on
      the `uncompressed` build, measured over five runs.
- [ ] The five suites named in CONTRIBUTING.md pass against the launcher:
      `database-init.sh`, `offline.sh`, `network.sh`, `server-database.sh`,
      `replacement.sh`.
- [ ] `tests/launcher.sh` passes, covering a cold start, a warm start, two cold
      starts at once producing one cache entry, a non-zero exit code from a
      failing `dr` command, and SIGINT during a serve stopping the server.
- [ ] `cd packaging/launcher && go test ./...` passes, covering a warm start that skips
      staging, a changed file size forcing a re-stage, two concurrent stages
      producing one runtime, a manifest path holding `..` or an absolute path
      refused, a payload entry the manifest does not declare refused, and a
      checksum mismatch leaving the target untouched.
- [ ] `ps` shows a running site as the `uncompressed` build does: one process and
      one readiness helper. Its command line names the cache entry's executable,
      because `runtime/launch.php` re-executes through `DRUPACK_RUNTIME_BINARY`
      and `pcntl_exec` takes `argv[0]` from the path it runs. The process count
      and the signal behaviour do not change.
- [ ] `grep -ri upx` over the repository returns only `docs/`.
- [ ] `packaging/align-segments.php` and the `DRUPACK_KEEP_ALIGNMENT` build
      argument no longer exist.

---

## Phase 2: Cache placement and recovery

**User stories**: 12, 13, 14, 15, 22

### What to build

The cache survives a hostile filesystem and does not grow without limit. A reader
can move it. A home directory that refuses writes sends the runtime to the
temporary directory. A cache root that cannot hold the runtime produces a message
naming the path and the space needed. A successful start removes the cache
entries of other versions. A stage that fails while a previously installed
version is present runs that version and warns.

### Acceptance criteria

- [ ] `DRUPACK_CACHE_DIR=<path> drupack --help` unpacks under `<path>` and leaves
      `os.UserCacheDir()` untouched.
- [ ] With `HOME` set to a read-only directory and `DRUPACK_CACHE_DIR` unset, the
      site starts and the runtime lands under `TMPDIR`.
- [ ] A cache root on a full filesystem produces an error naming the path, and the
      process exits non-zero without writing Site data.
- [ ] Starting a second version removes the first version's cache directory, and
      the `active` file names the second.
- [ ] A corrupted payload with a valid previously installed version starts that
      version and writes a warning to standard error.
- [ ] `tests/launcher.sh` covers each case above.
- [ ] The five Linux suites still pass.

---

## Phase 3: macOS

**User stories**: 1, 19

### What to build

`packaging/macos/build.sh` ends by calling the packer, so the macOS asset is a
launcher instead of the 361 MB executable. The macOS job's suites run against it.

### Acceptance criteria

- [ ] `bash packaging/macos/build.sh application <work> dist/drupack` produces an
      executable under 125 MB.
- [ ] `tests/database-init.sh`, `tests/browser.py` and `tests/replacement.sh` pass
      against it on macOS.
- [ ] The release workflow's unsigned-launch check passes: a copy carrying the
      quarantine attribute, with the attribute removed, runs `--help`.
- [ ] A cold start on macOS unpacks under `~/Library/Caches/Drupack/runtime`.
- [ ] The README's macOS instructions still describe what happens.

---

## Phase 4: Windows convergence

**User stories**: 20, 23

### What to build

Windows uses the same launcher. `packaging/windows/build.ps1` calls the packer
with the runtime directory it already assembles. The Windows launch path lives in
the shared program: a child process with `PHPRC` set, the console-ownership check
that keeps a double-clicked window open, and the child's exit code.

`packaging/windows/main.go` and `packaging/windows/package.ps1` are deleted.

### Acceptance criteria

- [ ] `packaging/windows/build.ps1` produces `drupack.exe` under 135 MB. The
      0.1.1 asset measured 141,409,280 bytes on the old zip launcher, and the
      Linux launcher measured 123,117,730 bytes.
- [ ] `tests/windows/launcher.Tests.ps1` passes against the new payload format,
      including its concurrency and liveness cases.
- [ ] `tests/windows/site.Tests.ps1` passes against the built executable.
- [ ] A double-click on a failing executable leaves the console window open until
      Enter.
- [ ] `drupack.exe dr ... status` returns Drush's exit code.
- [ ] `packaging/windows/main.go` and `packaging/windows/package.ps1` no longer
      exist, and no PowerShell file references them.
- [ ] A cold start unpacks under `%LOCALAPPDATA%\Drupack\runtime`.
