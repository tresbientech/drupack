ARG MUSL_BUILDER=dunglas/frankenphp:static-builder-musl@sha256:a78af5ef3b46b5f382a702ee7aed22b367a6dc1bce382de0aebac7f4d73dade1
FROM ${MUSL_BUILDER} AS build

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -fsSL https://getcomposer.org/download/2.8.12/composer.phar -o /usr/local/bin/composer.phar \
    && echo 'f446ea719708bb85fcbf4ef18def5d0515f1f9b4d703f6d820c9c1656e10a2f2  /usr/local/bin/composer.phar' | sha256sum -c -

WORKDIR /app
COPY composer.json composer.lock ./
RUN /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /usr/local/bin/composer.phar install --no-dev --prefer-dist --no-interaction --optimize-autoloader
COPY packaging/translations.tar.gz packaging/translations.json packaging/install-translations.php /build/
RUN /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /build/install-translations.php
COPY runtime/ ./
COPY packaging/site-templates.php web/sites/default/site-templates.php
RUN mkdir -p /app/seed/private /app/seed/tmp /app/seed/config /app/web/sites/default/files \
    && printf 'drupack-seed-hash-salt' > /app/seed/hash_salt \
    && cp /app/settings.php /app/web/sites/default/settings.php \
    && sed -i "s|__DRUPACK_DATABASE_CONFIGURATION__|['driver' => 'sqlite', 'database' => '/app/seed/site.sqlite', 'namespace' => 'Drupal\\\\sqlite\\\\Driver\\\\Database\\\\sqlite', 'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/']|" /app/web/sites/default/settings.php \
    && DRUPACK_RUNTIME_DATA_DIR=/app/seed DRUPACK_RUNTIME_HOST=localhost /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /app/vendor/drush/drush/drush.php site:install /app/recipes/drupal_cms_site_template_base --yes --db-url=sqlite://seed/site.sqlite --account-name=drupack-admin --account-pass=drupack-seed-password \
    && DRUPACK_RUNTIME_DATA_DIR=/app/seed DRUPACK_RUNTIME_HOST=localhost /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /app/vendor/drush/drush/drush.php pm:enable mcp_tools --yes \
    && mv /app/web/sites/default/files /app/seed/files \
    && rm -f /app/web/sites/default/settings.php
COPY packaging/embed.sh /usr/local/bin/embed.sh
COPY packaging/entrypoint.go /go/src/app/caddy/frankenphp/drupack.go
RUN bash /usr/local/bin/embed.sh

FROM scratch AS uncompressed
COPY --from=build /out/drupack /drupack

FROM build AS compressed
ARG UPX_VERSION=5.2.1
RUN case "$(uname -m)" in \
        x86_64) upx_arch=amd64; upx_checksum=402162aad30af47e60dbd767fb2e64ca394ace9727ba1f40283641f1d1b91657 ;; \
        aarch64) upx_arch=arm64; upx_checksum=a72d112c5970a904a31da0b9c84f919bc16b9a311787c12245508544a78c7d36 ;; \
        *) printf 'Unsupported UPX architecture: %s\n' "$(uname -m)" >&2; exit 1 ;; \
    esac \
    && upx_archive="upx-${UPX_VERSION}-${upx_arch}_linux.tar.xz" \
    && curl -fsSL "https://github.com/upx/upx/releases/download/v${UPX_VERSION}/${upx_archive}" -o /tmp/upx.tar.xz \
    && printf '%s  /tmp/upx.tar.xz\n' "$upx_checksum" | sha256sum -c - \
    && tar -xJf /tmp/upx.tar.xz -C /tmp \
    && "/tmp/upx-${UPX_VERSION}-${upx_arch}_linux/upx" -9 /out/drupack \
    && "/tmp/upx-${UPX_VERSION}-${upx_arch}_linux/upx" -t /out/drupack

FROM scratch AS artifact
COPY --from=compressed /out/drupack /drupack
