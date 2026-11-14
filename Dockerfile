# A Linux release publishes a file per runtime: the glibc one serves a rendered
# page several times faster, and the musl one runs on any host.
# PHP_EXTENSIONS takes effect while a builder image is built, so
# runtime/builder/build-builder.sh builds one image per libc before this file runs.
ARG MUSL_BUILDER=drupack-builder-musl:local
ARG GNU_BUILDER=drupack-builder-gnu:local
# The runtimes the job image carries. A COPY --from names a stage before a build
# context, so the release sets this to a name no stage has and passes that name
# as --build-context NAME=DIRECTORY.
ARG RUNTIMES=local-runtimes
# The build/release/sbom.py runtime reports of those runtimes, one TARGET.json each, which
# the release passes the same way. A local image carries none.
ARG RUNTIME_COMPONENTS=no-runtime-components
# The extension list a runtime links. build/pack/site-runtimes.sh sets this to a build
# context holding the engine list merged with a site's additions, as RUNTIMES does.
ARG EXTENSIONS=engine-extensions
# The job image is a glibc host, the only kind that runs both runtimes, so a file
# of either libc is tested where it was built.
# build/release/upstream.py sets the tag to GO_VERSION in release.yml and the digest to
# that tag's multi-platform index.
ARG JOB_BASE=golang:1.27.4-bookworm@sha256:4fa7c968ba93f1635670522dbb01a2c78150443e1385746924566079f36570ea
# act_runner starts JavaScript actions with the job container's own node.
# docker buildx imagetools inspect node:24-bookworm-slim --format '{{.Manifest.Digest}}'
ARG NODE_IMAGE=node:24-bookworm-slim@sha256:0e0ff40c39bc087845bfb27465a0df4ea419520094bc35842ff83dd8cbe6f9b6

# php.ini and the trust bundle sit beside the executable, where the launcher's
# environment function finds them.
# runtime/cacert.pem comes from https://curl.se/ca/cacert.pem, sha256
# f66dff1bdf8f96060b8177976f8b7d9254bc89bc4db933d769f7384d28480bc9, 121
# certificates. A refresh replaces the file and that checksum in one commit;
# no build fetches it.
FROM scratch AS engine-extensions
COPY runtime/extensions/php-extensions.txt /php-extensions.txt

FROM ${EXTENSIONS} AS extensions

FROM ${MUSL_BUILDER} AS runtime-musl
ARG DRUPACK_VERSION
COPY runtime/builder/embed.sh /usr/local/bin/embed.sh
COPY runtime/builder/builder-inputs.sh runtime/extensions/php-extension-libs.txt runtime/extensions/extensions-list.sh /build/
COPY --from=extensions /php-extensions.txt /build/php-extensions.txt
COPY runtime/frankenphp/entrypoint.go /go/src/app/caddy/frankenphp/drupack.go
COPY runtime/frankenphp/watch/watch.go /go/src/app/caddy/frankenphp/drupack_watch.go
COPY runtime/frankenphp/crtenv_other.go /go/src/app/caddy/frankenphp/drupack_crtenv_other.go
RUN bash /usr/local/bin/embed.sh
COPY runtime/php.ini runtime/cacert.pem /out/

FROM ${GNU_BUILDER} AS runtime-gnu
ARG DRUPACK_VERSION
COPY runtime/builder/embed.sh /usr/local/bin/embed.sh
COPY runtime/builder/builder-inputs.sh runtime/extensions/php-extension-libs.txt runtime/extensions/extensions-list.sh /build/
COPY --from=extensions /php-extensions.txt /build/php-extensions.txt
COPY runtime/frankenphp/entrypoint.go /go/src/app/caddy/frankenphp/drupack.go
COPY runtime/frankenphp/watch/watch.go /go/src/app/caddy/frankenphp/drupack_watch.go
COPY runtime/frankenphp/crtenv_other.go /go/src/app/caddy/frankenphp/drupack_crtenv_other.go
RUN bash /usr/local/bin/embed.sh
COPY runtime/php.ini runtime/cacert.pem /out/

FROM scratch AS uncompressed
COPY --from=runtime-musl /out/drupack /drupack

