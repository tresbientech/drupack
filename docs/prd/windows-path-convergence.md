# PRD: Path handling converges on one form per language

> Source RFC: [docs/rfc/windows-path-convergence.md](../rfc/windows-path-convergence.md)

## Problem Statement

A Windows reader meets path defects that a Linux reader never sees. Ten of them
landed on one branch. Seven were found by running the product, not by reading
it.

Each one came from the same place: every layer decides its own path form.
`launch.php` carries three hand-written helpers, the launcher joins with
`path/filepath`, and Drupal derives its root from `__DIR__`. A value crossing
two layers can arrive with mixed separators, with a drive letter read as a URL
scheme, or resolved against the wrong base.

Nothing in the product states which form a path takes. A reader of the code
cannot tell whether a given string is absolute, native or canonical. The
eleventh defect lands the same way as the first ten.

## Solution

One canonical path form, stated once and produced at every boundary.

A canonical path is absolute, uses forward slashes, carries no trailing
separator, and holds no `.` or `..` segment. Every path Drupack computes,
exports or prints takes that form, including the one the reader gives with
`--data-dir`.

Each language keeps the path API it already has. PHP calls
`Symfony\Component\Filesystem\Path`, which ships in `vendor/` and replaces the
three hand-written helpers. Go keeps `path/filepath` inside the launcher and
converts with `filepath.ToSlash` at the boundary where PHP, Caddy or a terminal
reads the value.

Drupal keeps taking one service override per defect, because separator
canonicalisation cannot reach code deriving its own root from `__DIR__`. A
conformance crawl over a fixed page set fails when a rendered address carries a
drive letter, a backslash or the application root prefix.

## User Stories

1. As a Windows reader, I want the `Site data` line to name a path my shell
   accepts, so that I can paste it without editing it.
2. As a Windows reader, I want the `Log` line in the same form as the `Site
   data` line, so that two lines of one report agree.
3. As a Windows reader, I want `--data-dir` resolved against the directory I
   started from, so that the site data lands where I named it.
4. As a Windows reader, I want a relative `--data-dir` to work from any
   directory, so that the shared application's location never enters it.
5. As a Windows reader, I want paths in Drupal stack traces to match the paths
   Drupack prints, so that one terminal holds one form.
6. As a Windows reader, I want an uploaded image to render, so that a page
   holds no broken address.
7. As a Windows reader, I want an icon to render on the front page, so that a
   drive letter never reaches a URL parser.
8. As a Windows reader, I want an image style derivative to render, so that a
   generated address carries no disk path.
9. As a Windows reader with an existing site, I want the first start after this
   change to boot at its usual speed, so that an upgrade costs no rebuild.
10. As a reader on any platform, I want `dr` commands to keep working, so that
    the autoloader change stays invisible.
11. As a developer, I want one path API per language, so that I learn one rule
    instead of three.
12. As a developer, I want `Path::makeRelative`, `Path::isAbsolute` and
    `Path::makeAbsolute` in place of the hand-written helpers, so that the
    product carries no path code of its own.
13. As a developer, I want `launch.php` to require `vendor/autoload.php`, so
    that one line reaches Symfony and every `Drupack\Support` class.
14. As a developer, I want the deleted helpers gone from the tests too, so that
    nothing in the repo serves the old mechanism.
15. As a developer, I want the minted segment rule written once in code, so
    that two comments cannot drift apart.
16. As a developer, I want `cmd/pack` to reject a `-version` that breaks the
    segment rule, so that the build fails before a reader meets it.
17. As a developer, I want `filepath.ToSlash` applied at the three exported
    runtime variables, so that the conversion point is countable.
18. As a developer, I want the launcher to keep native paths internally, so
    that Go's own file calls stay ordinary.
19. As a developer, I want the containment check in `SiteDataPublicStream` to
    keep resolving with `realpath()` first, so that a symlink cannot walk out
    of public storage.
20. As a developer, I want `Path::isBasePath()` used for the text comparison
    only, so that the resolution step stays where it is.
21. As a reviewer, I want the canonical form defined in `CONTEXT.md`, so that a
    later reader finds the rule without reading the RFC.
22. As a reviewer, I want `minted segment` and `application root` defined
    beside it, so that the glossary matches the code.
23. As a maintainer, I want a conformance case class crawling a fixed page set,
    so that a rendered drive letter fails the suite.
24. As a maintainer, I want the crawl to cover the front page,
    `/card-components`, `/admin/dashboard`, `/admin/modules` and a node with an
    uploaded image, so that an image style derivative appears in the set.
25. As a maintainer, I want the crawl to fail on a backslash, a drive letter or
    the application root prefix in any `href`, `src` or inline style, so that
    the two known defects would have been caught.
