# Path handling converges on one form per language

Proposed on 2026-09-21. Not implemented.

## The question

Ten Windows path defects landed on one branch. Seven were found by running the
product, not by reading it. Where does path handling converge, so the eleventh
does not reach a reader?

## What went wrong

| Class | Instances |
|---|---|
| Path text parsed as something else | `parse_url('C:/icon.svg')` read `C:` as a URL scheme. `app\2e2893…` lost `\2` to a `preg_replace` backreference and became `appe2893…` |
| Separator mixing on compare or join | `RootRelativePath::relativeTo`, the containment check in `SiteDataPublicStream` |
| Wrong base for a relative path | `--data-dir` resolved against the shared application after `chdir`, not against the reader's own directory |
| Filesystem object identity and limits | Junctions that PHP reports as neither file nor directory, directories Drupal hardened to read-only, MAX_PATH headroom |

## What each language already has

- Go: `path/filepath`, the only path API the launcher uses.
- PHP: `Symfony\Component\Filesystem\Path` 7.4.18 ships in `vendor/`, beside
  `launch.php`. Nothing in the repo calls it.
- Python tests and the PowerShell build: `pathlib` and `Join-Path`, each
  already the single entry point.

Three PHP helpers restate what Symfony ships:

- `RootRelativePath::relativeTo()` is `Path::makeRelative()`
- `absolutePath()` in `launch.php` is `Path::isAbsolute()`
- the join in `fromStartDirectory()` is `Path::makeAbsolute()`

## What a canonical form reaches, and what it does not

Measured on Windows 11 with the dev build on 2026-09-21. A script invoked
through `C:/Users/theno/AppData/Local/Temp/dirtest.php` reported:

```
DIR=C:\Users\theno\AppData\Local\Temp
FILE=C:\Users\theno\AppData\Local\Temp\dirtest.php
CWD=C:\Users\theno\AppData\Local\Drupack\runtime\app\r2e2893a48a83
REALPATH=C:\Users\theno\AppData\Local\Drupack\runtime\app\r2e2893a48a83
```

PHP returns backslashes from `__DIR__`, `__FILE__`, `getcwd()` and `realpath()`
whichever separator the caller used. `DrupalKernel::guessApplicationRoot()`
derives the application root from `__DIR__`, and the scaffolded `web/index.php`
passes no root of its own. Drupal's root therefore carries backslashes whatever
Drupack exports.

The invariant splits in two, and only one half reaches third-party code:

| Dimension | Reaches code Drupack did not write |
|---|---|
| Path content Drupack mints: first character a letter, short, ASCII | Yes. The `preg_replace` defect lived here |
| Path separator form | No. Drupal derives its root from `__DIR__` |

## Decision: one canonical form for every path Drupack states

A canonical path is absolute, uses forward slashes, carries no trailing
separator, and holds no `.` or `..` segment. Every path Drupack computes,
exports or prints takes that form, including the one the reader supplies with
`--data-dir`.

One form means no reverse conversion and no place the two can disagree. A
Windows reader sees `C:/Users/theno/Downloads/data`, which Explorer, `cmd` and
PowerShell all accept. Drupal stack traces in the same terminal already carry
forward slashes for any path built from an exported value, so a native-form
display would disagree with them.

`realpath()` returns the native form, so a call that resolves a path
re-canonicalises its result before the value travels further.

## Decision: Symfony Path is the PHP entry point

`launch.php` requires `vendor/autoload.php` in place of its hand-written
`require` of `support/PreviousCopies.php`. That one line reaches both Symfony's
`Path` and every `Drupack\Support` class, through the PSR-4 entry already in
`composer.json`.

Deleted: `RootRelativePath`, `absolutePath()`, and the join inside
`fromStartDirectory()`.

Kept: the containment check in `SiteDataPublicStream::getLocalPath()` resolves
with `realpath()` first and compares afterwards. `Path::isBasePath()` compares
text alone and follows no symlink, so it replaces the comparison, never the
resolution.

## Decision: Go stays native inside and canonical at the boundary

`path/filepath` keeps the native form for every path the launcher joins, walks
or opens. `filepath.ToSlash` converts at the boundary where PHP, Caddy or a
terminal reads the value: `DRUPACK_RUNTIME_APP_DIR`, `DRUPACK_RUNTIME_CWD`,
`DRUPACK_RUNTIME_BINARY`.

