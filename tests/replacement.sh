#!/usr/bin/env bash
set -euo pipefail

binary=$(realpath "${1:?Usage: tests/replacement.sh BINARY [RESULTS_DIRECTORY]}")
results=${2:-$(mktemp -d /tmp/drupack-replacement.XXXXXX)}
mkdir -p "$results"
results=$(realpath "$results")
data="$results/data"
listen=127.0.0.1:18096
site_name='Drupack replacement check'
server=

cleanup() {
  if [[ -n $server ]]; then
    kill "$server" 2>/dev/null || true
    wait "$server" 2>/dev/null || true
  fi
}
trap cleanup EXIT

fail() {
  printf '%s\n' "$1" >&2
  exit 1
}

start_site() {
  local executable=$1 log="$results/$2.log"
  shift 2
  "$executable" --data-dir "$data" --listen "$listen" "$@" >"$log" 2>&1 &
  server=$!
  for attempt in {1..150}; do
    if [[ $(curl -s -o /dev/null -w '%{http_code}' "http://$listen/user/login") == 200 ]]; then
      return
    fi
    if ! kill -0 "$server" 2>/dev/null; then
      break
    fi
    sleep 2
  done
  cat "$log" >&2
  fail "$executable did not serve /user/login"
}

stop_site() {
  kill "$server"
  wait "$server" 2>/dev/null || true
  server=
}

mkdir -p "$results/old" "$results/new" "$results/before"
cp "$binary" "$results/old/drupack"
start_site "$results/old/drupack" old --admin-user replacement-admin --admin-password Replacement.test.password.2026
stop_site
"$results/old/drupack" dr --data-dir "$data" config:set system.site name "$site_name" --yes >"$results/config-set.log" 2>&1
printf 'public-replacement-sentinel' >"$data/files/replacement.txt"
printf 'private-replacement-sentinel' >"$data/private/replacement.txt"
cp "$data/settings.php" "$data/hash_salt" "$results/before/"

cp "$binary" "$results/new/drupack"
rm -rf "$results/old"
# A new version extracts its application under a new directory, so the old extraction must not be reused.
rm -rf "$data"/runtime/frankenphp_*

start_site "$results/new/drupack" new
curl -s "http://$listen/" | grep -Fq "$site_name" || fail 'The front page lost the site name'
stop_site

[[ $("$results/new/drupack" dr --data-dir "$data" config:get system.site name --format=string) == "$site_name" ]] \
  || fail 'Configuration changed after replacement'
[[ $("$results/new/drupack" dr --data-dir "$data" user:information replacement-admin --field=name) == replacement-admin ]] \
  || fail 'The administrator account is missing after replacement'
[[ $(cat "$data/files/replacement.txt") == public-replacement-sentinel ]] || fail 'A public file changed after replacement'
[[ $(cat "$data/private/replacement.txt") == private-replacement-sentinel ]] || fail 'A private file changed after replacement'
cmp -s "$results/before/settings.php" "$data/settings.php" || fail 'settings.php changed after replacement'
cmp -s "$results/before/hash_salt" "$data/hash_salt" || fail 'hash_salt changed after replacement'
compgen -G "$data/runtime/frankenphp_*" >/dev/null || fail 'The new executable did not extract its application'

printf 'Replacement checks passed. Results: %s\n' "$results"
