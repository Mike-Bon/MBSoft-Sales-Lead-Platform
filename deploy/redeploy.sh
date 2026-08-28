#!/usr/bin/env bash
#
# Redeploy an update on Hostinger shared hosting.
# Run over SSH from the repository root after you have pushed a new tag.
#
#   cd ~/domains/mbsoft.online/public_html/app
#   bash deploy/redeploy.sh v1.0.2
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

TARGET="${1:-}"
[ -n "$TARGET" ] || { echo "Usage: bash deploy/redeploy.sh <tag-or-branch>   (e.g. v1.0.2)"; exit 1; }

PHP=""
for c in php8.3 php8.2 php /usr/bin/php8.3 /opt/alt/php83/usr/bin/php; do
    if command -v "$c" >/dev/null 2>&1 && "$c" -r 'exit(version_compare(PHP_VERSION,"8.2.0",">=")?0:1);' 2>/dev/null; then PHP="$c"; break; fi
done
[ -n "$PHP" ] || { echo "ERROR: no PHP >= 8.2 CLI found."; exit 1; }

if command -v composer >/dev/null 2>&1; then COMPOSER="composer"; else COMPOSER="$PHP composer.phar"; fi

echo "==> Maintenance mode on"
$PHP artisan down --retry=15 || true

echo "==> Fetching $TARGET"
git fetch --tags --prune
git checkout "$TARGET"

echo "==> composer install --no-dev"
COMPOSER_MEMORY_LIMIT=-1 $COMPOSER install --no-dev --optimize-autoloader --no-interaction

echo "==> Migrations"
$PHP artisan migrate --force

echo "==> Rebuilding caches"
$PHP artisan view:clear;  $PHP artisan view:cache
$PHP artisan event:clear; $PHP artisan event:cache
chmod -R 775 storage bootstrap/cache

echo "==> Restarting queue workers"
$PHP artisan queue:restart

echo "==> Maintenance mode off"
$PHP artisan up

echo "==> Done. Now on: $(git describe --tags --always)"