FROM scratch AS uncompressed-gnu
COPY --from=runtime-gnu /out/drupack /drupack

# A builder image per libc and a PHP compile do not share a runner, so one runner
# builds one runtime.
FROM scratch AS runtime-musl-files
COPY --from=runtime-musl /out /out

FROM scratch AS runtime-gnu-files
COPY --from=runtime-gnu /out /out

# One PLATFORM-LIBC directory per runtime. A local build carries its own
# architecture's two; the release passes all four.
FROM scratch AS local-runtimes
ARG TARGETARCH
COPY --from=runtime-musl /out /linux-${TARGETARCH}-musl
COPY --from=runtime-gnu /out /linux-${TARGETARCH}-glibc

FROM ${RUNTIMES} AS runtimes

FROM scratch AS no-runtime-components

FROM ${RUNTIME_COMPONENTS} AS runtime-components

# drupack-build runs here on any CI host, with no Docker daemon. The musl runtime
# is its PHP.
FROM ${NODE_IMAGE} AS node

FROM ${JOB_BASE} AS job
ARG DRUPACK_VERSION
ARG TARGETARCH
# Go moves to the next entry after a "|" on any error, so a dropped fetch gets
# a second attempt. go.sum checks either answer.
ENV GOPROXY="https://proxy.golang.org|https://proxy.golang.org,direct"
# The conformance suite reads process arguments with procps' ps. cweagans/composer-patches
# 1.x applies a patch to a dist install with GNU patch alone.
RUN apt-get update && apt-get install -y --no-install-recommends patch procps python3 \
    && rm -rf /var/lib/apt/lists/*
RUN wget -q -O /opt/composer.phar https://getcomposer.org/download/2.11.2/composer.phar \
    && echo 'dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028  /opt/composer.phar' | sha256sum -c -
COPY --from=runtimes / /opt/drupack/runtimes/
COPY --from=runtime-components / /opt/drupack/runtime-components/
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY application /opt/drupack/engine/application
COPY build /opt/drupack/engine/build
COPY launcher /opt/drupack/engine/launcher
COPY runtime/cacert.pem /opt/drupack/engine/runtime/
COPY runtime/extensions/php-extensions.txt runtime/extensions/check-extensions.py /opt/drupack/engine/runtime/extensions/
COPY runtime/builder/builder-inputs.sh runtime/builder/fetch-spc.sh runtime/builder/spc-sqlite.patch /opt/drupack/engine/runtime/builder/
COPY tests /opt/drupack/engine/tests
ENV DRUPACK_PHP=/opt/drupack/runtimes/linux-${TARGETARCH}-musl/drupack \
    DRUPACK_SPC=/opt/drupack/static-php-cli \
    DRUPACK_COMPOSER=/opt/composer.phar
# check-extensions.py runs this checkout on the musl runtime.
RUN bash /opt/drupack/engine/runtime/builder/fetch-spc.sh "$DRUPACK_SPC" \
    && PHPRC=$(dirname "$DRUPACK_PHP") DRUPACK_CA_FILE=/opt/drupack/engine/runtime/cacert.pem \
        "$DRUPACK_PHP" php-cli "$DRUPACK_COMPOSER" install \
        --working-dir="$DRUPACK_SPC" --no-dev --no-interaction --prefer-dist \
    && chmod -R a+rX "$DRUPACK_SPC"
# The packer builds the launcher from this module on every run, so its modules are
# fetched once here and the build runs as whichever user the CI host picks.
RUN cd /opt/drupack/engine/launcher \
    && go mod download \
    && CGO_ENABLED=0 go build -o /usr/local/bin/drupack-build ./cmd/drupack-build \
    && chmod -R a+rX /go/pkg/mod
ENV DRUPACK_ENGINE=/opt/drupack/engine \
    DRUPACK_ENGINE_VERSION=${DRUPACK_VERSION:-dev} \
    DRUPACK_RUNTIMES=/opt/drupack/runtimes \
    DRUPACK_RUNTIME_COMPONENTS=/opt/drupack/runtime-components \
    HOME=/tmp \
    GOCACHE=/tmp/go-build \
    GOTOOLCHAIN=local
