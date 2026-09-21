# PRD: A non-ASCII cache root breaks the server

> Source: the "Out of scope" section of
> [docs/rfc/windows-path-convergence.md](../rfc/windows-path-convergence.md),
> which holds the measurements.

## Problem Statement

A Windows reader whose account name carries a non-ASCII character cannot run
Drupack. The site answers 500 on a default start.

`%LocalAppData%` carries the account name, and the cache root sits under it. An
account named `José`, `Müller`, `Ольга` or `田中` therefore reaches this on the
first start, with no unusual setup and no way to see why.

The failure is loud in the log and silent to the reader. For each of the 13
extensions the Windows build loads as DLLs, PHP reports a startup warning:

```
Warning: PHP Startup: Unable to load dynamic library 'curl' (tried:
…\drupack-fs-accent_tëst\cache\vdev-27e7e19d151a\ext\php_curl.dll
(Le module spécifié est introuvable))
```

The printed path is correct. The open fails anyway.

## Solution

Find the failing layer, then fix it where it breaks.

The cause is open. Three candidates carry the evidence so far: PHP startup
inside FrankenPHP, the way the launcher hands `PHPRC` and the extension
directory to the `php-server` hop, or the cache root itself.

This work commits to a diagnosis first. The fix phases are written once the
evidence names a layer, so the plan does not promise a repair it cannot yet
describe.

## What the measurements already rule out

Three full starts, one variable between them, taken on the dev build on Windows
11 on 2026-09-21.

| `DRUPACK_CACHE_DIR` | HTTP | Extension load failures |
|---|---|---|
| `…\drupack-fs-plain\cache` | 200 | 0 |
| `…\drupack-fs-space with space\cache` | 200 | 0 |
| `…\drupack-fs-accent_tëst\cache` | 500 | 11 |

Ruled out by those runs:

- The path characters alone. `php-cli` loads every extension from the same
  accented root.
- Environment mangling across the spawn. `PHPRC` arrives at the spawned hop
  byte-identical, `c3ab` for `ë`, and that hop loads `curl`.
- A space in the path.

The warnings print after `launch.php` writes its `Site data` line and before
readiness, which places them in the `php-server` hop.

## User Stories

1. As a Windows reader whose account name carries an accent, I want a default
   start to serve a site, so that my own name is not a blocker.
2. As a Windows reader with a Cyrillic account name, I want the same.
3. As a Windows reader with a CJK account name, I want the same.
4. As a Windows reader, I want every extension the build ships to load, so that
   image handling, database access and TLS all work.
5. As a Windows reader, I want the site to answer 200 on the first start, so
   that I never meet a 500 I cannot read.
6. As a Windows reader, I want a failure I do cause to name the cache root, so
   that the next step is obvious.
7. As a developer, I want the failing layer named with evidence, so that the fix
   lands where the defect is.
8. As a developer, I want a reproduction that runs from this checkout, so that a
   fix can be shown to work.
9. As a developer, I want the reproduction to set the cache root by environment
   variable, so that no Windows account has to be created to run it.
10. As a developer, I want to know whether `php-cli` and `php-server` differ on
    the same path, so that the difference between the two hops is measured
    rather than assumed.
11. As a developer, I want to know whether the DLL open or the DLL search fails,
    so that the layer is named rather than guessed.
12. As a maintainer, I want conformance cases covering an accented, a Cyrillic
    and a CJK cache root, so that a later change cannot bring the fault back.
13. As a maintainer, I want to know whether the TLS bundle path shares the
    fault, so that trust does not break on the same accounts.
14. As a maintainer, I want the finding recorded wherever the fix does not
    reach, so that a reader meeting a related fault finds the note.

## Implementation Decisions

### The acceptance bar

A start succeeds on any Unicode account name. Accented Latin, Cyrillic and CJK
all serve. A fix that handles Latin-1 alone fails this PRD.

### The diagnosis comes first

Phase 1 reproduces the fault and isolates the failing layer. It produces
evidence, not a repair. The fix phases are written against that evidence.

Candidates to separate, each with its own probe:

- PHP startup inside FrankenPHP, where the DLL open happens.
- The launcher's handover of `PHPRC` and the extension directory to the
  `php-server` hop.
- The cache root, which Drupack chooses and could keep ASCII.

### The test vehicle

`DRUPACK_CACHE_DIR` sets the cache root, so the reproduction needs no Windows
account with a non-ASCII name. The RFC's own measurements used it.

### The TLS interaction

The bundled TLS work names the CA bundle through `${DRUPACK_CA_FILE}` in
`php.ini`, under the same cache root. Phase 1 measures whether that path opens
on an accented root, because a shared fault changes what the fix has to cover.

## Testing Decisions

A good test here starts the product and reads what a reader would read: the HTTP
status, the extension count, the log.

- Conformance cases, Windows marked, one per script: accented Latin, Cyrillic,
  CJK. Each starts a site with `DRUPACK_CACHE_DIR` pointed at such a root, and
  asserts a 200 and zero extension load failures in the log.
- Prior art: the existing conformance modules start sites and read logs the same
  way. `tests/conformance/harness.py` holds the helpers.
- No unit test can reach this. The fault appears only in a started server on
  Windows.

## Out of Scope

- MAX_PATH headroom. The RFC lists it in the same defect class, and it is a
  separate fault with a separate cause.
- Linux and macOS. Neither shows the fault.
- Any fix phase not yet written. Phase 1 produces the evidence that names them.

## What the fix does not reach

- A volume with 8.3 name creation disabled, where no candidate resolves to an
  ASCII path. The start stops with a line naming the cache root and
  `DRUPACK_CACHE_DIR`. Proven at the table-test level, not on such a volume:
  the setting is volume wide on a shared machine.
- PHP itself. The ANSI code page boundary in PHP startup stays where it is, so
  a reader who points `DRUPACK_CA_FILE` or any other PHP path at a non-ASCII
  location meets the same fault, and it fails silently.

## Further Notes

- No reader has an installed site today, so nothing migrates. A fix may change
  the cache root freely.
- Two streams are in flight on this repository: bundled TLS trust and path
  convergence. This work touches the same cache root both use, so it starts
  after they land.
