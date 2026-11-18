#!/bin/sh
# Solo el servicio "app" prepara el proyecto; el resto (cola, scheduler, reverb)
# espera a que app esté sano y arranca directamente.
set -e

if [ "$PREPARE_APP" = "1" ]; then
    [ -f .env ] || cp .env.example .env
    composer install --no-interaction --prefer-dist
    grep -q '^APP_KEY=base64' .env || php artisan key:generate --force
    php artisan migrate --force
    php artisan db:seed --force   # no hace nada si ya hay datos
fi

exec "$@"
