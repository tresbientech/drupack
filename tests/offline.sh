#!/usr/bin/env bash
set -euo pipefail

binary=$(realpath "${1:?Usage: tests/offline.sh BINARY [RESULTS_DIRECTORY] [PHASE] [devel]}")
test_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
results=${2:-$(mktemp -d /tmp/portable-drupal-results.XXXXXX)}
mkdir -p "$results"
results=$(realpath "$results")
printf 'Test results: %s\n' "$results"

docker run --rm --network none --shm-size 2g \
  --user "$(id -u):$(id -g)" \
  -e XDG_CONFIG_HOME=/tmp/chromium-config -e XDG_CACHE_HOME=/tmp/chromium-cache \
  -e "PORTABLE_TEST_PHASE=${3:-6}" \
  -e "PORTABLE_TEST_EXTRA=${4:-}" \
  --mount "type=bind,src=$binary,dst=/artifact/portable-drupal,readonly" \
  --mount "type=bind,src=$test_dir,dst=/tests,readonly" \
  --mount "type=bind,src=$results,dst=/results" \
  --entrypoint /usr/bin/python3 \
  selenium/standalone-chromium:143.0.7499.169 \
  /tests/browser.py /artifact/portable-drupal /results