26. As a maintainer, I want the crawl to run on Windows, so that the platform
    carrying the defects gets the check.
27. As a maintainer, I want a PHP unit table over the boundaries Drupack still
    owns, so that a canonicalisation defect fails without starting a site.
28. As a maintainer, I want the container cache key stable across this change,
    so that no Windows site rebuilds its container on upgrade.
29. As a maintainer, I want the start cost of the Composer autoloader measured
    on both platforms, so that the number sits in the record.
30. As a maintainer, I want `realpath()` results re-canonicalised, so that a
    resolved path never travels in native form.

## Implementation Decisions

### Canonical form

- Absolute, forward slashes, no trailing separator, no `.` or `..` segment.
- Applies to every path Drupack computes, exports or prints, and to the value
  of `--data-dir`.
- One form only. No reverse conversion, and no second form to disagree with it.
- A call resolving a path with `realpath()` re-canonicalises the result before
  the value travels further.

### PHP

- `launch.php` requires `vendor/autoload.php` in place of its hand-written
  `require` of `support/PreviousCopies.php`. The PSR-4 entry in
  `drupal/composer.json` maps `Drupack\Support\` to `support/`, so one line
  reaches both Symfony and the support classes.
- Deleted: `RootRelativePath`, `absolutePath()` in `launch.php`, and the join
  inside `fromStartDirectory()`. Their callers move to `Path::makeRelative()`,
  `Path::isAbsolute()` and `Path::makeAbsolute()`.
- Kept: the `realpath()` resolution in `SiteDataPublicStream::getLocalPath()`.
  `Path::isBasePath()` replaces the text comparison after it, and follows no
  symlink of its own.
- `settings.php` hashes a slash-normalised `DRUPACK_RUNTIME_APP_DIR` for
  `deployment_identifier`. The old native value and the new canonical value
  then produce one identifier, and an upgraded Windows site keeps its container
  cache. Two application directories still key apart.

### Go

- `path/filepath` keeps the native form for every path the launcher joins,
  walks or opens.
- `filepath.ToSlash` converts at the boundary where another program reads the
  value: `DRUPACK_RUNTIME_APP_DIR`, `DRUPACK_RUNTIME_CWD` and
  `DRUPACK_RUNTIME_BINARY`.
- A minted cache segment matches `^[a-z][a-z0-9.-]{0,31}$`. The rule lives in
  one exported place in `internal/runtime`, replacing the two comments that
  state it today.
- `cmd/pack` rejects a `-version` that would break the rule, at build time.
  `PrepareApp` already rejects a bad checksum at run time.

### Drupal

- `WindowsPathServiceProvider` keeps taking one override per defect.
- No scaffold override of `web/index.php`, and no Composer patch against core
  or contrib.

### Start cost

- The Composer autoloader runs on every start, `dr` commands included. The
  plan measures one `dr` command before and after on Linux and on Windows, and
  records both numbers. No timing assertion enters the suite.

## Testing Decisions

A good test here asserts what a reader can observe: a rendered page, a printed
line, an exit code. It never reaches into a helper the product may delete.

- Conformance crawl, new case class, Linux and Windows. It starts a site,
  fetches the five pages of the RFC's set, and fails on a backslash, a drive
  letter or the application root prefix in any `href`, `src` or inline style.
  Prior art: `tests/conformance/windows_paths_cases.py`.
- PHP unit table, `tests/unit/windows_paths.php`. It covers the boundaries
  Drupack still owns after the deletions. Entries for the deleted helpers go
  with them. Symfony's own functions get no tests of ours.
- Go unit tests, `packaging/launcher`. They cover the three converted
  variables, the segment rule, and the `cmd/pack` rejection of a bad
  `-version`.
- The existing suites stay green: `go test ./...`, the harness tests, and the
  full conformance run on Linux and Windows.

## Out of Scope

- A `Drupack\Support\Path` facade over Symfony's API.
- A scaffold override of `web/index.php` passing a canonical `$app_root`.
- Composer patches against core or contrib.
- The non-ASCII cache root fault: a cache directory holding an accented
  character makes the `php-server` hop fail to load 13 extensions, and the site
  answers 500. The RFC records the measurements. It needs its own
  investigation.
- macOS path behaviour. No defect of this class was reported there.

## Further Notes

- The source branch is `worktree-windows-paths`, 29 commits ahead of `main` and
  unmerged. This work branches from it, so the code the RFC edits is present.
  Both land together.
- The bundled TLS trust work runs at the same time on `tls-trust`, branched
  from `main`. It edits the launcher's child environment and
  `packaging/windows/build.ps1`. Expect a conflict in those two places at merge.
