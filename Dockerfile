# The builder images carry the static PHP toolchain and name their libc in
# SPC_LIBC, which embed.sh reads to pick its link mode. A Linux executable
# carries both runtimes: the glibc one serves a rendered page several times
# faster, and the musl one is the single file that runs on any host.
#
# A runtime links the PHP that runtime/php-extensions.txt names, and
# PHP_EXTENSIONS takes effect while a builder image is built, so
# runtime/build-builder.sh builds one image per libc before this file runs.
ARG MUSL_BUILDER=drupack-builder-musl:local
ARG GNU_BUILDER=drupack-builder-gnu:local
# The job image runs drupack-build: Go for the packer and the launcher, Python for
# the conformance suite, and GNU tar for the payload's fixed archive options. It is
# a glibc host, the only kind that runs both runtimes, so a build of either libc
# is tested where it was built.
# docker buildx imagetools inspect golang:1.26-bookworm --format '{{.Manifest.Digest}}'
ARG JOB_BASE=golang:1.26-bookworm@sha256:a688600ca24f8a4d3ca77f95b0dd40704a9fc787c826660eb7ba0b641b8b175d

# php.ini and the trust bundle join the entry executable in each runtime
# directory, so the launcher's environment function finds both beside it.
# application/cacert.pem comes from https://curl.se/ca/cacert.pem, sha256
# f66dff1bdf8f96060b8177976f8b7d9254bc89bc4db933d769f7384d28480bc9, 121
# certificates. A refresh replaces the file and that checksum in one commit;
# no build fetches it.
FROM ${MUSL_BUILDER} AS runtime-musl
ARG DRUPACK_VERSION
COPY runtime/embed.sh /usr/local/bin/embed.sh
COPY runtime/php-extensions.txt runtime/php-extension-libs.txt runtime/extensions-list.sh /build/
COPY runtime/entrypoint.go /go/src/app/caddy/frankenphp/drupack.go
RUN bash /usr/local/bin/embed.sh
COPY application/php.ini application/cacert.pem /out/

FROM ${GNU_BUILDER} AS runtime-gnu
ARG DRUPACK_VERSION
COPY runtime/embed.sh /usr/local/bin/embed.sh
COPY runtime/php-extensions.txt runtime/php-extension-libs.txt runtime/extensions-list.sh /build/
COPY runtime/entrypoint.go /go/src/app/caddy/frankenphp/drupack.go
RUN bash /usr/local/bin/embed.sh
COPY application/php.ini application/cacert.pem /out/

FROM scratch AS uncompressed
COPY --from=runtime-musl /out/drupack /drupack

FROM scratch AS uncompressed-gnu
COPY --from=runtime-gnu /out/drupack /drupack

# One runner builds one runtime, since a builder image per libc and a PHP
# compile do not share a runner. Each exports its directory, which
# drupack-build takes as a --runtime.
FROM scratch AS runtime-musl-files
COPY --from=runtime-musl /out /out

FROM scratch AS runtime-gnu-files
COPY --from=runtime-gnu /out /out

# drupack-build runs here on any CI host, with no Docker daemon. The musl runtime
# is its PHP: a static executable carrying every extension the site runs with.
FROM ${JOB_BASE} AS job
ARG DRUPACK_VERSION
# The conformance suite reads process arguments with procps' ps.
RUN apt-get update && apt-get install -y --no-install-recommends procps python3 \
    && rm -rf /var/lib/apt/lists/*
RUN wget -q -O /opt/composer.phar https://getcomposer.org/download/2.8.12/composer.phar \
    && echo 'f446ea719708bb85fcbf4ef18def5d0515f1f9b4d703f6d820c9c1656e10a2f2  /opt/composer.phar' | sha256sum -c -
COPY --from=runtime-musl /out /opt/drupack/php
COPY application /opt/drupack/engine/application
COPY build /opt/drupack/engine/build
COPY launcher /opt/drupack/engine/launcher
COPY runtime/php-extensions.txt /opt/drupack/engine/runtime/php-extensions.txt
COPY tests /opt/drupack/engine/tests
# The packer builds the launcher from this module on every run, so its modules are
# fetched once here and the build runs as whichever user the CI host picks.
RUN cd /opt/drupack/engine/launcher \
    && go mod download \
    && CGO_ENABLED=0 go build -o /usr/local/bin/drupack-build ./cmd/drupack-build \
    && chmod -R a+rX /go/pkg/mod
ENV DRUPACK_ENGINE=/opt/drupack/engine \
    DRUPACK_ENGINE_VERSION=${DRUPACK_VERSION:-dev} \
    DRUPACK_PHP=/opt/drupack/php/drupack \
    DRUPACK_COMPOSER=/opt/composer.phar \
    HOME=/tmp \
    GOCACHE=/tmp/go-build \
    GOTOOLCHAIN=local
