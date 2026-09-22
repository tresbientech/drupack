# Plan: Drupack builds any recipe site

> Source PRD: `docs/prd/build-any-recipe-site.md`
> Source RFC: `docs/rfc/0001-build-any-recipe-site.md`

## Architectural decisions

These hold across all phases:

- Site contract: `drupack.yml` beside `composer.json`, with fields `name`, `port`, `recipe`, `site_name`, `languages`, `smoke_paths`, and `extensions` reserved.
- Normalized form: `site.json`, written by the one Go parser. It travels in the application payload and beside each executable.
- Readers: `launch.php`, `drupack-build` and the conformance suite read `site.json` only.
- Identity variables: `DRUPACK_RUNTIME_NAME`, `DRUPACK_RUNTIME_SITE_VERSION` and `DRUPACK_RUNTIME_ENGINE_VERSION`, exported by the launcher.
- Cache root: `<user cache>/<name>/runtime`, with `<tmp>/<name>-<uid>/runtime` as the fallback.
- Executable assets: `<name>-linux-<arch>`, as today's `drupack-linux-<arch>`.
- Runtime archives: `drupack-runtime-<engine version>-linux-<arch>-<libc>.tar.zst`, listed in the engine release's `checksums.txt`.
- Job image: `ghcr.io/tresbientech/drupack-build:<engine version>`.
- CI inputs: `platforms`, `libc`, `publish`, with the same names on GitHub and GitLab.
- Mercury Demo builds at the end of every phase.

---

## Phase 1: Site config tracer

**User stories**: 1, 2, 3, 4, 7, 8, 26, 30, 32

### What to build

The Go parser reads `drupack.yml`, validates it and writes `site.json`. Mercury's
composer project moves to `examples/mercury-demo/` with a `drupack.yml`. The
current Docker build takes the site directory as a build context and reads the
recipe, site name and languages from `site.json`. `launch.php` reads the same
defaults from `site.json` for a MySQL or PostgreSQL first start. The `mcp_tools`
enable leaves both install paths. The `automatic_updates` and `package_manager`
uninstall stays.

### Acceptance criteria

- [ ] `cd launcher && go test ./...` passes, with table cases for each field's valid and invalid values and for the `site.json` output.
- [ ] A `drupack.yml` with a bad `name` makes the parser exit non-zero with a message naming `name`, shown by a Go test.
- [ ] `grep -rniE 'mercury|mcp_tools' application runtime launcher build Dockerfile` prints nothing.
- [ ] `./dist/drupack php-cli "$PWD/application/tests/launch_test.php"` passes, with cases for the defaults read from `site.json`.
- [ ] `git config --get qa.command`, updated for the site build context, passes in full.

---

## Phase 2: Site identity

**User stories**: 5, 6, 22, 23, 24, 25

### What to build

The packer takes `name`, `port`, the site version and the engine version and
writes them into the launcher. The launcher derives its cache root from `name`
and exports the three identity variables. The entry point and `launch.php` use
the name in usage, readiness and error text. The default listen port comes from
`site.json`. `--version` prints the name, both versions and the libc.

### Acceptance criteria

- [ ] `cd launcher && go test ./...` passes, with cases showing the cache root follows `name` and two names give two roots.
- [ ] A conformance launcher case packs fixture launchers named `alpha` and `beta`, runs `clean` on one, and the other still starts.
- [ ] `./dist/drupack --version` prints `drupack <site version> (drupack <engine version>, <libc>)`, asserted by a conformance case.
- [ ] `launch_test.php` passes, with a case whose error text names the executable from `DRUPACK_RUNTIME_NAME`.
- [ ] `git config --get qa.command` passes in full.

---

## Phase 3: drupack-build on Linux with local runtimes

**User stories**: 11, 12, 13, 20, 28, 31, 33

### What to build

`drupack-build` plans and runs a whole Linux build inside the job image with no
Docker daemon. The steps are Composer, the translation fetch, the seed install,
the payload archive, the pack and the suite. It takes local runtime directories
per libc. It refuses macOS and Windows platforms, and a non-empty `extensions`.
The job image definition lands in the repository. The Dockerfile keeps its
runtime compile stages and loses `app`, `packed` and `artifact`. CONTRIBUTING
documents the local build as one `docker run` of the job image.

