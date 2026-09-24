# Plan: Drupack packages an existing site

> Decisions: `docs/adr/0019-package-an-existing-site.md`, `docs/adr/0020-site-extensions.md`

## Status, 2026-09-24

Branch `package-an-existing-site`, from `main` at `707fbb1`. All six phases
are done, each with `bash build/qa.sh` passing. Phase 6 ran on the client's
prod release tag and a daily prod database backup imported into DDEV. Before
merge: one code-rules audit and one design audit.

## Architectural decisions

These hold across all phases:

- The docroot comes from composer.json's `web-root`, and a project without it
  fails the build. It travels in `site.json`.
- New `drupack.yml` fields: `settings`, `platforms`, `libc`. `recipe` becomes
  optional. `extensions` stops being refused.
- The parser holds every default. `drupack-build` flags and CI inputs override
  the file and carry no default of their own.
- A site without a recipe runs only against a server database that holds it.
- `build/site-runtimes.sh` is the one path that compiles a runtime with added
  extensions, on a Docker host.
- Mercury Demo builds at the end of every phase.

The target site for the last phase is a client Site Studio site on DDEV, built
from an untracked `drupack.yml` in the local checkout. Nothing is committed to
its repository, and no document here names it.

---

## Phase 1: Docroot from composer.json

### What to build

The parser reads `extra.drupal-scaffold.locations.web-root` and writes
`docroot` into `site.json`. The Caddyfile root, `launch.php`'s `$app_root`,
`build/seed.sh` and the `app-payload.sh` excludes read it instead of `web`.

### Acceptance criteria

- [x] `cd launcher && go test ./...` passes, with cases for a `docroot/` layout, a trailing slash and a refused absent key.
- [x] `grep -rnE "'/web|root \* web|/web/" application build/seed.sh` prints nothing.
- [x] A fixture site with `web-root: docroot/` builds in the job image, and the suite passes on it.
- [x] `bash build/qa.sh` passes.

---

## Phase 2: Targets in drupack.yml

### What to build

`platforms` and `libc` join the contract, with the values `--platform` and
`--libc` take. The parser supplies `linux-amd64` and `both`. `drupack-build`
flags override the file. `build.yml`'s `platforms` and `libc` inputs lose
their defaults, and an empty input means the file's value.

### Acceptance criteria

- [x] `cd launcher && go test ./...` passes, with parser cases for each field's valid and refused values and plan cases showing a flag beats the file.
- [x] `drupack-build --site examples/mercury-demo` with no target flag builds what Mercury's `drupack.yml` names.
- [x] `actionlint .github/workflows/*.yml` reports nothing.
- [x] `docs/build-your-site.md` lists both fields and the override order.
- [x] `bash build/qa.sh` passes.

---

## Phase 3: A site without a recipe

### What to build

The parser accepts a missing `recipe`. The build plan drops the seed step when
there is none. `launch.php` refuses a SQLite first start for such a site, naming
`--database mysql` and `pgsql`, and adopts a server database that holds the
site. The suite skips each seed case by name with the reason "the site has no
recipe".

### Acceptance criteria

- [x] `cd launcher && go test ./...` passes, with a plan case that has no seed step.
- [x] `launch_test.php` passes, with a case for the SQLite refusal text.
- [x] A fixture site without a recipe builds, and the suite lists its skipped seed cases by name and exits 0.
- [x] A conformance case starts the fixture against a MySQL server holding an installed site and gets a 200 on `/`.
- [x] `bash build/qa.sh` passes.

---

## Phase 4: Site settings file and --files-dir

### What to build

`settings` names a PHP file in the site. The build stages it into the
application, and the generated `settings.php` requires it last. `--files-dir`
sets the public files directory for the public stream wrapper and the
Caddyfile, through `DRUPACK_RUNTIME_FILES_DIR`, and the listener record keeps
it for later starts and `dr`.

### Acceptance criteria

- [x] `cd launcher && go test ./...` passes, with a parser case refusing a `settings` path outside the site.
- [x] `launch_test.php` passes, with cases for the require line and for the recorded `--files-dir`.
- [x] A conformance case serves a file placed in a `--files-dir` directory outside Site data.
- [x] `docs/cli.md` names `--files-dir`, and its parser case passes.
- [x] `bash build/qa.sh` passes.

---

## Phase 5: Site extensions

### What to build

The parser accepts `extensions`, each name checked against a pattern.
`build/site-runtimes.sh SITE OUTPUT` merges the lists, builds each target's
builder image and writes the runtime directories. `builder-tag.sh` and
`check-extensions.py` take the merged list.

As built:

- `site-runtimes.sh` compiles for the host's architecture only, and refuses
  another target.
- `drupack-build` runs `check-extensions.py` as its first step.
- `composer install` skips the build PHP's check of each site extension.
- The seed installs on the build PHP, so a recipe whose install needs a site
  extension fails.

### Acceptance criteria

- [x] `cd launcher && go test ./...` passes, with parser cases for valid and refused extension names.
- [x] A fixture site with `extensions: [xmlwriter]` and `libc: glibc` compiles through `site-runtimes.sh`, and a rerun reuses the builder image.
- [x] `drupack-build --runtimes OUTPUT` on that fixture builds, and a conformance case asserts the executable loads `xmlwriter`.
- [x] A lock declaring an extension neither list names fails the build, naming it.
- [x] `bash build/qa.sh` passes.

---

## Phase 6: The client site against DDEV

### What to build

No engine code, unless the run finds a defect. The client site's checkout gets an
untracked `drupack.yml` and a pinned `host_db_port` in `.ddev/config.local.yaml`.
The executable starts against DDEV's MySQL with `--files-dir` on
`docroot/sites/default/files`. `docs/build-your-site.md` gains the existing-site
walkthrough, with no client names.

The run found five engine defects, fixed on this branch:

- The job image lacked GNU `patch`, which `cweagans/composer-patches` 1.x needs.
- A tracked symlink reached the payload, which the unpacker refuses. Staging
  copies a link inside the site as its target, and leaves out one that points
  elsewhere, naming it in the log.
- A failed login link stopped the first start on an adopted site whose uid 1
  is blocked.
- A conformance case expected the executable to be named `drupack`.
- Site Studio names its compiled templates by their public files address,
  which the application does not hold. A Twig loader resolves those names in
  the files directory.

The runtimes, the executable and the Site data go to directories outside both
repositories. The executable carries the client's code, so no build output of
the client site goes to any remote: no forge, artifact store or registry.

### Acceptance criteria

- [x] `site-runtimes.sh` and `drupack-build` build the client site with no target flag.
- [x] A first start against DDEV's database reports the adopted site and enables nothing.
- [x] `/` and three Site Studio pages answer 200 with their styles, compared against DDEV's pages.
- [x] `ddev drush pm:list --status=enabled` prints the same list before and after the first start.
