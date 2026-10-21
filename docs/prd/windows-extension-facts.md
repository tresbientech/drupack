# One copy of the Windows extension facts

## Problem Statement

`runtime/php-extensions.txt` lists every PHP extension a build compiles. Two
facts about it hold per platform:

- Windows PHP cannot load `password-argon2`, `pcntl` or `posix`.
- `opcache` reports itself as `zend opcache`.

`build/windows/build.ps1` and `tests/conformance/harness.py` each hold these
facts. A maintainer who adds a platform-specific extension edits two files in
two languages, and nothing fails when one edit is missed. The backlog entry
"One parser for the extension allowlist" records the problem.

## Solution

`build.ps1` stops holding the facts. It writes a php.ini line for each listed
name that has a DLL in the PHP zip, as it does today, and runs no load check of
its own. The conformance suite's exact check on the shipped executable stays,
on every Windows leg, and `harness.py` holds the only copy of the facts.

## Out of Scope

- One shared reader for the allowlist. The four readers strip comments in bash,
  Python and PowerShell, at one to five lines each.
- `check-extensions.py` refusing a site extension that one platform lacks, such
  as `ext-pcntl` on Windows.
- `runtime/php-extension-libs.txt`.
- Any change to which extensions a build compiles.

## User Stories

1. As a maintainer, I want the Windows extension facts in one file, so that a
   platform-specific extension needs one edit.
2. As a maintainer, I want a Windows build that lacks an extension to fail with
   the extension's name, so that I find the cause without reading the build.

## Implementation Decisions

- D1. `build.ps1` drops `$absentExtensions` and the filter on it. Its php.ini
  `extension=` lines stay limited to names with an `ext\php_NAME.dll`. The
  pinned PHP 8.5.11 zip holds no DLL for `opcache`, `pcntl`, `posix` or
  `password-argon2`, so the written php.ini does not change. Evidence:
  `build.ps1:68-73` and `:193-195`; a listing of the zip with SHA-256
  `c83d5a1e…53e1de` shows 36 DLLs and none of the four.
- D2. `build.ps1` drops `$preloadedExtensions` and its clause in the DLL
  filter. Evidence: `build.ps1:70`, `:194`, `:205`.
- D3. `build.ps1` drops its load check: the probe script, `$loaded`,
  `$expected`, `$missing` and the throw. Evidence: `build.ps1:199-208`.
- D4. The comments that named the dropped code are rewritten to state what
  decides a php.ini line. Evidence: `build.ps1:64-67`, `:69`, `:190-192`.
- D5. `harness.py` keeps `_UNLOADABLE`, `_REPORTED_AS` and
  `expected_extensions()` unchanged, and becomes the only copy of the facts.
  `build.ps1` runs only in `release.yml` (`:606`), whose same job then runs the
  suite on every Windows leg (`:642`). `build.yml` never runs `build.ps1`.
- D6. An ADR records the decision: the harness holds the platform facts and
  each language keeps its own reader. The backlog entry leaves
  `docs/backlog.md`.

### Considered options

- Tags in the allowlist file, such as `pcntl absent=windows`, read by new
  Python and PowerShell modules and a changed bash `sed`. It was built to put
  every fact in one file. It lost on cost: two new files, a Dockerfile COPY, a
  new offline-container mount, and a test that needs `pwsh`.
- A shared Python module, with a Linux test that reads `build.ps1` as text and
  compares its lists. It was built to keep the change small. It lost because
  the facts stay written twice, and it still adds a Dockerfile COPY and a mount.

## Testing Decisions

- A good test here checks the shipped executable's loaded extensions, which
  `site_cases.py:114` already does by exact equality. A missing and a surplus
  extension both fail it.
- No new test. The change removes code; the suite's check covers what the
  removed check covered, on the packed executable instead of the bare runtime.
- The Windows dispatch run of `release.yml` decides the change: both Windows
  legs build and pass `test_extensions`.

## Further Notes

- A hand-run `build.ps1` no longer stops at the build step when an extension is
  missing. The suite reports it, naming the extension in a sorted diff.
- `release.yml:600` keys the Windows build cache on `build.ps1`, so the first
  run after the change rebuilds the runtime.