### Acceptance criteria

- [ ] `cd launcher && go test ./...` passes, with plan cases for each `platforms` and `libc` combination and for refused inputs.
- [ ] Inside the locally built job image, `drupack-build --site examples/mercury-demo --platform linux-amd64 --libc both` with local runtime directories writes the executable and `site.json`, and exits 0.
- [ ] The same command with `--libc glibc` writes an executable whose `--version` names glibc and which ignores `DRUPACK_LIBC=musl`, asserted by a conformance case.
- [ ] `--platform macos-arm64` and a `drupack.yml` with `extensions: [gmp]` each exit non-zero with a message naming the refused value.
- [ ] `grep -cE ' AS (app|packed|artifact)$' Dockerfile` prints 0.
- [ ] `git config --get qa.command`, rewritten to call `drupack-build`, passes in full.

---

## Phase 4: Site-agnostic suite without a daemon

**User stories**: 16, 17, 18, 19

### What to build

The conformance suite takes the site from `site.json`: its name and its smoke
paths. Server-database cases take their database from environment variables, so
CI services can provide one. Cases that need a Docker daemon report as skipped by
name when none answers. The run then passes on the rest.

### Acceptance criteria

- [ ] `grep -rniE 'mercury|mcp_tools' tests/conformance` prints nothing.
- [ ] `python3 -m unittest discover -s tests/conformance -p 'test_harness.py'` passes, with cases for reading `site.json` and for the skip report.
- [ ] `DOCKER_HOST=unix:///nonexistent python3 tests/conformance ./dist/drupack test-results/conformance` exits 0 and prints each skipped case by name.
- [ ] A `site.json` whose `smoke_paths` names a missing path makes the suite exit non-zero, naming the path.
- [ ] With the MySQL and PostgreSQL variables pointed at local containers, the server-database cases pass.
- [ ] `git config --get qa.command` passes in full.

---

## Phase 5: Published runtimes and job image

**User stories**: 21, 29

### What to build

A tag run of `release.yml` publishes the job image and one runtime archive per
Linux architecture and libc, listed in `checksums.txt`. `drupack-build` downloads
the archives for its engine version when no local runtime is given, and verifies
each against the checksums. The real publication waits for the owner's tag.

### Acceptance criteria

- [ ] `cd launcher && go test ./...` passes, with `httptest` cases for a checksum mismatch, a missing archive and a reused download.
- [ ] Runtime archives made by the release step's script, served with `python3 -m http.server`, let `drupack-build` build Mercury with no local runtime, and the suite passes.
- [ ] `actionlint .github/workflows/release.yml` reports nothing.

---

## Phase 6: GitHub reusable workflow

**User stories**: 9, 14, 15, 27

### What to build

`build.yml` is a `workflow_call` workflow taking `platforms`, `libc` and
`publish`. It runs `drupack-build` in the job image and uploads the executables.
With `publish`, it creates a release in the caller's repository with checksums, an
SBOM and provenance. `release.yml` builds Mercury by calling it with local
runtimes. How `build.yml` finds its own engine ref is settled here.

### Acceptance criteria

- [ ] `actionlint .github/workflows/*.yml` reports nothing.
- [ ] After the owner approves the push, `gh run list --commit <sha>` shows the `main` run green, with the Mercury build going through `build.yml`.
- [ ] A caller fixture workflow in a scratch GitHub repository, pinned to the branch, builds a `linux-amd64` glibc executable. `gh run view` shows the artifact.

---

## Phase 7: drupal.org GitLab template

**User stories**: 10

### What to build

A GitLab CI template in the engine repository takes `platforms`, `libc` and
`publish`. Callers include it from the `project/drupack` mirror at an engine tag.
Its job runs in the job image with MySQL and PostgreSQL services for the suite.
With `publish`, it creates a GitLab release carrying the executables and checksums.

### Acceptance criteria

- [ ] `gitlab-ci-local` runs a fixture caller project that includes the template by local path, and its build job exits 0 with the executable written.
- [ ] The fixture run lists the Docker-only cases as skipped and passes the server-database cases against its services.
- [ ] After the owner's engine tag, a pipeline in a drupal.org sandbox project including the template passes. `glab ci status` against git.drupalcode.org shows it green.
