# A script plans the release run

Accepted on 2026-11-09. Amends [ADR 0018](0018-release-graph.md).

## Context

The `targets` step of the `gate` job held 100 lines of bash and jq. It chose
the version, the image tags, the candidate run to reuse, four matrices and the
macOS test rows. It ran only on GitHub, so a wrong plan showed in a finished
run. A failed lookup of the candidate run read as no candidate, and the run
rebuilt what it could have published.

## Decision

- `build/release-plan.py` owns the release plan. `plan(event, targets, fetch)`
  returns the nine values the jobs read.
- `fetch` is the one port to GitHub. The command line passes a function that
  runs `gh api PATH`. Tests pass a fake over recorded responses.
- A failed GitHub call stops the gate. `plan` also stops on a response of
  another shape and names the path.
- The step passes the event as flags and appends the nine lines to
  `$GITHUB_OUTPUT`. It prints the flags, so a log reproduces the plan on any
  machine where `gh` is logged in.
- Expressions reach the script through `env:`, as `BEFORE`, `PLATFORM` and
  `NAMED`. No `${{ }}` sits in a `run:` body.
- `tests/conformance/test_release_plan.py` covers each branch.
  `tests/conformance/test_targets.py` checks that the workflow's `run-name`
  formats to the script's title.

## Consequences

- A change to the plan fails a unit test before it fails a release.
- A tag whose candidate lookup fails stops the gate instead of rebuilding.
- The version rule copied in `build.yml` and `ci/drupack.gitlab-ci.yml` stays
  as it is. Site repositories run those copies.
