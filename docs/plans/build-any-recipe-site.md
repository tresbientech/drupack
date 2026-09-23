# Plan: Drupack builds any recipe site

> Source PRD: `docs/prd/build-any-recipe-site.md`
> Source RFC: `docs/rfc/0001-build-any-recipe-site.md`

## Status, 2026-09-23

Branch `build-any-recipe-site`, head `3e5ac04`, is pushed to the Forge and the
GitHub mirror. It is not merged. The next mirror sync removes the GitHub copy.

Phases 1 to 6 are done:

| Phase | Commits |
|---|---|
| Plan and PRD | `fe801a6` |
| 1, site config | `0e2a3c7` |
| 2, site identity | `7d68294` |
| 3, site-agnostic suite | `ba74df3` |
| 4, `drupack-build` | `4945fd4` |
| 5, job image carries the runtimes | `d77f80a`, fixed by `476e569` |
| 6, reusable workflow | `f52104d`, fixed by `108a4f7` |

The owner added work outside the phases:

- `docs/build-your-site.md` covers GitHub, Gitea and any other CI (`1244296`).
- `build.yml` passes a `COMPOSER_AUTH` secret for private packages (`4b2d683`).
- The job image carries Node 24, which Gitea's runner needs for JavaScript actions (`a21dc2d`).
- The musl runtime gives PHP threads an 8 MB stack (`93602fc`). At 512 KB, freeing a render array nested past about 10,000 levels crashed the server.
- The README states the memory a hosting server needs (`3e5ac04`).

Evidence:

- `bash build/qa.sh` passed on `93602fc`.
- GitHub run 35852806747 on `93602fc` passed, with Mercury built through `build.yml`.
- A private caller repository, `theodoreb/drupack-caller-test`, built through `build.yml@build-any-recipe-site` in run 35845487975.
- A private Forge repository, `theodore/drupack-site-test`, ran the Gitea job from the doc: run 1114 on `main`, run 1115 on tag `0.0.1` with its release.

Next:

1. Phase 7, below.
2. A whole-branch review, then one `code-rules-auditor` and one `design-auditor` run. Delete `.superpowers/code-rules-deferred` afterwards.
3. `bash build/qa.sh` in full.
4. The owner approves the merge and the push to the Forge's `main`.

Chores:

- Delete the two scratch repositories named above once no test needs them.
- Delete the stray `/tmp/.git`.
- `drupack-build` leaves an empty temporary work directory when `NewPlan` refuses a request.
- The development loop in `build/dev/` has not run since phase 5 changed the runtime paths.

The run log with every ruling is `.superpowers/sdd/build-any-recipe-site-ledger.md`,
which is local to this checkout and not committed.

## Architectural decisions

These hold across all phases:

- Site contract: `drupack.yml` beside `composer.json`, with fields `name`, `port`, `recipe`, `site_name`, `languages`, `smoke_paths`, and `extensions` reserved.
- Normalized form: `site.json`, written by the one Go parser. It travels in the application payload and beside each executable.
- Readers: `launch.php`, `drupack-build` and the conformance suite read `site.json` only.
- Identity variables: `DRUPACK_RUNTIME_NAME` and `DRUPACK_RUNTIME_SITE_VERSION`, exported by the launcher. The runtime's own version is the engine version.
- Cache root: `<user cache>/<name>/runtime`, with `<tmp>/<name>-<uid>/runtime` as the fallback.
- Executable assets: `<name>-linux-<arch>`, as today's `drupack-linux-<arch>`.
- Job image: `ghcr.io/tresbientech/drupack-build:<engine version>`, carrying the four Linux runtimes under `/opt/drupack/runtimes/<platform>-<libc>`.
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

The packer takes `name` and the site version and writes them into the launcher.
The launcher derives its cache root from `name` and exports both identity
variables. The entry point and `launch.php` use
the name in usage, readiness and error text. The default listen port comes from
`site.json`. `--version` prints the name, both versions and the libc.

### Acceptance criteria

- [ ] `cd launcher && go test ./...` passes, with cases showing the cache root follows `name` and two names give two roots.
- [ ] A conformance launcher case packs fixture launchers named `alpha` and `beta`, runs `clean` on one, and the other still starts.
- [ ] `./dist/drupack --version` prints `drupack <site version> (drupack <engine version>, <libc>)`, asserted by a conformance case.
- [ ] `launch_test.php` passes, with a case whose error text names the executable from `DRUPACK_RUNTIME_NAME`.
- [ ] `git config --get qa.command` passes in full.

---

## Phase 3: Site-agnostic suite without a daemon

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

## Phase 4: drupack-build on Linux with local runtimes

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

## Phase 5: Published job image with its runtimes

**User stories**: 21, 29

### What to build

The job image carries the Linux runtimes, one `PLATFORM-LIBC` directory each.
`drupack-build` takes each target `--runtime` leaves out from that directory. A
tag run of `release.yml` builds the image with all four runtimes and publishes it
once every platform passed. The real publication waits for the owner's tag.

The runtimes ship inside the image, not as separate archives, because every build
runs in the image. That leaves no download, cache or checksum code.

### Acceptance criteria

- [ ] `cd launcher && go test ./...` passes, with a case where the runtime directory supplies one target and refuses a missing one.
- [ ] `bash build/qa.sh` builds Mercury in the job image with no `--runtime`, and the suite passes.
- [ ] `actionlint .github/workflows/release.yml` reports nothing.

---

## Phase 6: GitHub reusable workflow

**User stories**: 9, 14, 15, 27

### What to build

`build.yml` is a `workflow_call` workflow taking `platforms`, `libc` and
`publish`. It runs `drupack-build` in the job image and uploads the executables.
With `publish`, it creates a release in the caller's repository with checksums, an
SBOM and provenance. `release.yml` builds Mercury by calling it in the job image
its payload job pushed. `build.yml` reads its own engine ref from the OIDC `job_workflow_ref` claim
and runs in the job image under that tag, or under `sha-<commit>` for a branch.

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
