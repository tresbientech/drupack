#!/usr/bin/env bash
set -euo pipefail

# Adapts and serves every Caddyfile the launcher's tests render, with the Caddy
# and FrankenPHP the runtime links, in the builder image of this machine.
#
# Usage: adapt-test.sh [musl|gnu]
repository=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
libc=${1:-gnu}
# The module cache lives in a volume, so only the first run downloads Caddy.
docker run --rm --ulimit nofile=8192:8192 \
    -v "$repository:/src:ro" \
    -v "$repository/launcher/internal/caddyfile/testdata:/rendered:ro" \
    -v drupack-adapt-modules:/root/go/pkg/mod \
    -e DRUPACK_ADAPT_FILES=/rendered \
    "drupack-builder-$libc:local" bash -c '
        set -euo pipefail
        mkdir -p /build
        cp /src/runtime/builder/builder-inputs.sh /src/runtime/extensions/php-extension-libs.txt /src/runtime/extensions/extensions-list.sh /src/runtime/extensions/php-extensions.txt /build/
        cp /src/runtime/frankenphp/entrypoint.go /go/src/app/caddy/frankenphp/drupack.go
        cp /src/runtime/frankenphp/watch/watch.go /go/src/app/caddy/frankenphp/drupack_watch.go
        cp /src/runtime/frankenphp/crtenv_other.go /go/src/app/caddy/frankenphp/drupack_crtenv_other.go
        cp /src/runtime/frankenphp/adapt_test.go /go/src/app/caddy/frankenphp/drupack_adapt_test.go
        bash /src/runtime/builder/embed.sh test Adapt'
