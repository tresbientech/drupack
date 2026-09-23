#!/usr/bin/env bash
set -euo pipefail

usage='Usage: build/dev/dev-server.sh DATA_DIRECTORY [PORT] [DRUPACK_OPTION...]'
mkdir -p "${1:?$usage}"
data=$(cd -- "$1" && pwd)
port=${2:-7225}
shift 2 2>/dev/null || shift 1
repository=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
image=${DRUPACK_DEV_IMAGE:-drupack-job}
application=$repository/dist/work/app

if ! docker image inspect "$image" >/dev/null 2>&1; then
    printf 'Image %s is missing. Build it with: docker build --target job -t %s .\n' "$image" "$image" >&2
    exit 1
fi
if [ ! -d "$application" ]; then
    printf '%s is missing. Build it with: bash build/qa.sh, or drupack-build --work dist/work\n' "$application" >&2
    exit 1
fi

terminal=()
if [ -t 0 ]; then
    terminal=(--interactive --tty)
fi

# A release executable carries its application, so only a build changes those
# files there. This serves the application drupack-build left in dist/work
# instead, with the files application/ holds copied over it on every start.
# -e DRUPACK_CA_FILE with no value forwards the caller's own, unset or not, the
# same way a release start's environment reaches dev-entry.sh's export.
exec docker run --rm "${terminal[@]}" --user "$(id -u):$(id -g)" -p "127.0.0.1:$port:$port" \
    -e DRUPACK_CA_FILE \
    --mount "type=bind,src=$application,dst=/app,readonly" \
    --mount "type=bind,src=$repository/application,dst=/dev-application,readonly" \
    --mount "type=bind,src=$repository/build/dev/dev-entry.sh,dst=/dev-entry.sh,readonly" \
    --mount "type=bind,src=$data,dst=/data" \
    "$image" sh /dev-entry.sh "$port" "$@"
