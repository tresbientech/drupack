FROM dunglas/frankenphp:static-builder-gnu@sha256:77419ad4e1319db39ea3b2f88860b940faff2ce70dbd722ef0bd9c7d2d6f128d AS build

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
COPY packaging/embed.sh /usr/local/bin/embed.sh
COPY packaging/entrypoint.go /go/src/app/caddy/frankenphp/portable.go
RUN bash /usr/local/bin/embed.sh

FROM scratch AS uncompressed
COPY --from=build /out/portable-drupal /portable-drupal

FROM build AS compressed
ARG UPX_VERSION=5.2.1
RUN curl -fsSL "https://github.com/upx/upx/releases/download/v${UPX_VERSION}/upx-${UPX_VERSION}-amd64_linux.tar.xz" -o /tmp/upx.tar.xz \
    && echo '402162aad30af47e60dbd767fb2e64ca394ace9727ba1f40283641f1d1b91657  /tmp/upx.tar.xz' | sha256sum -c - \
    && tar -xJf /tmp/upx.tar.xz -C /tmp \
    && /tmp/upx-${UPX_VERSION}-amd64_linux/upx -9 /out/portable-drupal \
    && /tmp/upx-${UPX_VERSION}-amd64_linux/upx -t /out/portable-drupal

FROM scratch AS artifact
COPY --from=compressed /out/portable-drupal /portable-drupal
