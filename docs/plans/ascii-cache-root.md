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

## Phase 2: The fix

**User stories**: 1, 2, 3, 4, 5, 12

Written after phase 1 reports. The fix lands where the evidence puts the fault,
and ships with the conformance cases that prove it: accented Latin, Cyrillic and
CJK cache roots, each asserting a 200 and zero extension load failures.

This phase carries no acceptance criteria yet. Writing them before the
diagnosis would commit the work to a repair nobody can describe.

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
