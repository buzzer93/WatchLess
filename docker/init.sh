#!/bin/sh
set -eu
mkdir -p var/locks var/watchless/tmp var/cache-models
if [ ! -f var/app-secret ]; then
    php -r 'file_put_contents("var/app-secret", bin2hex(random_bytes(32)));'
fi
export APP_SECRET="$(cat var/app-secret)"
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console cache:clear --no-debug
php bin/console cache:warmup --no-debug
chown -R www-data:www-data var
chmod 700 var/watchless var/watchless/tmp var/locks
chmod 600 var/app-secret
