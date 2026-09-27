# Plan: A Composer package installs the engine executable

> Decision: `docs/adr/0024-composer-package.md`.

## Status, 2026-09-27

Released in 0.5.1. A `drupal/recommended-project` installs the package from
Packagist, and `./drupack` serves it.

## Architectural decisions

- The package is this repository's root `composer.json`, named
  `tresbientech/drupack`, with `composer/drupack-install` as its one bin.
- `drupack-install` fetches the release's install script for the package's
  version and runs it from a temporary file: `sh FILE`, or
  `powershell -File FILE` on Windows.
- The executable lands in the project root as `drupack`, `drupack.exe` on
  Windows.

---

## Phase 1: The package and its command

### What to build

`composer require --dev tresbientech/drupack` followed by
`vendor/bin/drupack-install` leaves `./drupack` in the project, listed in
`.gitignore`, and prints how to start it.

### Acceptance criteria

- [x] `git archive` of a tag holds `composer.json`, the bin script, `LICENSE` and `README.md` alone.
- [x] `drupack-install` refuses a dev version and names the release it needs.
- [x] It adds `/drupack` to `.gitignore` once, and creates the file only in a git project.
- [x] Its output names the project root as the executable's place, with no `PATH` advice.
- [x] It downloads nothing when `./drupack` matches the release's SHA-256 for `drupack`, and never runs the file.
- [x] After 0.5.1 publishes, a scratch Drupal project installs and serves through the package.
