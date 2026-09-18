#!/usr/bin/env bash
set -euo pipefail

binary=$(realpath "${1:?Usage: tests/initialization.sh BINARY [RESULTS_DIRECTORY]}")
results=${2:-$(mktemp -d /tmp/drupack-initialization.XXXXXX)}
mkdir -p "$results"
results=$(realpath "$results")
data="$results/data"
backup="$results/backup"
listen=127.0.0.1:18097
default_url=http://localhost:7225/
password=Initialization.test.2026
site_name='Drupack initialization check'
default_site_name='Drupal Mercury Demo'
postgres=postgres:17.11@sha256:67f41722b7a8cbdb868a44a4995c846eddfdc2973bccb291ce937dce88ad5675
container="drupack-initialization-$$"
server=
holder=

cleanup() {
  for process in $server $holder; do
    kill "$process" 2>/dev/null || true
    wait "$process" 2>/dev/null || true
  done
  docker rm -f "$container" >/dev/null 2>&1 || true
}
trap cleanup EXIT

fail() {
  printf '%s\n' "$1" >&2
  exit 1
}

note() {
  printf '== %s\n' "$1"
}

dr() {
  "$binary" dr --data-dir "$data" "$@"
}

status() {
  curl -s -o /dev/null -w '%{http_code}' "http://$listen$1"
}

start_site() {
  local name=$1 log="$results/$1.log"
  shift
  "$binary" --data-dir "$data" --listen "$listen" "$@" >"$log" 2>&1 </dev/null &
  server=$!
  for attempt in {1..150}; do
    if [[ $(status /user/login) == 200 ]]; then
      return
    fi
    if ! kill -0 "$server" 2>/dev/null; then
      break
    fi
    sleep 2
  done
  cat "$log" >&2
  fail "$name: the site did not serve /user/login"
}

stop_site() {
  kill "$server"
  wait "$server" 2>/dev/null || true
  server=
}

# A graceful shutdown removes the extracted application, so a case that races two starts keeps it.
stop_kept() {
  kill -9 "$server"
  wait "$server" 2>/dev/null || true
  server=
}

# A refusal leaves no server behind, so a start that keeps running is a failure of its own.
expect_refusal() {
  local name=$1 message=$2 code=0
  shift 2
  timeout 180 "$binary" --data-dir "$data" --listen "$listen" "$@" >"$results/$name.log" 2>&1 </dev/null || code=$?
  if ((code == 0)); then
    fail "$name: the start succeeded instead of refusing"
  fi
  if ((code == 124)); then
    fail "$name: the start kept running instead of refusing"
  fi
  if ! grep -Fq "$message" "$results/$name.log"; then
    cat "$results/$name.log" >&2
    fail "$name: the diagnostic does not name: $message"
  fi
}

# Keeps the extracted application, so a later case starts from an empty Site data directory.
reset_site() {
  rm -rf "$data/settings.php" "$data/site-installed" "$data/installation-progress" "$data/hash_salt" \
    "$data/site.sqlite" "$data/site.sqlite-shm" "$data/site.sqlite-wal" "$data/files" "$data/private" \
    "$data/config" "$data/tmp" "$data/site-adopted" "$data/first-install" "$data/listener"
}

expect_marker() {
  [[ -e $data/site-installed ]] || fail "$1: Site data holds no completion marker"
  [[ ! -e $data/installation-progress ]] || fail "$1: Site data still holds initialization progress"
}

expect_site_name() {
  local actual
  actual=$(dr config:get system.site name --format=string)
  [[ $actual == "$2" ]] || fail "$1: the site name is $actual, expected $2"
}

administrator() {
  dr php:eval 'print \Drupal\user\Entity\User::load(1)->getAccountName();'
}

login_line='  Login:     http'

expect_link() {
  grep -Fq 'Drupack is ready' "$results/$1.log" || fail "$1: the start printed no readiness heading"
  grep -Fq '  URL:       http' "$results/$1.log" || fail "$1: the start printed no URL label"
  grep -Fq '  Site data:' "$results/$1.log" || fail "$1: the start printed no site data label"
  grep -Fq '  Log:' "$results/$1.log" || fail "$1: the start printed no log label"
  grep -Fq 'Press Ctrl+C to stop.' "$results/$1.log" || fail "$1: the start printed no stop instruction"
  [[ -f "$data/logs/caddy.log" ]] || fail "$1: the Caddy log file was not created"
  printf '%s\n' caddy-log-sentinel >>"$data/logs/caddy.log"
  ! grep -Fq caddy-log-sentinel "$results/$1.log" || fail "$1: Caddy log content appeared in CLI output"
  grep -Fq "$login_line" "$results/$1.log" || fail "$1: the start printed no one-time login link"
}

