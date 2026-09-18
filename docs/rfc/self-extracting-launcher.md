# A self-extracting launcher replaces UPX

Proposed on 2026-09-18. Not implemented.

## Question

UPX produces a corrupt executable on GitHub runners. Drupack ships one file per
platform at roughly 100 MB. How does it keep that download without UPX?

## Why UPX goes

The 0.1.0 release build failed at `upx -t` with "compressed data violation".
Four methods failed on the runner and passed on a development machine:

- `upx -9`
- `upx -9 --no-lzma`
- `upx -1`
- `upx -9` after `packaging/align-segments.php` rewrote the over-aligned segment

The alignment patch addresses [UPX issue 836](https://github.com/upx/upx/issues/836),
open since 2005. The CI log shows segment 2 realigned and the packed file still
corrupt. A packer whose output depends on the build machine cannot gate a
release.

## Measurements

Taken on the 0.1.0 amd64 build, 379,064,720 bytes before compression.

| Method | Output | Cold cost |
|---|---|---|
| `upx -9` | 100.6 MB | none |
| `xz -9 -T8` | 81.8 MB | 2.7 s to extract |

The extraction ran on 32 cores. `xz -9 -T8` writes a multi-block stream, so
`xz -d -T0` decompresses in parallel. A single-block stream takes about four
times longer at any core count. Four-core hardware has not been measured.

## Design

The published file is a Go program with the real executable embedded, compressed
with xz. Every run does four things.

1. Resolve the cache path: `$XDG_CACHE_HOME/drupack/<version>/drupack` on Linux,
   `~/Library/Caches/Drupack/<version>/drupack` on macOS. `DRUPACK_CACHE_DIR`
   overrides both. An unwritable cache root falls back to `TMPDIR`.
2. `os.Stat` the cached file. A size match jumps to step 4.
3. Otherwise take `<version>/.lock` with `flock`, decompress to
   `drupack.partial.<pid>`, set mode 0755, rename into place. The rename is
   atomic. A second process finds the finished file or waits on the lock.
4. `syscall.Exec` the cached path with the current argv and environment.

The version string comes from the `-ldflags` value the build already sets.

## What the launcher preserves

`syscall.Exec` replaces the process image, so the launcher stops existing at step
4. The PID, argv, environment, open file descriptors, controlling terminal,
process group and working directory all carry over.

- `drupack dr ... status` prints to the terminal and returns Drush's exit code.
- Ctrl-C reaches FrankenPHP directly, with no signal forwarding.
- systemd, Docker and a shell's `$!` see one process.
- The hidden password prompt keeps a real terminal on standard input.

The launcher sets `argv[0]` to the downloaded path. `ps` still shows the cache
path, because `runtime/launch.php` re-executes through `DRUPACK_RUNTIME_BINARY`
and `pcntl_exec` takes `argv[0]` from the path it runs. The process count and
the signal behaviour do not change.

## Costs

- The cache holds 379 MB per version, beside the application each Site data
  directory extracts.
- The first run of each version pays the extraction.
- Old versions stay until something removes them. A successful start deletes
  every cached version except its own.
- A container that mounts the executable read-only needs a writable HOME or
  `DRUPACK_CACHE_DIR`.

## Windows and macOS

Windows already ships this design. `packaging/windows/main.go` embeds
`runtime.zip`, extracts to `%LOCALAPPDATA%\Drupack\runtime\<version>` and starts
a child, because Windows has no `exec`. The new launcher reuses its manifest and
lock handling.

macOS uses the Linux path. The download carries the quarantine attribute, and the
README already tells the reader to remove it.

## Alternatives

- Ship the 379 MB executable. The build drops its compression stage and the
  runtime keeps no cache. Rejected: the download size.
- Gzip each release asset. The reader runs `gunzip` once before the first start.
  Rejected by the owner.
- Embed `app.tar.xz` in place of `app.tar`. `app.tar` holds 247 MB of the 379 MB,
  and the published file stays a real executable. Estimated at 190 MB, above the
  target.
- Keep UPX on an older version. UPX 5.2.1 is current and the bug is 20 years old.
  No release is known to be correct here.

## Relation to ADR 0002

[ADR 0002](../adr/0002-shared-application-extraction.md) proposes taking
`app.tar` out of the FrankenPHP embed and extracting it once per release into the
user cache. Together, the launcher carries two payloads and the cache holds
132 MB plus one application directory in place of 379 MB plus one. The download
weighs the same. The launcher ships first.

## Acceptance checks

- A cold start extracts, serves the site, and leaves the cache at the release
  version.
- A warm start adds no measurable time to `drupack --help`, against the
  uncompressed build.
- Two cold starts at the same moment produce one extraction and two working
  processes.
- A failing `drupack dr` command returns a non-zero exit code.
- Ctrl-C during a serve stops the server and returns the terminal.
- A read-only HOME extracts under `TMPDIR` and serves.
- `DRUPACK_CACHE_DIR` moves the extraction.
- The Alpine container check passes with the executable mounted read-only.
- A second release removes the first release's cache directory on start.

## Open questions

- Whether a file written on macOS inherits the quarantine attribute from the
  process, which would block the extracted copy.
- Whether cache cleanup can remove a version that another process is running.
- Which compression settings the release uses. `-9 -T8` was measured here, and
  `-T0` on a runner changes the block count and the output size.
