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
- The launcher drops the dynamic loader's environment variables from every child's environment, because a glibc file loads host libraries.

### Release files and install scripts

- Every executable ships under its versioned name alone: `NAME-VERSION-TARGET`, with `.exe` on Windows.
- A release also carries `install-NAME.sh` for each executable, and `install-NAME.ps1` when NAME has a Windows build.
- Only `checksums.txt`, `release.json`, the SBOM and the install scripts carry no version in their name.
- A release publishes the macOS and Windows runtimes as `runtime-VERSION-TARGET.tar.zst`. No install script names them.
- A script finds the host's target, downloads that build into the current directory and checks its SHA-256. It then prints the command that starts it there, `./NAME`.
- On Linux the script installs the glibc file when the host runs glibc 2.28 or later. Otherwise it installs the musl file.
- `releases/latest/download/` serves the latest scripts, and `releases/download/VERSION/` pins one.

### Pack

- `-target` is a `targets.json` platform with its libc suffix. The architecture, the libc and the entry name follow from it. A target outside the table stops pack.
- A site whose `site.json` names `node` carries the archive of its target. A missing archive stops pack. A target Node does not build for carries none.

## Reason

An executable stays where its script installed it. The engine is one file per project, in its root, so each project keeps the version it installed. A site executable defaults its Site data to `./data` in the working directory, so a copy on `PATH` would set up a site in every folder it starts from.

UPX packing trips antivirus products and macOS, and a compressed image still needs a writable place before PHP can run. An unpacked cache avoids both.

A glibc runtime serves authenticated pages several times faster than musl, and a musl runtime runs on any host. Two runtimes in one file cost 54 MB for every reader. The install script tests the host and downloads the right file.

winget pins one SHA-256 per URL, so a file name must change with its release. The scripts take the version and checksums at publish time, so a change on `main` never reaches an old release. A script that asked the GitHub API for the latest release would hit its 60 requests an hour per IP.

Pack derives every value from a target and a directory, so a new target changes one table.
