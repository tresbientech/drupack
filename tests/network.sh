#!/usr/bin/env bash
set -euo pipefail

binary=$(realpath "${1:?Usage: tests/network.sh BINARY [RESULTS_DIRECTORY]}")
results=${2:-$(mktemp -d /tmp/portable-drupal-network.XXXXXX)}
mkdir -p "$results/data"
results=$(realpath "$results")
network="portable-drupal-network-$$"
app="portable-drupal-site-$$"
debian=debian@sha256:88200866dfff7ea7f5cbcb6ec7c8a701889efe6fe859fe64d6990e4b07ea4171
client=selenium/standalone-chromium@sha256:8745c65008bf01c5e7158a904704560e9971d8b7da2fc7ad1f1d0ffe776812f6

cleanup() {
  docker rm -f "$app" >/dev/null 2>&1 || true
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
    --mount "type=bind,src=$binary,dst=/artifact/portable-drupal,readonly" \
    --mount "type=bind,src=$results/data,dst=/site/data" \
    "$debian" /artifact/portable-drupal php-cli launch.php "${arguments[@]}" >/dev/null
  ready=false
  for attempt in {1..60}; do
    docker logs "$app" >"$results/$mode.log" 2>&1
    if grep -q 'server running' "$results/$mode.log"; then
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
import socket
import sys
import urllib.error
import urllib.request

mode = sys.argv[1]
if mode == "loopback":
    try:
        connection = socket.create_connection(("site", 8080), timeout=3)
    except OSError:
        print("PASS: default listener rejects access from another container")
    else:
        connection.close()
        raise AssertionError("Default listener accepted remote access")
else:
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    request = urllib.request.Request("http://site:8080/", headers={"User-Agent": "PortableDrupalNetworkTest"})
    with opener.open(request, timeout=60) as response:
        assert response.status == 200
        assert "/core/install.php" in response.url
        assert "Drupal" in response.read().decode()
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
printf 'Network checks passed. Results: %s\n' "$results"
