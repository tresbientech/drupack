# Self-extracting launcher

From [docs/rfc/self-extracting-launcher.md](../rfc/self-extracting-launcher.md).

## Problem Statement

A site owner downloads one executable per platform. Today the macOS download is
361 MB, because `packaging/macos/build.sh` compresses nothing. The Linux download
is 100 MB, packed with UPX.

The Linux build no longer produces a usable file. UPX 5.2.1 writes a corrupt
executable on GitHub runners, and `upx -t` catches it. `upx -9`, `upx -9
--no-lzma`, `upx -1` and a run after `packaging/align-segments.php` all failed
there, and all passed on a development machine. No 0.1.x release has shipped
since.

Windows already avoids UPX. Its executable carries a compressed runtime and
unpacks it on first use, so the download is 113 MB.

## Solution

Every platform downloads one executable of roughly 100 MB, and running it behaves
as it does today.

The first run of a version prints one line, unpacks the runtime into the user's
cache directory, and continues. That unpack takes about two seconds of decoding
plus the disk write. Every later run of that version reads a stored manifest,
compares file sizes, and starts with no measurable delay.

On Linux and macOS the launcher replaces its own process with the unpacked
executable, so the running program keeps the same process id, arguments,
environment, terminal and exit code. On Windows it starts a child, as it does
today, because Windows has no equivalent call.

## User Stories

1. As a site owner on macOS, I want a download near 100 MB, so that a first try
   at Drupal does not cost me 361 MB.
2. As a site owner on Linux, I want the published executable to run, so that a
   release exists at all.
3. As a site owner, I want `drupack` to work the same after this change, so that
   nothing I already learned stops working.
4. As a site owner, I want the first run to say it is unpacking, so that a few
   seconds of quiet does not read as a hang.
5. As a site owner, I want later runs to start immediately, so that the unpack
   cost lands once per version.
6. As a site owner, I want Ctrl-C to stop the server, so that I keep the habit I
   have with every other command.
7. As a site owner, I want a failed command to return its own exit code, so that
   `&&` and `||` behave.
8. As a site owner, I want `drupack dr ... status` to print to my terminal, so
   that Drush output reaches me unchanged.
9. As a site owner, I want the hidden password prompt to keep working, so that my
   administrator password stays off the screen.
10. As a site owner, I want relative paths such as `--data-dir ./site` to resolve
    against my shell's directory, so that nothing moves under me.
11. As a site owner, I want the cache to live under my user account, so that a
    first run needs no administrator rights.
12. As a site owner on a machine with a read-only home directory, I want Drupack
    to fall back to the temporary directory, so that it still runs.
13. As a site owner, I want `DRUPACK_CACHE_DIR` to move the cache, so that I can
    place it on a disk with room.
14. As a site owner, I want a clear message when the cache directory cannot hold
    the runtime, so that I know what to fix.
15. As a site owner who upgrades, I want the old version's cache removed, so that
    disk use does not grow with every release.
16. As a site owner running two sites at once, I want both to start, so that one
    unpack does not block the other.
17. As a site owner, I want two simultaneous first runs to unpack once, so that
    the cache never holds a half-written runtime.
18. As a site owner, I want a truncated or edited cache entry detected, so that
    Drupack refuses to run a damaged runtime.
19. As a site owner on macOS, I want the existing quarantine instructions to
    still apply, so that the README stays correct.
20. As a site owner on Windows, I want the double-click behaviour kept, so that
    the console window stays open on an error.
21. As a script author, I want `ps` and `kill` to see one process, so that my
    supervisor script needs no change.
22. As a container operator, I want the executable to run with a writable home or
    `DRUPACK_CACHE_DIR`, so that an image can place the cache deliberately.
23. As a maintainer, I want one launcher for all three platforms, so that a fix
    lands once.
24. As a maintainer, I want the packer to need no tool beyond Go, so that three
    build environments stay identical.
25. As a maintainer, I want UPX and its workaround removed, so that no dead
    mechanism stays in the tree.
26. As a maintainer, I want the five CI suites to run against the launcher, so
    that a packaging bug fails the build.
27. As a maintainer, I want the cache and extractor covered by Go tests, so that
    a concurrency or corruption case runs in a second and not a build.
28. As a maintainer, I want the release assets to keep their names and checksums
    format, so that the README's download command is untouched.

## Implementation Decisions

