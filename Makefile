# SYMTONY developer commands. Everything runs inside Docker.
# No `make` on Windows? Use `./dev.ps1 <target>` (same targets) or the raw commands in README.

DC  = docker compose
APP = $(DC) exec -T app
CON = $(APP) php bin/console

.DEFAULT_GOAL := help
.PHONY: help up down build shell composer install console test check stan lint demo mcp-stdio spike-ai

help: ## List targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-12s %s\n", $$1, $$2}'

build: ## Build images
	$(DC) build

up: ## Start the stack (http://localhost:8080), migrate, create the demo account, build CSS
	$(DC) up -d --wait app db
	$(APP) composer install --no-interaction
	$(CON) doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(CON) symtony:setup
	$(CON) tailwind:build
	$(CON) cache:warmup

down: ## Stop the stack
	$(DC) down

shell: ## Shell into the app container
	$(DC) exec app bash

composer: ## Run composer, e.g. make composer c="require foo/bar"
	$(APP) composer $(c)

console: ## Run bin/console, e.g. make console c="debug:router"
	$(CON) $(c)

lint: ## Container, Twig, YAML lint + design-token guard
	$(APP) sh bin/check-templates.sh
	$(CON) lint:container
	$(CON) lint:yaml config --parse-tags
	$(CON) lint:twig templates

stan: ## PHPStan
	$(CON) cache:warmup --env=dev >/dev/null
	$(APP) vendor/bin/phpstan analyse --no-progress --memory-limit=1G

test: ## PHPUnit (SYMTONY + bundle, then the demo shop suite)
	$(APP) php bin/console doctrine:database:create --env=test --if-not-exists --quiet
	$(APP) php bin/phpunit
	$(DC) exec -T demo-shop php bin/phpunit

check: lint stan test ## Everything that must be green before a commit

demo: up ## Start everything, install the demo shop, create the demo app + token, replay seeded history
	$(DC) up -d demo-shop
	$(DC) exec -T demo-shop composer install --no-interaction
	$(DC) exec -T demo-shop sh -c "mkdir -p var/data && php bin/console cache:clear -q"
	$(CON) symtony:demo:install
	@echo "Open http://localhost:8080 (demo@symtony.local / symtony-demo), then Applications → Acme Shop → Run failure scenario."

mcp-stdio: ## Run the MCP server over STDIO (for MCP clients)
	$(DC) exec -T app php bin/console mcp:server

spike-ai: ## Phase 0: verify tool calling + structured output on the configured model
	$(CON) app:spike:ai
