# Plan: A script plans the release run

**Spec:** docs/prd/release-plan-script.md

## Architectural decisions

Durable decisions that apply across all phases:

- **D1**: `build/release-plan.py` holds `plan(event, targets, fetch)`, standard library alone.
- **D2**: the result holds the nine keys the jobs read; matrices are lists.
- **D3**: one flag per event field and `--output FILE`; a failure exits non-zero with no pipe.
- **D4**: `fetch(path)` runs `gh api PATH` in production, and a failed call stops the script.
- **D5**: `fetch` is called on the tag branch and on the branch-push branch only.
- **D6**: `plan` validates what `fetch` returns.
- **D7**: the run title, the zero commit and the documentation pattern are each defined once.
- **D8**: `tests/conformance/test_release_plan.py`, run by `build/unit-tests.sh`.
- **D9**: each row's expected plan is checked against the bash before the bash leaves.
- **D10**: `test_targets.py` keeps its options check and gains the `run-name` check.
- **D11**: the step's `run:` body, its helpers and its `SHA` and `TARGETS` entries leave the workflow; `BEFORE`, `PLATFORM` and `NAMED` stay in `env:`.
- **D12**: ADR 0054, and an amendment line in ADR 0018.
- **Tests**: `bash build/unit-tests.sh` runs the Python unit files. The parity harness lives in the session's scratchpad, outside the repository.

---

## Phase 1: The script, tested against the bash

**User stories**: 1, 2, 3
**Decisions**: D1, D2, D3, D4, D5, D6, D7, D8, D9

### What to build

The script and its table test exist, and the workflow is untouched. A parity harness extracts the `targets` step's `run:` body from the workflow, runs it with a stub `gh` for each test row, and compares its nine outputs with `plan`'s.

### Acceptance criteria

- [ ] `python3 -m unittest discover -s tests/conformance -p test_release_plan.py` passes, with a row for each of: tag, named dispatch on `all`, named dispatch on one platform, unnamed dispatch, tag with a live candidate, tag with an expired candidate, tag with no matching run, new branch, documentation-only push, push with a nested `README.md`, the `linux_runtime` rows for arm64, amd64 and no build, the `macos_tests` rows for amd64 and arm64, a failed compare, a malformed response.
- [ ] `bash build/unit-tests.sh` passes and `grep -c test_release_plan.py build/unit-tests.sh` prints 1.
- [ ] The parity harness prints `N of N rows match` for every row that does not expect a failure, and the line is in the phase report.
- [ ] `python3 build/release-plan.py` with the flags of a new-branch push and `--output` on a temporary file writes nine lines and runs no `gh`.
- [ ] A dispatch row and a new-branch row assert that `fetch` was never called.

---

## Phase 2: The gate calls the script

**User stories**: 1, 2, 3
**Decisions**: D10, D11, D12

### What to build

The `targets` step runs the script with the event's values and `--output "$GITHUB_OUTPUT"`. Its bash, both jq helpers and the `SHA` and `TARGETS` entries are gone. `test_targets.py` checks the `run-name` against the script's title. ADR 0054 records the decision, and this plan file is deleted.

### Acceptance criteria

- [ ] `sed -n '/id: targets/,/^  [a-z-]*:$/p' .github/workflows/release.yml | grep -c 'jq\|gh api'` prints 0.
- [ ] `grep -c 'release-plan.py' .github/workflows/release.yml` prints 1.
- [ ] `bash build/unit-tests.sh` passes, with the `run-name` check among the tests of `test_targets.py`.
- [ ] `ls docs/adr/0054-*.md` names one file and `grep -c 0054 docs/adr/0018-release-graph.md` prints at least 1.
- [ ] After the merge reaches GitHub, the `gate` job of that push's `release.yml` run is green and its log shows the flags and nine outputs.
