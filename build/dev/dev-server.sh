#!/usr/bin/env bash
set -euo pipefail

usage='Usage: build/dev/dev-server.sh DATA_DIRECTORY [PORT] [DRUPACK_OPTION...]'
mkdir -p "${1:?$usage}"
data=$(cd -- "$1" && pwd)
port=${2:-7225}
shift 2 2>/dev/null || shift 1
repository=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
image=${DRUPACK_DEV_IMAGE:-drupack-build}

if ! docker image inspect "$image" >/dev/null 2>&1; then
    printf 'Image %s is missing. Build it with: docker build --target build -t %s .\n' "$image" "$image" >&2
    exit 1
fi

terminal=()
if [ -t 0 ]; then
    terminal=(--interactive --tty)
fi

# The release executable embeds runtime/ and deletes its extracted copy when it
# exits, so only a build changes those files there. This serves the application
# from the build image instead, with runtime/ copied over it on every start.
# -e DRUPACK_CA_FILE with no value forwards the caller's own, unset or not, the
# same way a release start's environment reaches dev-entry.sh's export.
exec docker run --rm "${terminal[@]}" --user "$(id -u):$(id -g)" -p "127.0.0.1:$port:$port" \
    -e DRUPACK_CA_FILE \
    --mount "type=bind,src=$repository/runtime,dst=/dev-runtime,readonly" \
    --mount "type=bind,src=$repository/build/dev/dev-entry.sh,dst=/dev-entry.sh,readonly" \
    --mount "type=bind,src=$data,dst=/data" \
    "$image" sh /dev-entry.sh "$port" "$@"
