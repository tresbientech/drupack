# Plan: Install scripts and versioned release files

> Decision: `docs/adr/0023-install-scripts.md`.

## Status, 2026-09-27

Released in 0.5.1. The install cases pass on every release runner. A site
release through `build.yml` published on engine 0.5.5, from
`tresbientech/drupack-mercury-example` 0.1.2.

## Architectural decisions

- `build/release-files.py` turns built executables into a release's files. It
  takes the output directory, the version, the download URL, the build commit
  and the files named `NAME-TARGET`.
- The install scripts are two templates beside it, `install.sh` and
  `install.ps1`. The step writes one copy of each per executable.
- A script installs into the current directory and edits no PATH.
- Drupack's release workflow and `build.yml` publish what the step writes.
  The GitLab template keeps its own step for now.

---

## Phase 1: The step and its scripts

### What to build

The step writes the versioned executables, `checksums.txt`, `release.json` and
the stamped scripts. A conformance case runs the step on the executable under
test, serves the output over local HTTP and runs the script on each OS.

### Acceptance criteria

- [x] The step names each file `NAME-VERSION-TARGET` and refuses a file whose name carries no target.
- [x] `install-NAME.sh` installs the Linux and macOS build, and `install-NAME.ps1` the Windows build, into the current directory.
- [x] A script refuses a download whose SHA-256 differs, and leaves no file.
- [x] A script refuses a host with no build, and names the targets the release has.

---

## Phase 2: Both workflows publish through the step

### What to build

`release.yml` and `build.yml` publish the step's output, and the unversioned
copies go. The README shows the one-liners.

### Acceptance criteria

- [x] A Drupack release lists no unversioned executable, and an `install-NAME` pair for `drupack` and `mercury-demo`.
- [x] A site release through `build.yml` lists the same shape for its executable.
- [x] The README installs both executables with the one-liners, and `CONTRIBUTING.md` and `docs/build-your-site.md` describe the release files.
