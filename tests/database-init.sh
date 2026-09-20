#!/usr/bin/env bash
set -euo pipefail

binary=$(realpath "${1:?Usage: tests/database-init.sh BINARY [RESULTS_DIRECTORY]}")
results=${2:-$(mktemp -d /tmp/drupack-database.XXXXXX)}
mkdir -p "$results"
results=$(realpath "$results")
work=$(mktemp -d "$results/drupack-work.XXXXXX")
trap 'rm -rf "$work"' EXIT

# SQLite needs no connection details and no credentials, so its first start serves instead of
# refusing. This script starts no server, so tests/conformance covers that start.
for database in mysql pgsql; do
  data="$work/$database"
  log="$results/$database.log"
  if timeout 20 "$binary" --data-dir "$data" --database "$database" >"$log" 2>&1; then
    printf 'Expected %s startup without connection details to fail\n' "$database" >&2
    exit 1
  fi
  if ! grep -Fxq "Missing database connection details for $database" "$log"; then
    printf 'Expected the missing-connection diagnostic for %s\n' "$database" >&2
    cat "$log" >&2
    exit 1
  fi
  if [[ -e $data ]]; then
    printf '%s startup without connection details wrote persistent data\n' "$database" >&2
    exit 1
  fi
done

data="$work/invalid"
if "$binary" --data-dir "$data" --database invalid >"$results/invalid.log" 2>&1; then
  printf 'Expected an unsupported database backend to fail\n' >&2
  exit 1
fi
if [[ -e $data ]]; then
  printf 'Unsupported database backend wrote persistent data\n' >&2
  exit 1
fi

data="$work/quoted\"dir"
if "$binary" --data-dir "$data" >"$results/quoted.log" 2>&1; then
  printf 'Expected a --data-dir with a double quote to fail\n' >&2
  exit 1
fi
if ! grep -Fq 'double quote' "$results/quoted.log"; then
  printf 'Expected the double-quote diagnostic\n' >&2
  cat "$results/quoted.log" >&2
  exit 1
fi
if [[ -e $data ]]; then
  printf 'A rejected data-dir with a double quote wrote persistent data\n' >&2
  exit 1
fi

data="$work/drush"
if "$binary" dr --data-dir "$data" status >"$results/drush.log" 2>&1; then
  printf 'Expected a dr command without a site to fail\n' >&2
  exit 1
fi
if ! grep -Fq "$data" "$results/drush.log"; then
  printf 'Expected the dr diagnostic to name the Site data directory\n' >&2
  cat "$results/drush.log" >&2
  exit 1
fi
if [[ -e $data ]]; then
  printf 'A dr command without a site wrote persistent data\n' >&2
  exit 1
fi

printf 'Database initialization argument checks passed. Results: %s\n' "$results"