expect_no_link() {
  ! grep -Fq "$login_line" "$results/$1.log" || fail "$1: a later start printed a one-time login link"
}

# Follows a one-time login link with a cookie jar. Drupal redirects it to the account form,
# so the landing page names the account it logged in.
follow_link() {
  local name=$1 link=$2 landing
  landing=$(curl -s -L -c "$results/$name.cookies" -b "$results/$name.cookies" \
    -o "$results/$name.html" -w '%{url_effective}' "$link")
  [[ $landing == *"/user/1/edit"* ]] || fail "$name: the link landed on $landing"
  grep -Fq "value=\"$3\"" "$results/$name.html" || fail "$name: the account form names no $3"
}

mkdir -p "$backup"

note 'A first start creates the site and records its completion'
start_site first-start --admin-user init-admin --admin-password "$password"
expect_marker first-start
expect_site_name first-start "$default_site_name"
expect_link first-start
dr config:set system.site name "$site_name" --yes >"$results/config-set.log" 2>&1

note 'Drush works while the server runs'
bootstrap=$(dr status --field=bootstrap)
[[ $bootstrap == Successful ]] || fail "dr status reported bootstrap $bootstrap while the server ran"

note 'dr addresses the site on the recorded listener, with no options repeated'
link=$(dr user:login --no-browser)
[[ $link == "http://localhost:${listen##*:}/"* ]] || fail "dr user:login printed $link"
follow_link recorded-listener "$link" init-admin

