#!/usr/bin/env bash
set -euo pipefail

binary=$(realpath "${1:?Usage: tests/database-init.sh BINARY [RESULTS_DIRECTORY]}")
results=${2:-$(mktemp -d /tmp/portable-drupal-database.XXXXXX)}
mkdir -p "$results"
results=$(realpath "$results")
work=$(mktemp -d "$results/portable-drupal-work.XXXXXX")
trap 'rm -rf "$work"' EXIT

for database in sqlite mysql pgsql; do
  data="$work/$database"
  log="$results/$database.log"
  if timeout 20 "$binary" --data-dir "$data" --database "$database" >"$log" 2>&1; then
    printf 'Expected %s startup without credentials to fail\n' "$database" >&2
    exit 1
  fi
  expected="Missing Drupal administrator credentials"
  if [[ $database != sqlite ]]; then
    expected="Missing database connection details for $database"
  fi
  if ! grep -Fxq "$expected" "$log"; then
    printf 'Expected missing-credential diagnostic for %s\n' "$database" >&2
    cat "$log" >&2
    exit 1
  fi
  if [[ -e $data ]]; then
    printf '%s startup without credentials wrote persistent data\n' "$database" >&2
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

printf 'Database initialization argument checks passed. Results: %s\n' "$results"
