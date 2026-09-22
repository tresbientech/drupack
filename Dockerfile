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
# Composer, the translation fetch, the site install and the packer need a PHP
# and a Go toolchain rather than the runtime's extension set, so they stay on
# the published image.
ARG APP_BUILDER=dunglas/frankenphp:static-builder-musl@sha256:a78af5ef3b46b5f382a702ee7aed22b367a6dc1bce382de0aebac7f4d73dade1

# The application is PHP source, vendor, translations and a seeded database.
# None of it depends on the libc a runtime links against, so one builder image
# installs it once and both runtimes carry the same payload.
FROM ${APP_BUILDER} AS app

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -fsSL https://getcomposer.org/download/2.8.12/composer.phar -o /usr/local/bin/composer.phar \
    && echo 'f446ea719708bb85fcbf4ef18def5d0515f1f9b4d703f6d820c9c1656e10a2f2  /usr/local/bin/composer.phar' | sha256sum -c -

WORKDIR /app
COPY application/composer.json application/composer.lock ./
RUN /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /usr/local/bin/composer.phar install --no-dev --prefer-dist --no-interaction --optimize-autoloader
COPY build/install-translations.php /build/
# The translation fetch reaches ftp.drupal.org over TLS on the bundle pinned below.
COPY application/cacert.pem /build/cacert.pem
RUN CURL_CA_BUNDLE=/build/cacert.pem /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /build/install-translations.php
COPY application/ ./
COPY build/site-templates.php web/sites/default/site-templates.php
RUN /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /usr/local/bin/composer.phar dump-autoload --optimize
RUN mkdir -p /app/seed/private /app/seed/tmp /app/seed/config /app/web/sites/default/files \
    && printf 'drupack-seed-hash-salt' > /app/seed/hash_salt \
    && cp /app/settings.php /app/web/sites/default/settings.php \
    && sed -i "s|__DRUPACK_DATABASE_CONFIGURATION__|['driver' => 'sqlite', 'database' => '/app/seed/site.sqlite', 'namespace' => 'Drupal\\\\sqlite\\\\Driver\\\\Database\\\\sqlite', 'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/']|" /app/web/sites/default/settings.php \
    && DRUPACK_RUNTIME_DATA_DIR=/app/seed DRUPACK_RUNTIME_HOST=localhost /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /app/vendor/drush/drush/drush.php site:install /app/recipes/mercury_demo --yes --db-url=sqlite://seed/site.sqlite --account-name=drupack-admin --account-pass=drupack-seed-password \
    && DRUPACK_RUNTIME_DATA_DIR=/app/seed DRUPACK_RUNTIME_HOST=localhost /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /app/vendor/drush/drush/drush.php pm:enable mcp_tools --yes \
    # automatic_updates and package_manager: add any other module here that a future Mercury Demo release enables and that also depends on package_manager.
    && DRUPACK_RUNTIME_DATA_DIR=/app/seed DRUPACK_RUNTIME_HOST=localhost /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /app/vendor/drush/drush/drush.php pm:uninstall automatic_updates package_manager --yes \
    && mv /app/web/sites/default/files /app/seed/files \
    && printf '%s\n%s\n' '<?php' "require getenv('DRUPACK_RUNTIME_DATA_DIR') . DIRECTORY_SEPARATOR . 'settings.php';" > /app/web/sites/default/settings.php
COPY build/app-payload.sh /usr/local/bin/app-payload.sh
RUN bash /usr/local/bin/app-payload.sh

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
# compile do not share a runner. A later job hands the exported directory back
# as a build context named for the stage it replaces, and the packed stage
# reads /out at the same path either way.
FROM scratch AS runtime-musl-files
COPY --from=runtime-musl /out /out

FROM scratch AS runtime-gnu-files
COPY --from=runtime-gnu /out /out

# The runtimes are listed with the one needing a host loader first, which is
# the order the launcher tries them in.
FROM ${APP_BUILDER} AS packed
ARG DRUPACK_VERSION
COPY launcher /src/launcher
COPY --from=app /go/src/app/app-payload.tar /go/src/app/app_checksum.txt /payload/
COPY --from=runtime-musl /out /runtime/musl
COPY --from=runtime-gnu /out /runtime/glibc
RUN export CGO_ENABLED=0 \
    && mkdir -p /packed \
    && cd /src/launcher \
    && go run ./cmd/pack -runtime glibc=/runtime/glibc -runtime musl=/runtime/musl \
        -entry drupack -version "${DRUPACK_VERSION:-dev}" -source /src/launcher \
        -output /packed/drupack -app /payload/app-payload.tar -app-checksum /payload/app_checksum.txt

FROM scratch AS artifact
COPY --from=packed /packed/drupack /drupack
