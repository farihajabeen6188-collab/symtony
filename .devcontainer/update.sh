#!/usr/bin/env bash
# Deploy the latest main to a running codespace: pull, install, migrate, rebuild assets, restart the worker.
# Run inside the codespace:  bash .devcontainer/update.sh
# From your computer:        gh codespace ssh -c <name> -- 'cd /workspaces/symtony && bash .devcontainer/update.sh'
set -euo pipefail
cd "$(dirname "$0")/.."
git pull --ff-only
grep -q '^SYMTONY_PUBLIC_DEMO=' .env.local || echo 'SYMTONY_PUBLIC_DEMO=1' >> .env.local
grep -q '^SYMTONY_DEMO_AUTO_SIGN_IN=' .env.local || echo 'SYMTONY_DEMO_AUTO_SIGN_IN=1' >> .env.local
C="docker compose exec -T app"
$C composer install --no-interaction --no-progress
$C php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
bash .devcontainer/assets.sh
docker compose restart app worker
echo "Updated to $(git log --oneline -1)"
