#!/usr/bin/env bash
set -euo pipefail

# Finds the newest release.yml run that QUERY selects, waits for it to complete,
# and exits 0 only on success. With --rerun, a failed run gets one rerun of its
# failed jobs. Reads GITHUB_API and GITHUB_TOKEN.
# Usage: build/wait-for-run.sh QUERY [--rerun]
# QUERY is a runs query string, such as event=push&branch=1.0.0.

query=${1:?Usage: build/wait-for-run.sh QUERY [--rerun]}
rerun=${2:-}
api() { curl -fsS --retry 5 --retry-all-errors -H "Authorization: Bearer $GITHUB_TOKEN" "$@"; }

# A dispatch or a push answers before its run exists.
for attempt in $(seq 30); do
    run=$(api "$GITHUB_API/actions/workflows/release.yml/runs?$query" | jq -r '.workflow_runs[0].id // empty')
    [ -n "$run" ] && break
    sleep 10
done
[ -n "$run" ] || { echo "No release.yml run matches $query."; exit 1; }
echo "Run: $(api "$GITHUB_API/actions/runs/$run" | jq -r .html_url)"

wait_for_run() {
    while sleep 120; do
        read -r status conclusion < <(api "$GITHUB_API/actions/runs/$run" | jq -r '"\(.status) \(.conclusion)"')
        [ "$status" = completed ] && break
    done
    echo "Run $run: $conclusion"
    [ "$conclusion" = success ]
}

wait_for_run && exit 0
[ "$rerun" = --rerun ] || exit 1
api -X POST "$GITHUB_API/actions/runs/$run/rerun-failed-jobs"
wait_for_run
