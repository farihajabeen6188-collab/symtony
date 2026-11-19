#!/usr/bin/env bash
# First boot of a GitHub Codespace: production settings behind the Codespaces HTTPS proxy,
# then the same steps as `make up` + `make demo`. Safe to run again.
set -euo pipefail
cd "$(dirname "$0")/.."

PUBLIC_URL="http://localhost:8080"
if [ -n "${CODESPACE_NAME:-}" ]; then
    PUBLIC_URL="https://${CODESPACE_NAME}-8080.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"
fi

if [ ! -f .env.local ]; then
    cat > .env.local <<ENV
# Written by .devcontainer/setup.sh for this codespace. Not committed.
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$(openssl rand -hex 32)
DEFAULT_URI=${PUBLIC_URL}
TRUSTED_PROXIES=REMOTE_ADDR
ENV
    echo "Wrote .env.local (prod, public URL ${PUBLIC_URL})"
fi

# The image sets APP_ENV=dev as a real environment variable, which wins over .env.local.
# Override it for the public services so the codespace never runs in dev mode (no profiler, no debug).
cat > compose.override.yaml <<'YAML'
# Written by .devcontainer/setup.sh: production mode in the codespace. Not committed.
services:
  app:
    environment: { APP_ENV: prod, APP_DEBUG: "0" }
  worker:
    environment: { APP_ENV: prod, APP_DEBUG: "0" }
YAML

bash .devcontainer/start.sh

C="docker compose exec -T app"
$C composer install --no-interaction --no-progress
$C php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
$C php bin/console symtony:setup
bash .devcontainer/assets.sh
docker compose up -d worker runner

docker compose up -d demo-shop
docker compose exec -T demo-shop composer install --no-interaction --no-progress
docker compose exec -T demo-shop sh -c "mkdir -p var/data && php bin/console cache:clear -q"
$C php bin/console symtony:demo:install
docker compose restart worker

echo
echo "SYMTONY is running at ${PUBLIC_URL}"
echo "Make it public: gh codespace ports visibility 8080:public -c \"\$CODESPACE_NAME\""
