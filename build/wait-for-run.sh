#!/usr/bin/env bash
set -euo pipefail

# Finds the newest release.yml run that QUERY selects, waits for it, and exits 0
# only on success. It names each failed job as soon as it ends, while the run
# goes on to its other jobs. A failed run gets one rerun of its failed jobs,
# which GitHub allows once the run has completed. Reads GITHUB_API and
# GITHUB_TOKEN.
# Usage: build/wait-for-run.sh QUERY
# QUERY is a runs query string, such as event=push&branch=1.0.0.

query=${1:?Usage: build/wait-for-run.sh QUERY}
api() { curl -fsS --retry 5 --retry-all-errors -H "Authorization: Bearer $GITHUB_TOKEN" "$@"; }

# A dispatch or a push answers before its run exists.
for attempt in $(seq 30); do
    run=$(api "$GITHUB_API/actions/workflows/release.yml/runs?$query" | jq -r '.workflow_runs[0].id // empty')
    [ -n "$run" ] && break
    sleep 10
done
[ -n "$run" ] || { echo "No release.yml run matches $query."; exit 1; }
echo "Run: $(api "$GITHUB_API/actions/runs/$run" | jq -r .html_url)"

# Names each job of the run's latest attempt that ended red. A run holds fewer
# than 100 jobs.
failed_jobs() {
    api "$GITHUB_API/actions/runs/$run/jobs?filter=latest&per_page=100" | jq -r '.jobs[]
        | select(.conclusion == "failure" or .conclusion == "timed_out" or .conclusion == "cancelled")
        | "Failed: \(.name) \(.html_url)"'
}

# A plain call keeps set -e, so a failed status read ends the script.
wait_for_run() {
    reported=
    while sleep 120; do
        state=$(api "$GITHUB_API/actions/runs/$run" | jq -r '"\(.status) \(.conclusion)"')
        read -r status conclusion <<<"$state"
        [ "$status" = completed ] && break
        failed=$(failed_jobs)
        if [ "$failed" != "$reported" ]; then
            printf '%s\nRun %s goes on to its other jobs.\n' "$failed" "$run"
            reported=$failed
        fi
    done
    echo "Run $run: $conclusion"
    [ "$conclusion" = success ] || failed_jobs
}

wait_for_run
[ "$conclusion" = success ] && exit 0
api -X POST "$GITHUB_API/actions/runs/$run/rerun-failed-jobs"
wait_for_run
[ "$conclusion" = success ]
