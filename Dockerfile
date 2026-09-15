FROM dunglas/frankenphp:static-builder-gnu@sha256:77419ad4e1319db39ea3b2f88860b940faff2ce70dbd722ef0bd9c7d2d6f128d AS build

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -fsSL https://getcomposer.org/download/2.8.12/composer.phar -o /usr/local/bin/composer.phar \
    && echo 'f446ea719708bb85fcbf4ef18def5d0515f1f9b4d703f6d820c9c1656e10a2f2  /usr/local/bin/composer.phar' | sha256sum -c -

WORKDIR /app
COPY composer.json composer.lock ./
RUN /go/src/app/dist/static-php-cli/buildroot/bin/frankenphp php-cli /usr/local/bin/composer.phar install --no-dev --prefer-dist --no-interaction --optimize-autoloader
COPY runtime/ ./
COPY packaging/site-templates.php web/sites/default/site-templates.php
COPY packaging/embed.sh /usr/local/bin/embed.sh
RUN bash /usr/local/bin/embed.sh

FROM scratch AS artifact
COPY --from=build /out/portable-drupal /portable-drupal
