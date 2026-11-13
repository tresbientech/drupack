# A release is self-extracting executables with versioned names and install scripts

## Context

A reader downloads one file and runs it, with no installer and no antivirus warning. Linux hosts differ in their C library. A reader needs a way to pick the right file, and winget needs a URL whose file never changes. The repository's directories must name what they hold.

## Decision

### The launcher

- Each executable is a Go launcher that carries a compressed runtime payload and a manifest naming every file with its size and checksum.
- A start unpacks the payload once per release into a per-user cache, then runs the entry from there. [The cache](0003-the-cache.md) records the rules.

### One runtime per Linux file

- Each Linux target publishes two files. `NAME-VERSION-linux-ARCH` carries the glibc runtime, and `NAME-VERSION-linux-ARCH-musl` carries the musl runtime.
- An executable carries one runtime and runs it. `DRUPACK_LIBC` has no effect on a running executable.
- `libc` in `drupack.yml` takes `both`, `glibc` or `musl`, with `both` as the default. It names the Linux files a build publishes.
- The glibc runtime links with `-z now`.
- The launcher drops `LD_PRELOAD`, `LD_LIBRARY_PATH`, `LD_AUDIT` and `GLIBC_TUNABLES` from every child's environment, because a glibc file loads host libraries.
- `launcher/internal/payload/targets.json` is the one table of platforms and libcs.

### One directory per artifact

- `application/` holds the Application root: the Composer project, the files laid over it, `support/` and the PHP unit tests.
- `runtime/` holds the PHP and FrankenPHP compile and the extension lists.
- `launcher/` is the Go module, and `build/` holds the build scripts and helpers.
- `tests/conformance` holds the conformance suite. `Dockerfile` stays at the root beside its `.dockerignore`.
- `application/tests/` never ships. `build/pack/app-payload.sh` excludes every path component named `tests`.

### Release files and install scripts

- Every executable ships under its versioned name alone: `NAME-VERSION-TARGET`, with `.exe` on Windows.
- A release also carries `install-NAME.sh` for each executable, and `install-NAME.ps1` when NAME has a Windows build.
- Only `checksums.txt`, `release.json`, the SBOM and the install scripts carry no version in their name.
- A release publishes the macOS and Windows runtimes as `runtime-VERSION-TARGET.tar.zst`. No install script names them.
- `build/release/release-files.py` names the files, writes the checksums and the scripts, and stamps in the version, the download URL and each build's SHA-256. `release.yml` and `build.yml` run it.
- A script finds the host's target, downloads that build into the current directory and checks its SHA-256. It then prints the command that moves the file onto PATH.
- On Linux the script installs the glibc file when the host has the glibc program interpreter of its architecture at glibc 2.28 or later. Otherwise it installs the musl file.
- `DRUPACK_LIBC=glibc` or `musl` overrides that test. Another value stops the script.
- `releases/latest/download/` serves the latest scripts, and `releases/download/VERSION/` pins one.

### Pack

- `launcher/cmd/pack` takes `-target`, `-runtime`, `-payload`, `-version` and `-output`, plus `-site-version` and `-engine`.
- `-target` is a `targets.json` platform with its libc suffix. The architecture, the libc and the entry name follow from it. A target outside the table stops pack.
- A payload directory has one layout: the application archive, its checksum, the site's `site.json` and a `node/` directory of archives named by `node.Archive`. An engine payload holds the first two.
- A site whose `site.json` names `node` carries the archive of its target. A missing archive stops pack. A target Node does not build for carries none.
- Pack prints one line per resolved input before it builds.

## Reason

UPX packing trips antivirus products and macOS, and a compressed image still needs a writable place before PHP can run. An unpacked cache avoids both.

A glibc runtime serves authenticated pages several times faster than musl, and a musl runtime runs on any host. Two runtimes in one file cost 54 MB for every reader. The install script tests the host and downloads the right file.

winget pins one SHA-256 per URL, so a file name must change with its release. The scripts take the version and checksums at publish time, so a change on `main` never reaches an old release. A script that asked the GitHub API for the latest release would hit its 60 requests an hour per IP.

Pack derives every value from a target and a directory, so a new target changes one table.
