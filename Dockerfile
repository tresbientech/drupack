ARG GNU_BUILDER=dunglas/frankenphp:static-builder-gnu@sha256:77419ad4e1319db39ea3b2f88860b940faff2ce70dbd722ef0bd9c7d2d6f128d
ARG COMPARISON_BUILDER=${GNU_BUILDER}
ARG ARTIFACT_BUILD=build
FROM ${GNU_BUILDER} AS build

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
    && printf 'portable-seed-hash-salt' > /app/seed/hash_salt \
    && cp /app/settings.php /app/web/sites/default/settings.php \
    && sed -i "s|__PORTABLE_DATABASE_CONFIGURATION__|['driver' => 'sqlite', 'database' => '/app/seed/site.sqlite', 'namespace' => 'Drupal\\\\sqlite\\\\Driver\\\\Database\\\\sqlite', 'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/']|" /app/web/sites/default/settings.php \
    && PORTABLE_DATA_DIR=/app/seed PORTABLE_HOST=localhost /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /app/vendor/drush/drush/drush.php site:install /app/recipes/drupal_cms_site_template_base --yes --db-url=sqlite://seed/site.sqlite --account-name=portable-admin --account-pass=portable-seed-password \
    && PORTABLE_DATA_DIR=/app/seed PORTABLE_HOST=localhost /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /app/vendor/drush/drush/drush.php pm:enable mcp_tools --yes \
    && mv /app/web/sites/default/files /app/seed/files \
    && rm -f /app/web/sites/default/settings.php
COPY packaging/embed.sh /usr/local/bin/embed.sh
COPY packaging/entrypoint.go /go/src/app/caddy/frankenphp/portable.go
RUN bash /usr/local/bin/embed.sh

FROM ${COMPARISON_BUILDER} AS minimal-runtime
ENV SPC_CONCURRENCY=4
ARG PHP_VERSION=8.5.10
ARG PHP_EXTENSIONS=ctype,curl,dom,exif,fileinfo,filter,gd,iconv,intl,mbregex,mbstring,mysqli,mysqlnd,opcache,openssl,password-argon2,pcntl,pdo,pdo_mysql,pdo_pgsql,pdo_sqlite,phar,session,simplexml,sodium,tokenizer,xml,xmlreader,xmlwriter,zip,zlib
ARG PHP_EXTENSION_LIBS=brotli,watcher,freetype,libjpeg,libwebp,libavif
WORKDIR /go/src/app/dist/static-php-cli
RUN ./spc download --with-php="${PHP_VERSION}" --for-extensions="${PHP_EXTENSIONS}" --for-libs="${PHP_EXTENSION_LIBS}" --without-suggestions --retry=3
ARG CACHED_NATIVE_LIBS=bzip2,xz,zstd,libssh2,ldap,gmp,nghttp2,nghttp3,ngtcp2
RUN ./spc build --enable-zts --build-embed --disable-opcache-jit "${PHP_EXTENSIONS}" --with-libs="${PHP_EXTENSION_LIBS},${CACHED_NATIVE_LIBS}" \
    || { tail -n 100 log/spc.shell.log; exit 1; }

FROM minimal-runtime AS comparison-build
COPY --from=build /go/src/app/app.tar /go/src/app/app_checksum.txt /go/src/app/
COPY packaging/embed.sh /usr/local/bin/embed.sh
COPY packaging/entrypoint.go /go/src/app/caddy/frankenphp/portable.go
RUN bash /usr/local/bin/embed.sh --reuse-archive

FROM ${ARTIFACT_BUILD} AS selected-build

FROM scratch AS uncompressed
COPY --from=selected-build /out/portable-drupal /portable-drupal

FROM selected-build AS compressed
ARG UPX_VERSION=5.2.1
RUN curl -fsSL "https://github.com/upx/upx/releases/download/v${UPX_VERSION}/upx-${UPX_VERSION}-amd64_linux.tar.xz" -o /tmp/upx.tar.xz \
    && echo '402162aad30af47e60dbd767fb2e64ca394ace9727ba1f40283641f1d1b91657  /tmp/upx.tar.xz' | sha256sum -c - \
    && tar -xJf /tmp/upx.tar.xz -C /tmp \
    && /tmp/upx-${UPX_VERSION}-amd64_linux/upx -9 /out/portable-drupal \
    && /tmp/upx-${UPX_VERSION}-amd64_linux/upx -t /out/portable-drupal

FROM scratch AS artifact
COPY --from=compressed /out/portable-drupal /portable-drupal
