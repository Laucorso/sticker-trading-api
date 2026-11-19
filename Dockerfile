FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl libzip-dev libicu-dev \
    && docker-php-ext-install pdo_mysql pcntl intl zip \
    && rm -rf /var/lib/apt/lists/*

# Opcache en CLI (artisan serve, tests, cola): evita releer y recompilar el código
# desde el bind mount de Windows en cada petición. file_cache lo conserva entre procesos.
RUN { \
      echo 'opcache.enable_cli=1'; \
      echo 'opcache.file_cache=/tmp/opcache'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=2'; \
    } > /usr/local/etc/php/conf.d/opcache-dev.ini \
 && mkdir -p /tmp/opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
