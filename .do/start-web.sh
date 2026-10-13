#!/bin/sh
set -eu
php artisan deskops:production-check --web --no-interaction
php artisan config:cache --no-interaction
exec apache2-foreground
