# Plan: Bundled Node

> Source PRD: `docs/prd/bundled-node.md`. Starts after the one runtime per
> Linux file plan merges.

## Architectural decisions

- The contract field `node` takes `true`, a major number, or
  `MAJOR.MINOR.PATCH`. Absent or `false` means no Node.
- The build resolves the value to one exact version and writes it to
  `site.json` as `node`.
- The Node resolver is one module. It takes the contract value, the targets,
  the release index, the checksum file with its signature, and the keyring kept
  in the repository. It returns the version and one verified archive per target.
- Target map: glibc Linux by architecture, macOS by architecture, Windows x64.
  A musl target carries no Node.
- Node's files travel as a payload beside the runtime, keyed by version, and
  unpack into the cache once per version.
- The site's command line parser owns `node`, `npm` and `npx`, as it owns
  `drush`.
- The Engine executable carries no Node.

---

## Phase 1: contract field and verified resolution

User stories: 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 22

### What to build

The contract parser accepts and checks `node`. The Node resolver picks the
version from the index, maps each target, verifies the checksum file's OpenPGP
signature against the kept keyring, and checks each archive's SHA-256. The
build calls it, prints the chosen version, and records it in `site.json`.
Nothing is packed yet.

### Acceptance criteria

- [x] The contract parser's Go tests cover `true`, a major, an exact version,
      `false`, an absent field, and each refused form with its message.
- [x] The resolver's Go tests use a fixture index, fixture checksum files and a
      test keyring, with no network.
- [x] Those tests cover the three value forms, a version missing from the index,
      a target with no build, a bad signature, an unknown key and a hash
      mismatch.
- [x] `drupack-build` on a fixture site with `node: true` prints the exact
      version and writes it to `site.json`.
- [x] A site without `node` builds a `site.json` with no Node entry, shown by a
      planner test.
- [x] `(cd launcher && go test ./...)` passes.

---

## Phase 2: Node packed and run on glibc Linux

User stories: 12, 13, 14, 15, 16, 21, 23

### What to build

The build unpacks each verified archive and packs its files beside the runtime.
The launcher unpacks Node into the cache on first use, once per version. The
server and `drush` find Node's executable directory first on PATH. `node`,
`npm` and `npx` run the bundled release in the reader's directory with the
reader's arguments. npm keeps its own cache and registry settings.

### Acceptance criteria

- [x] A conformance case packs a fixture site with `node: true` and runs
      `node --version`, `npm --version` and `npx --version`, each reporting the
      bundled release.
- [x] The same case shows a Drush command sees the bundled `node` first on PATH.
- [x] The same case shows a request's PHP process finds the same `node`.
- [x] A second start leaves the unpacked Node directory's modification time
      unchanged.
- [x] `node -e 'console.log(process.cwd())'` prints the reader's directory.
- [x] `npx -y cowsay hi` runs in a network case, with npm's cache in the
      reader's home.

---

## Phase 3: clean, refusals and licenses

User stories: 17, 18, 19

### What to build

`clean` removes unpacked Node releases with the rest of the cache. On a musl
build the three words stop and name the glibc build. A site without Node
refuses them as unknown commands. Node's license files ship with it.

### Acceptance criteria

- [x] A conformance case shows `clean` removes the unpacked Node directory, and
      `clean --dry-run` lists it.
- [x] The musl run of the fixture case shows `node` exits 1 and names the glibc
      build.
- [x] A case on Mercury Demo, which carries no Node, shows `node` answers as an
      unknown command.
- [x] The unpacked Node directory holds Node's `LICENSE`.

---

## Phase 4: macOS and Windows

User stories: 20

### What to build

The resolver maps macOS by architecture and Windows x64. The build packs the
tar and zip layouts, whose executable directories differ. The launcher puts the
right directory on PATH on each platform.

### Acceptance criteria

- [ ] The resolver's Go tests cover the macOS and Windows archive names.
- [ ] The release workflow runs the Node fixture case on macOS and Windows, and
      it passes.

---

## Phase 5: docs and ADR

User stories: 1, 2, 3, 12

### What to build

The contract reference documents `node` and its forms. The CLI reference lists
the three words and the musl refusal. ADR 0027 records Bundled Node and the
keyring rule.

### Acceptance criteria

- [ ] `git grep -n 'node:' docs/` finds the contract reference entry.
- [ ] The CLI reference names `node`, `npm` and `npx`.
- [ ] ADR 0027 exists and names the keyring update the build asks for.
- [ ] `bash build/qa.sh` passes, and `.git/qa-green` records it.
