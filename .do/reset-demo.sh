#!/bin/sh
set -eu
php artisan deskops:production-check --database --no-interaction
php artisan deskops:demo-reset --force --exclusive --no-interaction
php artisan deskops:production-check --ready --no-interaction
