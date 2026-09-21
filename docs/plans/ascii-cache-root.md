# Plan: A non-ASCII cache root breaks the server

> Source PRD: [docs/prd/ascii-cache-root.md](../prd/ascii-cache-root.md)
> Origin: the "Out of scope" section of
> [docs/rfc/windows-path-convergence.md](../rfc/windows-path-convergence.md)

## Architectural decisions

Durable across every phase.

- **Acceptance bar**: a start serves on any Unicode account name. Accented
  Latin, Cyrillic and CJK all answer 200 with zero extension load failures.
- **Test vehicle**: `DRUPACK_CACHE_DIR` sets the cache root, so no Windows
  account with a non-ASCII name has to exist to run a probe.
- **Diagnosis first**: phase 1 produces evidence naming the failing layer. No
  product code changes in it.
- **No fix is committed in advance**: phase 2 is written after phase 1 reports,
  against what the evidence says. No implementer runs against an unwritten
  phase.
- **Platform**: the fault appears on Windows only. Linux and macOS runs prove
  nothing regressed.

## Suite

Windows carries the fault, so the Windows chain decides every phase. The Linux
chain runs first, to prove the change broke nothing there.

```sh
/tmp/drupack-suite-lock.sh bash -c 'docker build --target artifact --output type=local,dest=dist .'
cd packaging/launcher && go test ./...
/tmp/drupack-suite-lock.sh bash -c 'python3 tests/conformance ./dist/drupack test-results/conformance'
```

```powershell
./packaging/windows/build.ps1 -ApplicationDirectory application -Version dev `
  -WorkDirectory $env:TEMP\drupack -Output dist\drupack.exe
python tests/conformance dist\drupack.exe test-results\conformance
```

Other worktrees share this machine. Docker builds and conformance runs take the
shared lock. The go and unit runs do not.

---

## Phase 1: Name the failing layer

**User stories**: 7, 8, 9, 10, 11, 13

### What to build

No product change. A set of probes on Windows, each one variable apart, and a
written finding naming the layer that fails.

The three candidates to separate:

- PHP startup inside FrankenPHP, where the DLL open happens.
- The launcher's handover of `PHPRC` and the extension directory to the
  `php-server` hop.
- The cache root, which Drupack chooses and could keep ASCII.

Every probe records its command, its HTTP status, its extension load failure
count, and the exact warning text when one appears.

### Acceptance criteria

- [ ] The fault reproduces from this checkout: a start with
      `DRUPACK_CACHE_DIR` under an accented path answers 500, and its log holds
      one load failure per DLL extension the build ships.
- [ ] The same build with an ASCII cache root answers 200 with zero failures,
      run in the same session, so the two differ by the root alone.
- [ ] `php-cli` and `php-server` are both measured on the accented root, and the
      report states whether they differ.
- [ ] An accented cache root with the extension directory pointed at an ASCII
      copy of `ext/` is measured. Its result decides whether the loader's own
      argument carries the fault.
- [ ] An ASCII cache root with an accented extension directory is measured, as
      the reverse of the case above.
- [ ] A Cyrillic root and a CJK root are measured, so the bar covers more than
      Latin-1.
- [ ] The TLS bundle path is measured on an accented root: a `php-cli` fetch
      over HTTPS reports whether `curl.cainfo` opens from there.
- [ ] The report names one layer as the failing one, with the probe that shows
      it, and states what the evidence rules out.

---

## Phase 2: An ASCII cache root on Windows

**User stories**: 1, 2, 3, 4, 5, 12

### What to build

Phase 1 named the layer: PHP startup resolves the `PHPRC`-derived
configuration path through the ANSI code page, so a root it cannot represent
breaks extension loading, and for Cyrillic and CJK roots it fails to locate
`php.ini` at all. The product cannot fix PHP, so it stops handing PHP a path
that breaks.

`runtime.Root()` gains the rule on Windows, and nowhere else. The launcher
creates the cache directory, then asks Windows for its 8.3 short name, because
a short name exists only for a path that exists. An ASCII result becomes the
cache root, and every later path derives from it.

Three conditions leave that result non-ASCII: 8.3 creation disabled on the
volume, a name Windows leaves in Unicode, and a path with no alias yet. Each
one falls to the next rung.

The rungs, in order:

1. The chosen cache root, short name resolved. A real Windows run forced one
   exception: a candidate already in ASCII is kept as-is, with no resolve
   call, since Windows aliases any long path segment, ASCII or not, and a
   merely long ASCII root asked for no rewrite.
2. The temporary directory, short name resolved. The default temporary
   directory sits under the same profile, so it carries the same account name
   and needs the same treatment.
3. A stop. The start fails with one line naming the cache root it could not
   make ASCII and the variable that overrides it.

A start that stops is better than a start that serves a site with 12 extensions
missing, which is what happens today.

Linux and macOS keep `runtime.Root()` exactly as it is.

### Acceptance criteria

- [ ] `cd packaging/launcher && go test ./...` exits 0, including a table over
      the rung order: an ASCII candidate is kept, a non-ASCII candidate with an
      ASCII alias takes the alias, and a candidate with no ASCII form anywhere
      reaches the stop.
- [ ] That table fails when the rung order is reversed.
- [ ] A site started with `DRUPACK_CACHE_DIR` under an accented Latin path
      answers 200 with zero extension load failures in its log.
- [ ] The same holds for a Cyrillic path and for a CJK path.
- [ ] The conformance cases covering those three scripts are marked Windows and
      pass against `dist\drupack.exe`.
- [ ] Those three cases fail against a build without this change, which phase 1
      already measured at HTTP 500.
- [ ] A start whose cache root cannot be made ASCII prints one line naming the
      root and `DRUPACK_CACHE_DIR`, and exits non-zero.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance` shows
      no change on Linux, since no Linux path runs the new rule.

---

## Phase 3: Record what the fix does not reach

**User stories**: 6, 14

### What to build

The note a reader needs when they meet a related fault. The RFC's out-of-scope
section points here. If the fix leaves any path content failing, a start that
meets it says so and names the cache root.

### Acceptance criteria

- [ ] The RFC's out-of-scope section names this plan and its outcome.
- [ ] Any path content still unsupported after phase 2 is listed in the PRD,
      with the measurement that shows it.
- [ ] A start that meets an unsupported cache root prints one line naming the
      root and what to change.