note 'dr --listen overrides the recorded listener'
link=$(dr --listen 127.0.0.1:19999 user:login --no-browser)
[[ $link == http://localhost:19999/* ]] || fail "an overridden dr user:login printed $link"

note 'Site data with no recorded listener falls back to the default'
mv "$data/listener" "$backup/listener"
link=$(dr user:login --no-browser)
[[ $link == "$default_url"* ]] || fail "a record-less dr user:login printed $link"
mv "$backup/listener" "$data/listener"
stop_site

note 'A completed site keeps its recorded backend when a start passes other database options'
start_site recorded-backend --database mysql --db-host 127.0.0.1 --db-port 3306 \
  --db-name absent --db-user absent --db-password absent
driver=$(dr status --field=db-driver)
[[ $driver == sqlite ]] || fail "the start replaced the recorded backend with $driver"
expect_site_name recorded-backend "$site_name"
expect_no_link recorded-backend
stop_site

note 'A directory without the completion marker is adopted when Drupal bootstraps'
cp "$data/settings.php" "$backup/settings.php"
cp "$data/site.sqlite" "$backup/site.sqlite"
rm "$data/site-installed"
start_site adoption
expect_marker adoption
expect_site_name adoption "$site_name"
stop_site

note 'A directory that cannot bootstrap Drupal refuses instead of serving'
rm "$data/site-installed"
printf 'not a database' >"$data/site.sqlite"
expect_refusal unbootstrappable "$data"
grep -Fq -- "dr --data-dir" "$results/unbootstrappable.log" \
  || fail 'the refusal does not name the recovery command'
cp "$backup/site.sqlite" "$data/site.sqlite"

note 'A database without recorded settings refuses instead of taking the seed'
rm -f "$data/settings.php" "$data/site-installed" "$data/installation-progress"
expect_refusal orphan-database "$data"
cmp -s "$backup/site.sqlite" "$data/site.sqlite" || fail 'the refused start changed the existing database'

note 'A first start interrupted before the administrator step records its progress'
reset_site
setsid --wait "$binary" --data-dir "$data" --listen "$listen" \
  --admin-user init-admin --admin-password "$password" >"$results/interrupted.log" 2>&1 </dev/null &
supervisor=$!
# The administrator step follows the settings, so the recorded progress names the moment to interrupt.
for attempt in {1..1800}; do
  if [[ $(cat "$data/installation-progress" 2>/dev/null) == '["administrator"]' ]]; then
    break
  fi
  if ! kill -0 "$supervisor" 2>/dev/null; then
    break
  fi
  sleep 0.1
done
[[ $(cat "$data/installation-progress" 2>/dev/null) == '["administrator"]' ]] \
  || fail 'the first start recorded no pending administrator step'
group=$(ps -o pgid= -p "$supervisor" | tr -d ' ')
[[ -n $group && $group != "$(ps -o pgid= -p $$ | tr -d ' ')" ]] \
  || fail 'the interrupted start shares this process group'
kill -9 -- "-$group"
wait "$supervisor" 2>/dev/null || true
[[ -e $data/settings.php ]] || fail 'the interrupted start recorded no settings'
[[ ! -e $data/site-installed ]] || fail 'the interrupted start recorded a completed installation'

note 'An interrupted first start that lost its progress record is not adopted with the seed password'
cp "$data/installation-progress" "$backup/installation-progress"
rm "$data/installation-progress"
expect_refusal seed-administrator "packaged seed password"
grep -Fq -- "dr --data-dir" "$results/seed-administrator.log" \
  || fail 'the refusal does not name the recovery command'
grep -Fq "$data" "$results/seed-administrator.log" \
  || fail 'the refusal does not name the Site data directory'
cp "$backup/installation-progress" "$data/installation-progress"

note 'The interrupted start left the seed administrator in place'
account=$(administrator)
[[ $account == drupack-admin ]] || fail "the seed administrator is already replaced by $account"

note 'A start with credentials finishes the interrupted setup and prints a link'
start_site recovery --admin-user init-admin --admin-password "$password"
expect_marker recovery
expect_link recovery
account=$(administrator)
[[ $account == init-admin ]] || fail "the recovery left administrator $account"
stop_kept

note 'Two simultaneous first starts initialize once and the loser names the directory'
reset_site
"$binary" --data-dir "$data" --listen "$listen" --admin-user init-admin --admin-password "$password" \
  >"$results/race-first.log" 2>&1 </dev/null &
first=$!
"$binary" --data-dir "$data" --listen "$listen" --admin-user init-admin --admin-password "$password" \
  >"$results/race-second.log" 2>&1 </dev/null &
second=$!
loser=
winner=
for attempt in {1..150}; do
  if ! kill -0 "$first" 2>/dev/null; then
    loser=$first
    winner=$second
    break
  fi
  if ! kill -0 "$second" 2>/dev/null; then
    loser=$second
    winner=$first
    break
  fi
  sleep 1
done
if [[ -z $loser ]]; then
  kill "$first" "$second" 2>/dev/null || true
  fail 'both simultaneous starts kept running'
fi
code=0
wait "$loser" || code=$?
((code != 0)) || fail 'a simultaneous start exited without a failure'
server=$winner
log="$results/race-first.log"
if [[ $loser == "$second" ]]; then
  log="$results/race-second.log"
fi
grep -Fq 'Another Drupack start' "$log" || { cat "$log" >&2; fail 'the losing start names no other start'; }
grep -Fq "$data" "$log" || fail 'the losing start does not name the Site data directory'
for attempt in {1..150}; do
  if [[ $(status /user/login) == 200 ]]; then
    break
  fi
  sleep 2
done
[[ $(status /user/login) == 200 ]] || fail 'the winning start did not serve /user/login'
expect_marker race
stop_site

note 'An equivalent path to one Site data directory takes the same lock'
reset_site
ln -sfn "$data" "$results/equivalent"
# The holder keeps the lock until the blocked start has answered, and releases it without a signal.
( flock -x 9; while [[ ! -e $results/release-lock ]]; do sleep 1; done ) 9>"$data/startup.lock" &
holder=$!
code=0
timeout 180 "$binary" --data-dir "$results/equivalent" --listen "$listen" \
  >"$results/equivalent.log" 2>&1 </dev/null || code=$?
touch "$results/release-lock"
wait "$holder" 2>/dev/null || true
holder=
((code != 0)) || fail 'a start took a lock another process held'
((code != 124)) || fail 'a start waited for a lock another process held'
grep -Fq 'Another Drupack start' "$results/equivalent.log" \
  || { cat "$results/equivalent.log" >&2; fail 'the blocked start names no other start'; }
grep -Fq "$data" "$results/equivalent.log" || fail 'the blocked start does not name the resolved directory'

note 'A chosen site name survives, and a later start never renames the site'
reset_site
start_site chosen-name --site-name 'Chosen initialization name' \
  --admin-user init-admin --admin-password "$password"
expect_site_name chosen-name 'Chosen initialization name'
stop_site
start_site rename-attempt --site-name 'Rejected initialization name'
expect_site_name rename-attempt 'Chosen initialization name'
stop_site

note 'The site name also comes from the environment'
reset_site
export DRUPACK_SITE_NAME='Environment initialization name'
start_site environment-name --admin-user init-admin --admin-password "$password"
expect_site_name environment-name 'Environment initialization name'
stop_site
unset DRUPACK_SITE_NAME

note 'Starting PostgreSQL for the server-database cases'
reset_site
docker run -d --name "$container" -p 127.0.0.1::5432 \
  -e POSTGRES_DB=drupal -e POSTGRES_USER=drupal -e POSTGRES_PASSWORD="$password" \
  "$postgres" >/dev/null
port=$(docker port "$container" 5432/tcp | head -n 1)
port=${port##*:}
for attempt in {1..90}; do
  if docker exec "$container" pg_isready -h 127.0.0.1 -U drupal -d drupal >/dev/null 2>&1; then
    break
  fi
  sleep 2
done
docker exec "$container" psql -U drupal -d drupal -c 'CREATE DATABASE occupied' >/dev/null
docker exec "$container" psql -U drupal -d occupied -c 'CREATE TABLE tenant (id integer)' >/dev/null
connection=(--database pgsql --db-host 127.0.0.1 --db-port "$port" --db-user drupal --db-password "$password")

note 'A PostgreSQL first start records its completion'
start_site pgsql-first --db-name drupal "${connection[@]}" \
  --admin-user init-admin --admin-password "$password"
expect_marker pgsql-first
expect_site_name pgsql-first "$default_site_name"
expect_link pgsql-first
stop_site
dr config:set system.site name "$site_name" --yes >"$results/pgsql-config-set.log" 2>&1

note 'A start after an interrupted installation finishes the missing steps without reinstalling'
dr pm:uninstall mcp_tools --yes >"$results/pgsql-uninstall.log" 2>&1 \
  || fail 'the case could not uninstall MCP Tools'
rm "$data/site-installed"
printf '["install","modules"]' >"$data/installation-progress"
# The recovery passes no database options, so the recorded settings decide the backend.
start_site pgsql-recovery --admin-user init-admin --admin-password "$password"
expect_marker pgsql-recovery
expect_site_name pgsql-recovery "$site_name"
expect_link pgsql-recovery
enabled=$(dr php:eval 'print \Drupal::moduleHandler()->moduleExists("mcp_tools") ? "enabled" : "missing";')
[[ $enabled == enabled ]] || fail "the recovery left MCP Tools $enabled"
driver=$(dr status --field=db-driver)
[[ $driver == pgsql ]] || fail "the recovery served driver $driver"
stop_site

note 'A first start never reinstalls a database that already holds a site'
reset_site
start_site pgsql-existing --db-name drupal "${connection[@]}" \
  --admin-user other-admin --admin-password "$password"
expect_site_name pgsql-existing "$site_name"
account=$(administrator)
[[ $account == init-admin ]] || fail "the start replaced the existing administrator with $account"
stop_site

note 'A first start refuses a database that holds other tables'
reset_site
expect_refusal occupied-database "$data" --db-name occupied "${connection[@]}"
tables=$(docker exec "$container" psql -U drupal -d occupied -t -A -c \
  "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'")
[[ $tables == 1 ]] || fail "the refused start left $tables tables in the schema, expected the existing one"
tenant=$(docker exec "$container" psql -U drupal -d occupied -t -A -c \
  "SELECT count(*) FROM information_schema.tables WHERE table_name = 'tenant'")
[[ $tenant == 1 ]] || fail 'the refused start dropped the existing tables'

docker logs "$container" >"$results/postgres.log" 2>&1
printf 'Initialization checks passed. Results: %s\n' "$results"
