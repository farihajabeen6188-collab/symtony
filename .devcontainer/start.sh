#!/usr/bin/env bash
# Every codespace start: bring the stack back (containers do not survive a codespace stop on their own).
set -euo pipefail
cd "$(dirname "$0")/.."
for i in $(seq 1 30); do docker info >/dev/null 2>&1 && break; sleep 2; done
docker compose up -d --wait app db
docker compose up -d worker runner
if docker compose ps --services --all | grep -qx demo-shop; then docker compose up -d demo-shop; fi
# After the first boot (vendor installed), re-apply the runner job directory permissions.
if docker compose exec -T app test -f vendor/autoload.php; then
    docker compose exec -T app php bin/console symtony:setup >/dev/null
fi
