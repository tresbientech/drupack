# Plan: A site writes into its own application

> Source PRD: `docs/prd/writable-directories.md`. Decision: `docs/adr/0021-writable-directories.md`.

## Architectural decisions

These hold across all phases:

- `drupack.yml` gains `writable`, a list of directories relative to the project
  root. The parser validates it and holds its empty default. It travels in
  `site.json`.
- A site whose list is non-empty keeps its application in `app` in Site data,
  laid from the shared cache entry. A site whose list is empty keeps the shared
  entry, unchanged.
- A marker in the laid copy holds the release's application checksum, written
  last. A matching marker means the copy is used as it stands.
- The launcher handles an internal word, `lay-app` with the Site data
  directory, before runtime selection. `launch.php` calls it under the Serving
  lease and exports the laid copy to every child. `dr` only reads the copy.
- On upgrade, a direct entry of a writable directory carries over when the old
  copy holds it and the new release does not ship it. A shipped entry takes the
  release's version.
- The conformance case skips by name on a site whose `site.json` lists no
  writable directory. Engine QA runs on Mercury Demo, which lists none, so the
  case is proven on a scratch build of Mercury with the field added.

---

## Phase 1: The contract names writable directories

**User stories**: 1, 2, 3, 20

### What to build

The parser reads `writable`, refuses an absolute path, a `..` segment or a
duplicate, and names the field and the value it rejects. `site.json` and
`drupack-build --describe` carry the list. A site that lists nothing builds
and starts exactly as today. The site-building guide documents the field
beside the other contract fields.

### Acceptance criteria

- [x] Parser unit tests cover the accepted list, each rejection and the empty default
- [x] `drupack-build --describe` on a site with `writable` prints the list
- [x] A build of Mercury Demo is byte-for-byte what it was, apart from `site.json`
- [x] `docs/build-your-site.md` names `writable` with its rule

---

## Phase 2: A first start lays the site's copy and serves a write

**User stories**: 4, 5, 6, 7, 8, 9, 13, 15, 16, 18

### What to build

The layer in the launcher's runtime package lays `app` in Site data from the
shared entry, creates each writable directory, writes the marker last and
reports progress on standard error. The launcher's `lay-app` word runs it and
prints the directory. A first start whose `site.json` lists a writable
directory calls it and exports the laid copy to the server and to `dr`. A
file written under a writable directory serves on the next request, and a
theme written there is found by Drupal.

The conformance case writes a probe theme into the writable directory through
`php-cli`, enables it with `dr`, and fetches a page that renders it. On Mercury
it skips by name.

### Acceptance criteria

- [ ] Layer unit tests cover the first lay, reuse on the same marker, an interrupted lay leaving no marker, and a writable directory created when absent
- [ ] `clean` leaves Site data alone, and the shared entry stays in the cache
- [ ] The probe theme case passes on a scratch build of Mercury with `writable: [web/themes/custom]`
- [ ] The case is reported as skipped by name in engine QA
- [ ] Two Site data directories of the scratch build see each other's writes nowhere
- [ ] `bash build/qa.sh` passes

---

## Phase 3: An upgrade keeps the writes

**User stories**: 10, 11, 12, 14, 17, 19

### What to build

A start by a release whose checksum differs from the marker lays a staging
copy beside `app`, moves in every direct entry of each writable directory that
the old copy holds and the new release lacks, swaps, and removes the old copy.
`dr` from a release whose checksum differs from the marker refuses and names a
start.

The conformance case continues: rebuild the executable as the replacement
cases do, start it on the same Site data, and fetch the page that renders the
probe theme. The CLI reference names `app` among what Site data holds.

### Acceptance criteria

- [ ] Layer unit tests cover the carry-over, a shipped entry winning, and an interrupted upgrade leaving the old copy in place
- [ ] The probe theme case passes through the upgrade on the scratch build
- [ ] `dr` on a stale copy exits 1 and names a start
- [ ] `docs/cli.md` names `app` in the Site data section
- [ ] `bash build/qa.sh` passes
