#!/usr/bin/env bash
# Build CSS and compile assets for production. Order matters: clear the cache *before* compiling,
# otherwise AssetMapper reuses the previously compiled files and the old CSS keeps being served.
set -euo pipefail
cd "$(dirname "$0")/.."
C="docker compose exec -T app"
$C php bin/console importmap:install
$C php bin/console tailwind:build --minify
$C php bin/console cache:clear
$C rm -rf public/assets
$C php bin/console asset-map:compile
$C php bin/console cache:clear
