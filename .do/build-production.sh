#!/bin/sh
set -eu

if [ -n "${APP_KEY:-}" ] || [ -n "${DATABASE_URL:-}" ] || [ -f .env ]; then
    echo 'Build refused: runtime secrets or a local environment file are present.' >&2
    exit 1
fi

case "${1:-}" in
    assets)
        test "$(node --version)" = "v$(cat .node-version)"
        test "$(npm --version)" = "$(node -p 'require("./package.json").packageManager.split("@")[1]')"
        npm ci --include=dev --ignore-scripts
        npm audit
        npm run build
        ;;
    php)
        php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 3 ? 0 : 1);'
        test "$(composer --version --no-ansi | cut -d ' ' -f 3)" = "$(cat .composer-version)"
        composer validate --strict
        composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
        composer check-platform-reqs --no-dev
        composer audit --locked --no-dev --no-interaction
        php artisan config:clear --no-interaction
        php artisan route:cache --no-interaction
        php artisan event:cache --no-interaction
        php artisan view:cache --no-interaction
        test ! -f bootstrap/cache/config.php
        ;;
    *)
        echo 'Usage: sh .do/build-production.sh assets|php' >&2
        exit 1
        ;;
esac
