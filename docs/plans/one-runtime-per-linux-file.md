# Plan: one runtime per Linux file

> Source PRD: `docs/prd/one-runtime-per-linux-file.md`

## Architectural decisions

- A Linux glibc file keeps the target `linux-ARCH`. A musl file takes
  `linux-ARCH-musl`. macOS and Windows targets stay as they are.
- A packed executable carries one runtime, with one manifest and no recorded
  program interpreter.
- The `libc` contract field keeps `both`, `glibc` and `musl`, default `both`. It
  names the Linux files a build publishes.
- The install script picks the libc on Linux. `DRUPACK_LIBC` accepts `glibc` or
  `musl` and overrides the pick. `drupack-install` runs the same script.
- The builder image per libc and the job image holding both runtimes stay.
- The work ships in its own release, before Bundled Node.

---

## Phase 1: one runtime per packed file

User stories: 5, 6, 7, 11, 13, 14, 16, 17, 18

### What to build

The packer takes one runtime and the launcher runs the one it carries. The
build planner plans one file per platform and libc, for a site and for the
Engine executable. The release workflow packs a glibc file and a musl file per
Linux architecture. The release file lister accepts the `-musl` targets, so
`checksums.txt`, `release.json` and the scripts' build list name them. CI runs
the conformance suite on each glibc file and starts each musl file on Alpine.
The QA chain builds the glibc file it tests.

### Acceptance criteria

- [x] `(cd launcher && go test ./...)` passes, with the planner tests expecting
      one file per platform and libc and the musl names.
- [x] A planner test shows `libc: glibc` and `libc: musl` each plan one Linux
      file per platform, and `both` plans two.
- [x] The release file lister's test, or a run on fixture files, writes
      `NAME-VERSION-linux-amd64-musl` into `checksums.txt` and `release.json`.
- [ ] `--version` on the glibc file names glibc, and on the musl file names
      musl, checked in the release workflow. Pending CI.
- [ ] The glibc Linux file of Mercury Demo measures about 125 MB, printed by
      the release workflow's size step. Pending CI.
- [ ] The release workflow starts the musl file in the Alpine container, which
      answers `/user/login`. Pending CI.
- [ ] `bash build/qa.sh` passes.

---

## Phase 2: install script picks the libc

User stories: 1, 2, 3, 4, 8, 9, 10

### What to build

On Linux the install script tests for the glibc program interpreter of the
host's architecture. It installs the glibc file when the interpreter is
present and the musl file otherwise. `DRUPACK_LIBC` overrides the test, and any
other value stops the script with both accepted values. A release without the
chosen file stops with the builds it has. The checksum check covers both files.
Scripts of earlier releases stay unchanged.

### Acceptance criteria

- [x] The install cases, with the interpreter path pointed at a fixture, show
      the glibc pick, the musl pick, and both overrides.
- [x] An install case shows `DRUPACK_LIBC=gnu` stops before any download and
      names `glibc` and `musl`.
- [x] An install case shows a release without the chosen file stops and lists
      the builds it has.
- [x] An install case shows a musl download whose SHA-256 differs is refused.
- [x] `drupack_install_test.php` passes, and shows `DRUPACK_LIBC` reaches the
      script `drupack-install` runs.

---

## Phase 3: removal sweep, docs and ADR

User stories: 15, 19

### What to build

Every symbol, test, CI step and doc passage that served two runtimes in one
file leaves the repository. That covers the runtime selection cases, the CLI
reference's `DRUPACK_LIBC` passage, the README, CONTRIBUTING, Dockerfile
comments and the build-any-recipe-site PRD's reference. The install docs gain
the musl file and the override. The CONTEXT.md runtime relationships say one
Runtime per file. ADR 0026 records the decision, and ADR 0015 is marked
superseded by it.

### Acceptance criteria

- [ ] `git grep -n -e LibcVariable -e elfInterpreter -e 'runtime glibc=' -e
      runtime_selection -e 'carries both runtimes'` finds only ADR 0015.
- [ ] `git grep -n DRUPACK_LIBC` finds only the install script, its cases, the
      install docs, ADR 0015 and ADR 0026.
- [ ] ADR 0015's status names ADR 0026.
- [ ] `bash build/qa.sh` passes, and `.git/qa-green` records it.
