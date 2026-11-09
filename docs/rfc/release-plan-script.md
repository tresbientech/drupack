# A script plans the release run

Proposed on 2026-11-09.

## Problem

The `targets` step of the `gate` job (`.github/workflows/release.yml:86-176`) holds 90 lines of bash and jq. It decides:

- the version name
- the job image tags
- whether a tag run publishes its candidate run's files
- the Linux, macOS and Windows matrices
- the `linux_runtime` matrix
- whether anything builds

It has five branches: tag with a candidate, tag without one, dispatch, new branch, and branch push. No test runs any of them. `test_targets.py` checks one fact: the workflow's `platform` options match `targets.json`.

The step cannot run outside GitHub Actions. A wrong plan is found by reading a finished run.

## Proposed interface

New file `build/release-plan.py`, standard library only.

```python
TITLE = "Release candidate {version}"
ZERO = "0" * 40
DOCS = re.compile(r"docs/|LICENSE$|[^/]+\.md$")

def plan(event: dict, targets: dict, fetch) -> dict
```

- `event` has the keys `ref_type`, `ref_name`, `event_name`, `sha`, `before`, `platform`, `named`, `repo`.
- `targets` is the parsed `targets.json`.
- `fetch(path)` returns the parsed JSON of one GitHub REST path.
- The result has the eight keys the jobs read: `version`, `build`, `candidate`, `image_tags`, `linux`, `macos`, `windows`, `linux_runtime`. Matrices are lists. `main` encodes them.

### Command line

```yaml
- id: targets
  env:
    GH_TOKEN: ${{ github.token }}
  run: |
    python3 build/release-plan.py --ref-type "$GITHUB_REF_TYPE" --ref-name "$GITHUB_REF_NAME" \
      --event-name "$GITHUB_EVENT_NAME" --sha "$GITHUB_SHA" --before "${{ github.event.before }}" \
      --platform "${{ inputs.platform || 'all' }}" --version "${{ inputs.version }}" \
      --repo "$GITHUB_REPOSITORY" | tee -a "$GITHUB_OUTPUT"
```

Standard output carries the eight `key=value` lines. Standard error carries the flags, each path fetched, and the reason for the plan.

### What `plan` hides

- the version and image tag rules
- the candidate lookup by title, and the expiry of its artifacts
- matrix selection: platform input, new branch, documentation-only push
- the `linux_runtime` rows, with the amd64 musl row the payload job needs

## Dependency strategy

True external: the GitHub REST API, behind `fetch`.

- In production `main` passes a function that runs `gh api PATH` and parses its output. A failed call fails the step.
- In tests a dict of recorded responses stands in. It raises on a path the test did not script, and it records the paths called.
- `plan` calls `fetch` on two branches only. A tag fetches the runs list, then the candidate's artifacts. A branch push fetches the compare.

API responses are remote data, so `plan` validates them:

- `workflow_runs` is a list whose entries hold a string `display_title` and an integer `id`
- `artifacts` is a list whose entries hold a boolean `expired`
- `files` is a list whose entries hold a string `filename`

A response of another shape stops the script and names the path.

## Testing strategy

New file `tests/conformance/test_release_plan.py`, with fixtures recorded from real responses. It holds one row per branch of today's bash.

### Rows

1. Tag: the version is the tag name and the image tag is `sha`.
2. Dispatch, named version, platform `all`: tags `dev sha`.
3. Dispatch, named version, one platform: tag `dev`.
4. Dispatch, no name: version `dev-<sha12>`, tag `dev`.
5. Tag with a matching candidate and live artifacts: candidate set, matrices empty, `build` false.
6. Tag whose candidate has an expired artifact: full build.
7. Tag with no run of that title: full build.
8. Dispatch, platform `all`: five targets, no fetch.
9. Dispatch, `linux-arm64`: one row.
10. Dispatch, `windows-amd64`: one row, plus the amd64 musl runtime row.
11. Push to a new branch: Linux amd64, no fetch.
12. Push that changes `docs/x`, `LICENSE` and `README.md`: nothing builds.
13. Push that changes `a/README.md`: Linux amd64.
14. `linux_runtime` for Linux arm64: arm64 on both libcs, plus amd64 musl.
15. `linux_runtime` for Linux amd64: no duplicate row.
16. `linux_runtime` when nothing builds: empty.
17. A failed compare stops the script.
18. A malformed response stops the script.

### Existing test

`test_targets.py` keeps its platform options check, since GitHub reads `options:` before any step runs. It gains one check: the workflow's `run-name` formats to `TITLE`.

## Implementation recommendations

The script owns the whole decision. The workflow passes the event and reads eight outputs.

Removed from `release.yml`:

- the `run:` body of the `targets` step, with its `matrix()` and `runtimes()` helpers
- the `BEFORE`, `SHA`, `PLATFORM`, `NAMED` and `TARGETS` entries of its `env:`

The title stays written twice. `run-name` is a GitHub expression and cannot import `TITLE`, so the test above holds the two together.

The test rows are written against the bash before the bash is removed. For each row, the expected plan comes from a real run's `targets` step output.

A wrong plan is diagnosed from the step log. The log prints the flags, and the same flags reproduce the plan on any machine with `gh`.
