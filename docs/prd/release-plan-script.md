# A script plans the release run

## Problem Statement

A maintainer cannot test what a release run will build. The `targets` step of
the `gate` job in `.github/workflows/release.yml` holds about 100 lines of bash
and jq. It decides the version, the image tags, the reuse of a candidate run,
four build matrices, the macOS test rows and whether anything builds. It has
five branches and no test. It runs only on GitHub, so a wrong plan is found by
reading a finished run.

## Solution

A Python script owns the decision. The gate step passes it the event and reads
its outputs. The script runs on any machine with the same flags, and a table
test covers every branch. The plan it prints for a given event is the plan the
bash prints today.

## Out of Scope

- Any change to what a run builds.
- A zero-argument local dry run that reads git. The flags printed in the step log reproduce a run.
- The version rule copied in `build.yml` and `ci/drupack.gitlab-ci.yml`, which site repositories run.
- Generating the workflow's `platform` options, which GitHub reads before any step.

## User Stories

1. As a maintainer, I want the release plan computed by a tested function, so that a change to the gate fails a test before it fails a release.
2. As a maintainer, I want to reproduce a run's plan from its log, so that I diagnose a wrong matrix without pushing.
3. As a release owner, I want the same plan as today for every event, so that the move changes no release.

## Implementation Decisions

- D1: `build/release-plan.py` holds `plan(event, targets, fetch)`, with the standard library alone. `event` carries the ref type, ref name, event name, commit, previous commit, platform input, named version and repository. `targets` is the parsed `targets.json`. Evidence: the step's `env:` block and the `GITHUB_*` variables its bash reads; `build/upstream.py` is the precedent for a tested release script.
- D2: The result holds the nine keys the jobs read: `version`, `build`, `candidate`, `image_tags`, `linux`, `linux_runtime`, `macos`, `macos_tests`, `windows`. Matrices are lists, and the command line encodes them. Evidence: the nine `printf` lines that end the step.
- D3: The command line takes one flag per event field and `--output FILE`. It appends the nine `key=value` lines to that file. It prints the flags, each path fetched, the reason for the plan and the nine lines. A failure exits non-zero with no pipe between the script and the step. Evidence: `cat "$GITHUB_OUTPUT"` ends the step today, so the log already shows the outputs.
- D4: `fetch(path)` returns the parsed JSON of one GitHub REST path. The command line passes a function that runs `gh api PATH`, and a failed call stops the script. Evidence: the bash calls `gh api` three times, with `GH_TOKEN` in the step's environment.
- D5: `plan` calls `fetch` on two branches only. A tag fetches the runs of that commit, then the artifacts of a matching candidate. A branch push with a real previous commit fetches the compare. Evidence: the three `gh api` calls sit under those conditions.
- D6: `plan` validates what `fetch` returns, since it is remote data: `workflow_runs` entries hold a string `display_title` and an integer `id`, `artifacts` entries hold a boolean `expired`, `files` entries hold a string `filename`. Another shape stops the script and names the path.
- D7: The run title, the all-zero commit and the documentation path pattern are each defined once in the script. Evidence: `Release candidate` appears at `run-name` and in the lookup; the pattern and the zero commit appear once each in the bash.
- D8: `tests/conformance/test_release_plan.py` holds one row per branch of the bash, with a fake `fetch` over recorded responses that raises on an unscripted path and records its calls. `build/unit-tests.sh` runs it. Evidence: that script lists the Python unit files by name.
- D9: Before the bash leaves the workflow, each row's expected plan is checked against the bash itself, run with a stub `gh` on the same inputs.
- D10: `tests/conformance/test_targets.py` keeps its platform options check and gains one: the workflow's `run-name` formats to the script's title.
- D11: The gate step's `run:` body, its `matrix()` and `runtimes()` helpers and its `SHA` and `TARGETS` entries leave the workflow. `BEFORE`, `PLATFORM` and `NAMED` stay in `env:`, since an expression pasted into a run script is a shell injection path.
- D12: ADR 0054 records the decision, and ADR 0018 gains an amendment line. Evidence: ADR 0018 describes the release graph the gate opens.

### Considered options

- A local dry run that reads git and guesses the previous commit. It lost because a plan computed from `git diff` can differ from the compare call CI makes, and it adds a second HTTP client.
- The workflow fetching the JSON and handing files to a pure script. It lost because the step would again decide which calls to make, in bash.

## Testing Decisions

- A good test gives `plan` an event, the targets and canned responses, and reads the nine values. It also asserts which paths were fetched.
- Tested: `plan`. The command line is covered by the step itself on the next push.
- Prior art: `tests/upstream/test_upstream.py` for recorded indexes, and `tests/conformance/test_release_files.py` for a release script's unit test.

## Further Notes

A push to `main` exercises the branch-push path on the first run after the
merge. The tag path first runs at the next release, so D9's check against the
bash is what covers it before then. A wrong plan is diagnosed from the step log:
the flags it prints reproduce the plan on any machine with `gh`.
