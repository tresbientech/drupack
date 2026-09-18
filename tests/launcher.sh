#!/usr/bin/env bash
set -euo pipefail

binary=$(realpath "${1:?Usage: tests/launcher.sh BINARY [RESULTS_DIRECTORY]}")
results=${2:-$(mktemp -d /tmp/drupack-launcher.XXXXXX)}
mkdir -p "$results"
results=$(realpath "$results")
listen=127.0.0.1:18097
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

# The only line cache.go writes to standard error, once per unpacked version.
unpacking_pattern='^Unpacking Drupack .+\. This happens once for each version\.$'

entry_count() {
  find "$1" -mindepth 1 -maxdepth 1 -type d | wc -l
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

## Case 1: cold start.
cache1="$results/case1/cache"
rm -rf "$cache1"
export DRUPACK_CACHE_DIR="$cache1"
"$binary" --help >"$results/case1.out" 2>"$results/case1.err"

lines=$(grep -cE "$unpacking_pattern" "$results/case1.err" || true)
[[ $lines -eq 1 ]] || fail 'A cold start did not print exactly one unpacking line to standard error'
leaked=$(grep -cE "$unpacking_pattern" "$results/case1.out" || true)
[[ $leaked -eq 0 ]] || fail 'A cold start leaked the unpacking line to standard output'
[[ $(entry_count "$cache1") -eq 1 ]] || fail 'A cold start did not leave exactly one cache entry'
key1=$(basename "$(find "$cache1" -mindepth 1 -maxdepth 1 -type d)")
[[ -f "$cache1/$key1/manifest.json" ]] || fail 'The cache entry has no manifest.json'
[[ -f "$cache1/$key1/drupack" ]] || fail 'The cache entry has no drupack executable'

## Case 2: warm start, same cache as case 1.
"$binary" --help >"$results/case2.out" 2>"$results/case2.err"
warm_lines=$(grep -cE "$unpacking_pattern" "$results/case2.err" || true)
[[ $warm_lines -eq 0 ]] || fail 'A warm start printed an unpacking line'
[[ $(entry_count "$cache1") -eq 1 ]] || fail 'A warm start changed the number of cache entries'
[[ -d "$cache1/$key1" ]] || fail 'A warm start replaced the cache entry'

## Case 3: two cold starts at once, fresh cache, independent of cases 1 and 2.
cache3="$results/case3/cache"
rm -rf "$cache3"
export DRUPACK_CACHE_DIR="$cache3"
"$binary" --help >"$results/case3-a.out" 2>"$results/case3-a.err" &
first=$!
"$binary" --help >"$results/case3-b.out" 2>"$results/case3-b.err" &
second=$!
first_code=0
wait "$first" || first_code=$?
second_code=0
wait "$second" || second_code=$?
[[ $first_code -eq 0 ]] || fail 'The first of two simultaneous cold starts exited non-zero'
[[ $second_code -eq 0 ]] || fail 'The second of two simultaneous cold starts exited non-zero'
# One process wins the lock and stages; the other finds the entry already warm.
race_lines=$(cat "$results/case3-a.err" "$results/case3-b.err" | grep -cE "$unpacking_pattern" || true)
[[ $race_lines -eq 1 ]] || fail 'Two simultaneous cold starts did not print exactly one unpacking line between them'
[[ $(entry_count "$cache3") -eq 1 ]] || fail 'Two simultaneous cold starts did not leave exactly one cache entry'
staging=$(find "$cache3" -mindepth 1 -maxdepth 1 -name '*.staging-*')
[[ -z $staging ]] || fail 'A staging directory survived two simultaneous cold starts'

## Cases 4 to 6 share one installed site, fresh cache, independent of cases 1 to 3.
cache456="$results/site/cache"
data="$results/site/data"
rm -rf "$cache456" "$data"
export DRUPACK_CACHE_DIR="$cache456"
start_site "$binary" install --admin-user launcher-admin --admin-password Launcher.test.password.2026
stop_site

## Case 4: dr on a command Drush does not have.
dr_code=0
"$binary" dr --data-dir "$data" this-command-does-not-exist >"$results/case4.log" 2>&1 || dr_code=$?
[[ $dr_code -ne 0 ]] || fail 'dr with an unknown Drush command exited zero'

## Case 5: one process, named by its cache entry.
start_site "$binary" serve
key456=$(cat "$cache456/active")
args=$(ps -o args= -p "$server")
# runtime/launch.php re-execs through DRUPACK_RUNTIME_BINARY, so the served
# process names the cache entry's drupack, never the path this test invoked.
[[ $args == "$cache456/$key456/drupack"* ]] || fail 'The serving process does not run the cache entry executable'
children=$(pgrep -c -P "$server" || true)
[[ $children -eq 1 ]] || fail 'The serving process did not have exactly one child, the readiness helper'

## Case 6: SIGINT stops the server.
kill -INT "$server"
gone=false
for attempt in {1..30}; do
  if ! kill -0 "$server" 2>/dev/null; then
    gone=true
    break
  fi
  sleep 1
done
[[ $gone == true ]] || fail 'SIGINT did not stop the server within 30 seconds'
wait "$server" 2>/dev/null || true
server=
if curl -s -o /dev/null --max-time 2 "http://$listen/"; then
  fail 'The port still answers after SIGINT stopped the server'
fi

printf 'Launcher checks passed. Results: %s\n' "$results"
