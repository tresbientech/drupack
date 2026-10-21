# Plan: One copy of the Windows extension facts

**Spec:** docs/prd/windows-extension-facts.md

## Architectural decisions

- **D1**: `build.ps1` drops `$absentExtensions` and the filter on it; php.ini
  lines stay limited to names with an `ext\php_NAME.dll`.
- **D2**: `build.ps1` drops `$preloadedExtensions` and its clause in the DLL
  filter.
- **D3**: `build.ps1` drops its load check.
- **D4**: the comments that named the dropped code state what decides a php.ini
  line.
- **D5**: `harness.py` keeps its facts unchanged and becomes their only copy.
- **D6**: an ADR records the decision, and the backlog entry leaves
  `docs/backlog.md`.

---

## Phase 1: The harness holds the only copy

**User stories**: 1, 2
**Decisions**: D1, D2, D3, D4, D5, D6

### What to build

The Windows build writes its php.ini from the allowlist and the zip's DLLs
alone, with no list of its own and no load check. The suite's exact extension
check on the shipped executable is the one check. An ADR states the rule, and
the backlog entry goes.

### Acceptance criteria

- [ ] `grep -cE 'absentExtensions|preloadedExtensions|get_loaded_extensions|Runtime is missing' build/windows/build.ps1`
  prints `0`.
- [ ] `grep -c 'php_\$_.dll' build/windows/build.ps1` prints `1`: the DLL filter
  stays.
- [ ] `git diff main -- tests/conformance/harness.py` prints nothing.
- [ ] `grep -c 'One parser for the extension allowlist' docs/backlog.md` prints
  `0`.
- [ ] `ls docs/adr/0045-*.md` lists one ADR, and `grep -c 'harness.py' docs/adr/0045-*.md`
  prints at least `1`.
- [ ] After the merge, a `release.yml` dispatch with `platform=windows-amd64`
  on main passes Windows amd64 and both Windows arm64 shards.
