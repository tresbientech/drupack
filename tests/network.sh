#!/usr/bin/env bash
set -euo pipefail

binary=$(realpath "${1:?Usage: tests/network.sh BINARY [RESULTS_DIRECTORY] [INSTALLED_TEST_DATA]}")
results=${2:-$(mktemp -d /tmp/drupack-network.XXXXXX)}
mkdir -p "$results/data"
results=$(realpath "$results")
installed=false
if [[ -n ${3:-} ]]; then
  tar -C "$3" --exclude='files/php' -cf - site.sqlite settings.php hash_salt files private config \
    | tar -C "$results/data" -xf -
  installed=true
fi
network="drupack-network-$$"
app="drupack-site-$$"
space_app=""
debian=debian@sha256:88200866dfff7ea7f5cbcb6ec7c8a701889efe6fe859fe64d6990e4b07ea4171
client=selenium/standalone-chromium@sha256:8745c65008bf01c5e7158a904704560e9971d8b7da2fc7ad1f1d0ffe776812f6
if [[ $installed == true ]]; then
  # The copied fixture has cached absolute paths from its original container.
  docker run --rm -i --network none --user "$(id -u):$(id -g)" \
    --mount "type=bind,src=$results/data,dst=/data" --entrypoint /usr/bin/python3 "$client" - <<'PY'
import sqlite3

with sqlite3.connect("/data/site.sqlite") as database:
    for (table,) in database.execute("SELECT name FROM sqlite_master WHERE type = 'table' AND name GLOB 'cache_*'"):
        database.execute('DELETE FROM "' + table.replace('"', '""') + '"')
PY
fi

cleanup() {
  docker logs "$app" >"$results/final.log" 2>&1 || true
  docker rm -f "$app" >/dev/null 2>&1 || true
  if [[ -n $space_app ]]; then
    docker rm -f "$space_app" >/dev/null 2>&1 || true
  fi
  docker network rm "$network" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create --internal "$network" >/dev/null

for mode in loopback network; do
  arguments=()
  if [[ $mode == network ]]; then
    arguments=(--listen 0.0.0.0:8080 --host site)
  fi
  docker run -d --name "$app" --network "$network" --network-alias site \
    --user "$(id -u):$(id -g)" --workdir /site \
    --mount "type=bind,src=$binary,dst=/artifact/drupack,readonly" \
    --mount "type=bind,src=$results/data,dst=/site/data" \
    "$debian" /artifact/drupack --admin-user network-admin --admin-password Network.test.administrator.2026! "${arguments[@]}" >/dev/null
  ready=false
  for attempt in {1..60}; do
    docker logs "$app" >"$results/$mode.log" 2>&1
    if grep -Fq 'Drupal is ready.' "$results/$mode.log"; then
      ready=true
      break
    fi
    sleep 1
  done
  if [[ $ready != true ]]; then
    cat "$results/$mode.log"
    exit 1
  fi
  mkdir -p "$results/data/files/php"
  printf 'private-network-sentinel' >"$results/data/private/secret.txt"
  printf '<?php echo "php-network-sentinel";' >"$results/data/files/php/test.php"
  docker run --rm -i --network "$network" --entrypoint /usr/bin/python3 \
    "$client" - "$mode" <<'PY'
from http.cookiejar import CookieJar
import socket
import sys
import urllib.error
import urllib.request

mode = sys.argv[1]
if mode == "loopback":
    try:
        connection = socket.create_connection(("site", 7225), timeout=3)
    except OSError:
        print("PASS: default listener rejects access from another container")
    else:
        connection.close()
        raise AssertionError("Default listener accepted remote access")
else:
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(CookieJar()))
    request = urllib.request.Request("http://site:8080/", headers={"User-Agent": "DrupackNetworkTest"})
    with opener.open(request, timeout=60) as response:
        assert response.status == 200
        page = response.read().decode()
        assert "/core/install.php" not in response.url
    for path in ["/sites/default/settings.php", "/site.sqlite", "/private/secret.txt", "/sites/default/files/php/test.php"]:
        try:
            opener.open("http://site:8080" + path, timeout=10)
        except urllib.error.HTTPError as error:
            assert error.code in (403, 404), (path, error.code)
        else:
            raise AssertionError("Private path accessible: " + path)
    print("PASS: explicit listener serves Drupal to another container and protects private paths")
PY
  docker logs "$app" >"$results/$mode.log" 2>&1
  docker rm -f "$app" >/dev/null
done

# A Caddyfile placeholder substitutes as raw text before Caddy tokenizes it, so an
# unquoted path with a space splits into two tokens and Caddy fails to start.
space_root="$results/space-root"
mkdir -p "$space_root"
space_app="drupack-network-space-$$"
docker run -d --name "$space_app" --network "$network" --network-alias space \
  --user "$(id -u):$(id -g)" --workdir /site \
  --mount "type=bind,src=$binary,dst=/artifact/drupack,readonly" \
  --mount "type=bind,src=$space_root,dst=/site" \
  "$debian" /artifact/drupack --admin-user space-admin --admin-password Network.space.test.2026! \
    --data-dir "/site/data with space" --listen 0.0.0.0:8080 --host space >/dev/null
ready=false
for attempt in {1..60}; do
  docker logs "$space_app" >"$results/space.log" 2>&1
  if grep -Fq 'Drupal is ready.' "$results/space.log"; then
    ready=true
    break
  fi
  sleep 1
done
if [[ $ready != true ]]; then
  cat "$results/space.log"
  exit 1
fi
docker run --rm -i --network "$network" --entrypoint /usr/bin/python3 "$client" - <<'PY'
import urllib.request

with urllib.request.urlopen("http://space:8080/user/login", timeout=30) as response:
    assert response.status == 200
print("PASS: a Site data path with a space serves the login page")
PY
[[ -s "$space_root/data with space/logs/caddy.log" ]] \
  || { printf 'A Site data path with a space wrote no log file\n' >&2; exit 1; }
docker logs "$space_app" >"$results/space.log" 2>&1
docker rm -f "$space_app" >/dev/null
space_app=""

printf 'Network checks passed. Results: %s\n' "$results"
