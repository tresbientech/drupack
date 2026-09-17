#!/usr/bin/env bash
set -euo pipefail

binary=$(realpath "${1:?Usage: tests/server-database.sh BINARY [RESULTS_DIRECTORY]}")
results=${2:-$(mktemp -d /tmp/drupack-server-database.XXXXXX)}
mkdir -p "$results"
results=$(realpath "$results")
mysql=mysql:8.4.11@sha256:85b9bf2e29cf836ecb8c2a15a935d4ba0c606631dff1dd79531a11983c638f2a
postgres=postgres:17.11@sha256:67f41722b7a8cbdb868a44a4995c846eddfdc2973bccb291ce937dce88ad5675
container="drupack-database-$$"
listen=127.0.0.1:18095
password=Server.database.test.2026
server=

cleanup() {
  if [[ -n $server ]]; then
    kill "$server" 2>/dev/null || true
    wait "$server" 2>/dev/null || true
  fi
  docker rm -f "$container" >/dev/null 2>&1 || true
}
trap cleanup EXIT

status() {
  curl -s -o /dev/null -w '%{http_code}' "http://$listen$1"
}

expect_status() {
  local actual
  actual=$(status "$1")
  if [[ $actual != "$2" ]]; then
    printf '%s: %s returned %s, expected %s\n' "$database" "$1" "$actual" "$2" >&2
    exit 1
  fi
}

start_site() {
  local log="$results/$database-$1.log"
  shift
  "$binary" --data-dir "$data" --listen "$listen" "$@" >"$log" 2>&1 &
  server=$!
  for attempt in {1..300}; do
    if [[ $(status /user/login) == 200 ]]; then
      return
    fi
    if ! kill -0 "$server" 2>/dev/null; then
      break
    fi
    sleep 2
  done
  cat "$log" >&2
  printf '%s: the site did not serve /user/login\n' "$database" >&2
  exit 1
}

stop_site() {
  kill "$server"
  wait "$server" 2>/dev/null || true
  server=
}

for database in mysql pgsql; do
  data="$results/$database-data"
  if [[ $database == mysql ]]; then
    docker run -d --name "$container" -p 127.0.0.1::3306 \
      -e MYSQL_DATABASE=drupal -e MYSQL_USER=drupal -e MYSQL_PASSWORD="$password" -e MYSQL_RANDOM_ROOT_PASSWORD=yes \
      "$mysql" >/dev/null
    port=$(docker port "$container" 3306/tcp | head -n 1)
    # The image's initialization server listens on a socket only, so a TCP query waits for the final server.
    ready=(mysql -h 127.0.0.1 -u drupal "-p$password" drupal -e 'SELECT 1')
  else
    docker run -d --name "$container" -p 127.0.0.1::5432 \
      -e POSTGRES_DB=drupal -e POSTGRES_USER=drupal -e POSTGRES_PASSWORD="$password" \
      "$postgres" >/dev/null
    port=$(docker port "$container" 5432/tcp | head -n 1)
    ready=(pg_isready -h 127.0.0.1 -U drupal -d drupal)
  fi
  port=${port##*:}
  for attempt in {1..90}; do
    if docker exec "$container" "${ready[@]}" >/dev/null 2>&1; then
      break
    fi
    sleep 2
  done

  start_site first-start --database "$database" --db-host 127.0.0.1 --db-port "$port" --db-name drupal \
    --db-user drupal --db-password "$password" --admin-user server-admin --admin-password "$password"
  expect_status / 200
  expect_status /sites/default/settings.php 404
  expect_status /sites/default/private/ 403
  stop_site

  driver=$("$binary" dr --data-dir "$data" status --field=db-driver)
  bootstrap=$("$binary" dr --data-dir "$data" status --field=bootstrap)
  if [[ $driver != "$database" || $bootstrap != Successful ]]; then
    printf '%s: dr status reported driver %s and bootstrap %s\n' "$database" "$driver" "$bootstrap" >&2
    exit 1
  fi
  if [[ -e $data/site.sqlite ]]; then
    printf '%s: Site data holds a SQLite database\n' "$database" >&2
    exit 1
  fi

  start_site restart
  expect_status / 200
  stop_site

  docker logs "$container" >"$results/$database-server.log" 2>&1
  docker rm -f "$container" >/dev/null
done

printf 'Server database checks passed. Results: %s\n' "$results"
