#!/usr/bin/env bash
set -euo pipefail

# Waits for the GitHub Actions run RUN_ID to complete, prints its conclusion,
# and exits 0 only on success. Reads GITHUB_API and GITHUB_TOKEN.
# Usage: build/wait-for-run.sh RUN_ID

run=${1:?Usage: build/wait-for-run.sh RUN_ID}
while sleep 120; do
    read -r status conclusion < <(curl -fsS --retry 5 --retry-all-errors -H "Authorization: Bearer $GITHUB_TOKEN" \
        "$GITHUB_API/actions/runs/$run" | jq -r '"\(.status) \(.conclusion)"')
    [ "$status" = completed ] && break
done
echo "Run $run: $conclusion"
[ "$conclusion" = success ]