A minted cache segment matches `^[a-z][a-z0-9.-]{0,31}$`. `vdev-27e7e19d151a`
and `r2e2893a48a83` both comply. The rule lives in one place instead of the two
comments that state it today. `cmd/pack` rejects a `-version` that would break
it at build time, where `PrepareApp` already rejects a bad checksum at run time.

## Decision: the Drupal layer gets a crawl

Separator canonicalisation cannot reach Drupal, and both Drupal-side defects
needed a service override regardless. Passing a canonical `$app_root` into
`DrupalKernel` would have fixed neither: Canvas appends its suffix with
`DIRECTORY_SEPARATOR` after the root, and `parse_url` reads `C:` as a scheme
whichever slash follows.

`WindowsPathServiceProvider` keeps taking one override per defect. A new
conformance case class crawls a fixed page set and fails when an `href`, `src`
or inline style carries a drive letter, a backslash, or the application root
prefix. Pages: the front page, `/card-components`, `/admin/dashboard`,
`/admin/modules`, and one node carrying an uploaded image, so an image style
derivative address appears. Both defects found so far would have failed this
crawl.

## Considered options

- A `Drupack\Support\Path` facade over Symfony's. Rejected: a wrapper with one
  implementer is a layer, and Symfony covers every operation the product uses.
- Hand-requiring `vendor/symfony/filesystem/Path.php` and its two exception
  classes. Rejected: it writes three vendor paths into `launch.php`, which a
  Composer reorganisation breaks without a signal.
- A scaffold override of `web/index.php` passing a canonical `$app_root`.
  Rejected on the evidence above, which shows it fixes neither known defect.
- Composer patches against core and contrib. Rejected: every core and contrib
  update needs a re-roll, and a service override costs nothing per update.

## Consequences

- Windows readers see forward slashes in the `Site data` and `Log` lines.
- `launch.php` starts the Composer autoloader, which includes 27 bootstrap
  files and a 7292-entry classmap on every start, `dr` commands included.
- The crawl adds one site start to the suite on each platform.
- A path the reader supplies keeps whatever content it carries. The content
  rule covers only segments Drupack mints.

## Glossary delta

For `CONTEXT.md` on the implementing branch:

- **Canonical path**: the written form Drupack uses for every path it computes,
  exports or prints. Absolute, forward slashes, no trailing separator.
- **Minted segment**: a path element Drupack names itself, rather than one
  inherited from the reader or the operating system.
- **Application root**: the directory holding one release's unpacked
  application. Every site of that release reads it and none writes to it.

## Out of scope: a non-ASCII cache root breaks the server

Found while measuring this RFC, on the dev build, Windows 11, 2026-09-21. It
needs its own investigation and its own fix.

Three full starts, one variable between them:

| `DRUPACK_CACHE_DIR` | HTTP | Extension load failures |
|---|---|---|
| `…\drupack-fs-plain\cache` | 200 | 0 |
| `…\drupack-fs-space with space\cache` | 200 | 0 |
| `…\drupack-fs-accent_tëst\cache` | 500 | 11 |

The failing start reports, for each of the 13 extensions the Windows build
loads as DLLs:

```
Warning: PHP Startup: Unable to load dynamic library 'curl' (tried:
…\drupack-fs-accent_tëst\cache\vdev-27e7e19d151a\ext\php_curl.dll
(Le module spécifié est introuvable))
```

`%LocalAppData%` carries the Windows account name, so an account named `José`,
`Müller` or anything outside ASCII reaches this on a default start.

What the measurements rule out:

- The path characters alone. `php-cli` loads every extension from the same
  accented root.
- Environment mangling across the spawn. `PHPRC` arrives at the spawned hop
  byte-identical, `c3ab` for `ë`, and that hop loads `curl`.
- A space. The space run passes with a plain 200.

The warnings print after `launch.php` writes its `Site data` line and before
readiness, which places them in the `php-server` hop.

## Open questions

- Whether the fix belongs in FrankenPHP's PHP startup, in how the launcher
  hands `PHPRC` to it, or in choosing an ASCII cache root on Windows.
