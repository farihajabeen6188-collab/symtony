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

up: ## Start the stack (http://localhost:8080)
	$(DC) up -d --wait
	$(APP) composer install --no-interaction
	$(CON) cache:warmup

down: ## Stop the stack
	$(DC) down

shell: ## Shell into the app container
	$(DC) exec app bash

composer: ## Run composer, e.g. make composer c="require foo/bar"
	$(APP) composer $(c)

console: ## Run bin/console, e.g. make console c="debug:router"
	$(CON) $(c)

lint: ## Container, Twig and YAML lint
	$(CON) lint:container
	$(CON) lint:yaml config --parse-tags
	$(CON) lint:twig templates

stan: ## PHPStan
	$(CON) cache:warmup --env=dev >/dev/null
	$(APP) vendor/bin/phpstan analyse --no-progress --memory-limit=1G

test: ## PHPUnit
	$(APP) php bin/phpunit

check: lint stan test ## Everything that must be green before a commit

demo: ## Reset and seed the demo (implemented in Phase 3)
	@echo "Demo seeding arrives in Phase 3."

mcp-stdio: ## Run the MCP server over STDIO (for MCP clients)
	$(DC) exec -T app php bin/console mcp:server

spike-ai: ## Phase 0: verify tool calling + structured output on the configured model
	$(CON) app:spike:ai
