ARG MUSL_BUILDER=dunglas/frankenphp:static-builder-musl@sha256:a78af5ef3b46b5f382a702ee7aed22b367a6dc1bce382de0aebac7f4d73dade1
FROM ${MUSL_BUILDER} AS build

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -fsSL https://getcomposer.org/download/2.8.12/composer.phar -o /usr/local/bin/composer.phar \
    && echo 'f446ea719708bb85fcbf4ef18def5d0515f1f9b4d703f6d820c9c1656e10a2f2  /usr/local/bin/composer.phar' | sha256sum -c -

WORKDIR /app
COPY drupal/composer.json drupal/composer.lock ./
RUN /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /usr/local/bin/composer.phar install --no-dev --prefer-dist --no-interaction --optimize-autoloader
COPY packaging/install-translations.php /build/
RUN /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /build/install-translations.php
COPY runtime/ ./
COPY packaging/site-templates.php web/sites/default/site-templates.php
RUN mkdir -p /app/seed/private /app/seed/tmp /app/seed/config /app/web/sites/default/files \
    && printf 'drupack-seed-hash-salt' > /app/seed/hash_salt \
    && cp /app/settings.php /app/web/sites/default/settings.php \
    && sed -i "s|__DRUPACK_DATABASE_CONFIGURATION__|['driver' => 'sqlite', 'database' => '/app/seed/site.sqlite', 'namespace' => 'Drupal\\\\sqlite\\\\Driver\\\\Database\\\\sqlite', 'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/']|" /app/web/sites/default/settings.php \
    && DRUPACK_RUNTIME_DATA_DIR=/app/seed DRUPACK_RUNTIME_HOST=localhost /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /app/vendor/drush/drush/drush.php site:install /app/recipes/byte --yes --db-url=sqlite://seed/site.sqlite --account-name=drupack-admin --account-pass=drupack-seed-password \
    && DRUPACK_RUNTIME_DATA_DIR=/app/seed DRUPACK_RUNTIME_HOST=localhost /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /app/vendor/drush/drush/drush.php pm:enable mcp_tools --yes \
    # automatic_updates and package_manager: add any other module here that a future Byte release enables and that also depends on package_manager.
    && DRUPACK_RUNTIME_DATA_DIR=/app/seed DRUPACK_RUNTIME_HOST=localhost /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /app/vendor/drush/drush/drush.php pm:uninstall automatic_updates package_manager --yes \
    && mv /app/web/sites/default/files /app/seed/files \
    && rm -f /app/web/sites/default/settings.php
ARG DRUPACK_VERSION
COPY packaging/embed.sh /usr/local/bin/embed.sh
COPY packaging/entrypoint.go /go/src/app/caddy/frankenphp/drupack.go
RUN bash /usr/local/bin/embed.sh

FROM scratch AS uncompressed
COPY --from=build /out/drupack /drupack

FROM build AS packed
ARG DRUPACK_VERSION
COPY packaging/launcher /src/launcher
RUN export CGO_ENABLED=0 \
    && mkdir -p /packed \
    && cd /src/launcher \
    && go run ./cmd/pack -runtime /out -entry drupack -version "${DRUPACK_VERSION:-dev}" -source /src/launcher -output /packed/drupack

FROM scratch AS artifact
COPY --from=packed /packed/drupack /drupack