One Go program serves all three platforms. It replaces `packaging/windows/main.go`
and `packaging/windows/package.ps1`.

Payload and manifest:

- The payload is a tar stream compressed with `github.com/klauspost/compress/zstd`
  at its best level. On Linux and macOS the tar holds one entry. On Windows it
  holds the PHP DLL tree that `build.ps1` assembles today.
- The manifest is JSON: a version, the entry executable's name, and a path, size
  and SHA-256 for every file. `go:embed` carries both into the launcher.
- The cache key is the version plus a prefix of the payload digest. Two
  development builds that share the version string `dev` get separate cache
  entries.

Modules:

- Cache takes a manifest and a cache root and returns a ready runtime directory.
  It owns the warm-start size check, the lock on the cache root, staging,
  checksum validation, the atomic rename, activation, and the fall back to a
  previously installed version when staging fails.
- Extractor reads the payload into a staging directory. It rejects any entry the
  manifest does not declare and any path that escapes the destination.
- Packer turns a runtime directory into a payload, a manifest and a compiled
  launcher. The Dockerfile, `packaging/macos/build.sh` and
  `packaging/windows/build.ps1` all call it.
- Launch splits by platform. Unix calls `syscall.Exec` with `argv[0]` set to the
  path the reader invoked. Windows starts a child, sets `PHPRC`, keeps the
  console-ownership handling, and returns the child's exit code.

Behaviour:

- The cache root comes from `os.UserCacheDir()`, under `Drupack/runtime`.
  `DRUPACK_CACHE_DIR` overrides it. An unwritable root falls back to `TMPDIR`.
- A cold extraction prints one line to standard error before it starts. Standard
  output carries nothing new.
- A successful start removes cache entries for other versions.

Removed with the mechanism they served: the UPX stage in the Dockerfile,
`packaging/align-segments.php`, the `DRUPACK_KEEP_ALIGNMENT` build argument,
`packaging/windows/package.ps1`, and the compression section of CONTRIBUTING.md.
The Dockerfile's `uncompressed` target becomes the packer's input.

## Testing Decisions

A good test here builds a state and asserts what a caller can observe: the
directory that comes back, the error returned, the bytes on disk. No test reaches
into an unexported helper, and none needs a 361 MB payload.

Go tests cover Cache and Extractor, with byte-sized fixture archives in a
temporary directory:

- a warm start with a matching manifest returns without staging
- a size that changed on disk forces a re-stage
- two goroutines staging at once produce one runtime and no partial directory
- a failed stage falls back to the previously installed version
- a manifest entry with `..` or an absolute path is refused
- a payload entry the manifest does not declare is refused
- a checksum mismatch fails the stage and leaves the target untouched
- an unwritable cache root falls back to the temporary directory

`tests/launcher.sh` covers the Unix end-to-end behaviour, following
`tests/replacement.sh` for shape: a cold start, a warm start, two cold starts at
once, exit-code passthrough, SIGINT during a serve, a read-only home,
`DRUPACK_CACHE_DIR`, and a second version removing the first from the cache.

`tests/windows/launcher.Tests.ps1` exists and moves to the new payload format.
The five CI suites run against the launcher, so each exercises one cold start and
several warm starts.

## Out of Scope

- ADR 0002, which moves `app.tar` out of the FrankenPHP embed. It lands after
  this, as its own change.
- The version record, `drupack update` and the update check, from
  `docs/rfc/dependency-updates-and-compatibility.md`.
- Windows code signing and the Windows tray app.
- Homebrew and WinGet manifests.
- Any change to release asset names, checksums or `release.json`.

## Further Notes

Measured on the 0.1.0 amd64 build, 379,064,720 bytes:

| Method | Payload | Decode in Go |
|---|---|---|
| UPX `-9`, the Linux build today | 100.6 MB | not applicable |
| `klauspost/compress/zstd`, best level | 97.5 MB | 1.84 s |
| zstd CLI `-19 --long=27` | 87.4 MB | 2.05 s |
| `ulikunitz/xz`, default preset | 100.9 MB | 25.47 s |

The Go encoder was chosen over the zstd command line tool. It gives up 10 MB and
keeps three build environments free of an extra dependency. A decode verified
byte for byte against the source sha256.

Decoding ran on 32 cores with four decoder threads. Hardware with fewer cores has
not been measured.
