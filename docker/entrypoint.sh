#!/bin/sh
set -e

echo "Container initialization started..."

git config --global --add safe.directory '*'

echo "Installing PHP dependencies..."
composer config --global process-timeout 2000
composer install --no-interaction

echo "Preparing dev and test databases..."
php bin/console doctrine:database:create --if-not-exists --no-interaction
php bin/console doctrine:database:create --if-not-exists --no-interaction --env=test

echo "Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

echo "Fixing permissions..."
mkdir -p var public/uploads
chown -R www-data:www-data var public/uploads

echo "Initialization complete. Starting nginx, php-fpm and the messenger worker..."

exec "$@"
