# One directory per built artifact

Accepted on 2026-09-22.

## Context

The word `runtime` named four things: the PHP application files, the compiled
PHP and FrankenPHP executable, the `runtime` directory inside Site data, and
the launcher's Go package.

`drupal/` and `runtime/` were one Composer package root in two directories.
`drupal/composer.json` mapped PSR-4 `Drupack\Support\` to `support/`, which
lived in `runtime/support/`. The mapping resolved only after the build merged
both trees into `/app`. A `runtime/vendor` symlink let the PHP unit files run
outside a build, and three files carried a paragraph explaining it.

`packaging/` held shell build scripts, a Go module, Caddy module source, a
Drupal application file and the dev loop. Its `go.mod` declared
`git.tresbien.tech/tresbientech/drupack/launcher`, a path the tree contradicted.

Tests sat in three homes under two conventions: Python at `tests/conformance`,
PHP at `tests/unit`, Go beside its source.

## Decision

- `application/` holds everything that becomes the Application root: the
  Composer project, the files laid over it, `support/` and the PHP unit tests.
- `runtime/` holds the PHP and FrankenPHP compile: `entrypoint.go`, `embed.sh`,
  `build-builder.sh`, `extensions-list.sh`, both extension lists and
  `check-extensions.py`.
- `launcher/` is the Go module, at the path `go.mod` declares.
- `build/` holds the macOS and Windows builds, the two app-stage helpers and the
  dev loop.
- `Dockerfile` stays at the root, beside the `.dockerignore` that governs it.
- `tests/conformance` keeps its path.
- The payload staging directory moves from `application/` to `dist/payload/`.
  Both platform scripts take a payload directory in place of an application
  directory.
- `packaging/`, `drupal/` and the `runtime/vendor` symlink go.

## Considered options

- A `src/` and `build/` split, by what ships. It divides the PHP compile:
  `entrypoint.go` on one side, `php-extensions.txt` and `embed.sh` on the other.
- Move only the misfiled files. It leaves `runtime` carrying four meanings, and
  keeps the vendor symlink and its three explanations.
- Keep the Composer project and its overlay as two directories. The PSR-4
  mapping keeps pointing across trees, and the symlink stays.
- Move the `Dockerfile` to `build/linux/`. It makes the three platform builds
  peers, at one `-f` flag on 7 invocations. It also separates the file from the
  `.dockerignore` that governs its `COPY` lines.

## Consequences

- One commit rewrites 18 lines in the `Dockerfile`, 26 in `release.yml`, 7 path
  references in the PHP unit files, both ignore files and `CONTRIBUTING.md`. It
  also reaches the conformance harness, which holds the allowlist and launcher
  source paths as constants.
- `git log --follow` traces a file across the rename. Per-line blame survives.
- The composer install layer still caches. The `Dockerfile` copies the two lock
  files by name before the rest of `application/`.
- `application/tests/` never ships. `app-payload.sh` excludes every path
  component named `tests`.
- ADR 0002 and ADR 0016 cite `tests/conformance`, which does not move.
- ADR 0016 names three `packaging/` paths that this decision moves. It records
  what was decided then and stays as written.
