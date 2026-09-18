#!/usr/bin/env bash
set -euo pipefail

binary=$(realpath "${1:?Usage: tests/launcher.sh BINARY [RESULTS_DIRECTORY]}")
results=${2:-$(mktemp -d /tmp/drupack-launcher.XXXXXX)}
mkdir -p "$results"
results=$(realpath "$results")
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
launcher_src=$(cd "$script_dir/../packaging/launcher" && pwd)
listen=127.0.0.1:18097
server=

have_go=false
command -v go >/dev/null 2>&1 && have_go=true
have_docker=false
command -v docker >/dev/null 2>&1 && have_docker=true

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

## Case 0: startup ignores a launch.php in the current directory.
hostile_root="$results/case0"
mkdir -p "$hostile_root"
printf '%s\n' '<?php fwrite(STDOUT, "cwd launch executed\n"); exit(42);' >"$hostile_root/launch.php"
hostile_data="$hostile_root/data"
(
  cd "$hostile_root"
  "$binary" --no-browser --data-dir "$hostile_data" --admin-user launcher-admin --admin-password Launcher.test.password.2026
) >"$results/case0.log" 2>&1 &
hostile_server=$!
for attempt in {1..150}; do
  if [[ $(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:7225/user/login") == 200 ]]; then
    break
  fi
  if ! kill -0 "$hostile_server" 2>/dev/null; then
    cat "$results/case0.log" >&2
    fail 'A launch.php in the current directory replaced the bundled startup script'
  fi
  sleep 2
done
kill "$hostile_server" 2>/dev/null || true
wait "$hostile_server" 2>/dev/null || true
! grep -Fq 'cwd launch executed' "$results/case0.log" || fail 'The current directory launch.php was executed'

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

# pack_fixture builds a launcher carrying a one-line shell script as its
# entry, so a case can install or replace a version without the 400 MB
# production executable.
pack_fixture() {
  local version=$1 output=$2 runtime
  runtime=$(mktemp -d)
  cat >"$runtime/drupack" <<FIXTURE
#!/bin/sh
echo "fixture $version"
exit 0
FIXTURE
  chmod +x "$runtime/drupack"
  (cd "$launcher_src" && go run ./cmd/pack -runtime "$runtime" -entry drupack \
    -version "$version" -source . -output "$output") >/dev/null
  rm -rf "$runtime"
}

# build_corrupted_fixture packs a fixture like pack_fixture, then flips one
# hex digit of its entry file's checksum inside the built binary. The
# checksum is embedded as literal hex text, so the edit is unique and keeps
# every offset after it unchanged, unlike patching the compressed payload.
build_corrupted_fixture() {
  local version=$1 output=$2 runtime correct
  runtime=$(mktemp -d)
  cat >"$runtime/drupack" <<FIXTURE
#!/bin/sh
echo "fixture $version"
exit 0
FIXTURE
  chmod +x "$runtime/drupack"
  correct=$(sha256sum "$runtime/drupack" | cut -d' ' -f1)
  (cd "$launcher_src" && go run ./cmd/pack -runtime "$runtime" -entry drupack \
    -version "$version" -source . -output "$output") >/dev/null
  rm -rf "$runtime"
  python3 - "$output" "$correct" <<'PY'
import sys

path, correct = sys.argv[1], sys.argv[2].encode()
with open(path, "r+b") as handle:
    data = handle.read()
    offset = data.find(correct)
    if offset < 0:
        sys.exit("expected checksum not found in the fixture binary")
    handle.seek(offset)
    handle.write(b'0' if correct[0:1] != b'0' else b'1')
PY
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

## Case 7: DRUPACK_CACHE_DIR takes precedence over the default cache root.
if $have_go; then
  fixture7="$results/case7/fixture"
  pack_fixture 7.0.0 "$fixture7"
  cache7="$results/case7/cache"
  xdg7="$results/case7/xdg"
  rm -rf "$cache7" "$xdg7"
  mkdir -p "$xdg7"
  export DRUPACK_CACHE_DIR="$cache7" XDG_CACHE_HOME="$xdg7"
  "$fixture7" --help >"$results/case7.out" 2>"$results/case7.err"
  [[ $(entry_count "$cache7") -eq 1 ]] || fail 'DRUPACK_CACHE_DIR did not receive the unpacked runtime'
  [[ ! -e "$xdg7/Drupack" ]] || fail 'The default cache root gained an entry although DRUPACK_CACHE_DIR was set'
  unset XDG_CACHE_HOME
else
  printf 'Skipping case 7: go is not installed\n' >&2
fi

## Case 8: HOME refuses writes, so Root() falls back to TMPDIR.
if $have_go; then
  if [[ $(id -u) -eq 0 ]]; then
    printf 'Skipping case 8: running as root, which ignores directory mode bits\n' >&2
  else
    fixture8="$results/case8/fixture"
    pack_fixture 8.0.0 "$fixture8"
    home8="$results/case8/home"
    tmp8="$results/case8/tmp"
    rm -rf "$home8" "$tmp8"
    mkdir -p "$home8" "$tmp8"
    chmod 0500 "$home8"
    unset DRUPACK_CACHE_DIR XDG_CACHE_HOME
    code8=0
    HOME="$home8" TMPDIR="$tmp8" "$fixture8" --help >"$results/case8.out" 2>"$results/case8.err" || code8=$?
    chmod 0700 "$home8"
    [[ $code8 -eq 0 ]] || fail 'The launcher did not exit 0 when HOME refuses writes'
    # The temporary directory is shared, so the launcher names its root per uid.
    [[ $(entry_count "$tmp8/Drupack-$(id -u)/runtime") -eq 1 ]] || fail 'The runtime did not land under TMPDIR when HOME refuses writes'
  fi
else
  printf 'Skipping case 8: go is not installed\n' >&2
fi

## Case 9: a cache root with no room for the runtime.
if $have_docker; then
  debian=$(sed -n 's/^debian=//p' "$script_dir/network.sh")
  data9="$results/case9/data"
  rm -rf "$data9"
  mkdir -p "$data9"
  code9=0
  docker run --rm --tmpfs /cache:size=4m \
    -e DRUPACK_CACHE_DIR=/cache \
    --workdir /site \
    --mount "type=bind,src=$binary,dst=/artifact/drupack,readonly" \
    --mount "type=bind,src=$data9,dst=/site" \
    "$debian" /artifact/drupack --help >"$results/case9.out" 2>"$results/case9.err" || code9=$?
  [[ $code9 -ne 0 ]] || fail 'The launcher exited 0 with no room to unpack the runtime'
  grep -Fq '/cache' "$results/case9.err" || fail 'The failure message did not name the cache path'
  [[ ! -e "$data9/data" ]] || fail 'A Site data directory was written despite the unpacking failure'
else
  printf 'Skipping case 9: docker is not installed\n' >&2
fi

## Case 10: two versions leave only the second active.
if $have_go; then
  fixture10a="$results/case10/fixture-v1"
  fixture10b="$results/case10/fixture-v2"
  pack_fixture 10.0.0 "$fixture10a"
  pack_fixture 10.0.1 "$fixture10b"
  cache10="$results/case10/cache"
  rm -rf "$cache10"
  export DRUPACK_CACHE_DIR="$cache10"
  "$fixture10a" --help >"$results/case10-a.out" 2>"$results/case10-a.err"
  key10a=$(cat "$cache10/active")
  [[ -d "$cache10/$key10a" ]] || fail 'The first version did not stage a cache entry'
  "$fixture10b" --help >"$results/case10-b.out" 2>"$results/case10-b.err"
  key10b=$(cat "$cache10/active")
  [[ $key10b != "$key10a" ]] || fail 'The second version did not become active'
  [[ ! -d "$cache10/$key10a" ]] || fail "The first version's entry survived a second version's start"
  [[ $(entry_count "$cache10") -eq 1 ]] || fail 'Two versions did not leave exactly one cache entry'
  unset DRUPACK_CACHE_DIR
else
  printf 'Skipping case 10: go is not installed\n' >&2
fi

## Case 11: a corrupted payload falls back to the version already cached.
if $have_go; then
  fixture11="$results/case11/fixture-v1"
  pack_fixture 11.0.0 "$fixture11"
  cache11="$results/case11/cache"
  rm -rf "$cache11"
  export DRUPACK_CACHE_DIR="$cache11"
  "$fixture11" --help >"$results/case11-install.out" 2>"$results/case11-install.err"
  [[ $(entry_count "$cache11") -eq 1 ]] || fail 'Installing version one did not leave one cache entry'

  broken11="$results/case11/fixture-v2"
  build_corrupted_fixture 11.0.1 "$broken11"
  code11=0
  "$broken11" --help >"$results/case11-fallback.out" 2>"$results/case11-fallback.err" || code11=$?
  [[ $code11 -eq 0 ]] || fail 'A corrupted payload with a valid cached version did not exit 0'
  grep -Fq 'fixture 11.0.0' "$results/case11-fallback.out" || fail 'The fallback did not run the cached version'
  grep -Eq '^Could not unpack Drupack 11\.0\.1: .*Using the runtime already in the cache\.$' "$results/case11-fallback.err" \
    || fail 'The fallback warning was not written to standard error'
  [[ $(entry_count "$cache11") -eq 1 ]] || fail 'The fallback start changed the number of cache entries'
  unset DRUPACK_CACHE_DIR
else
  printf 'Skipping case 11: go is not installed\n' >&2
fi

printf 'Launcher checks passed. Results: %s\n' "$results"
