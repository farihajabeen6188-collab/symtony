# SYMTONY developer commands for Windows without `make`. Same targets as the Makefile.
# Usage: ./dev.ps1 up | down | check | test | stan | lint | shell | spike-ai | mcp-stdio | console "<args>" | composer "<args>"
param([Parameter(Position = 0)][string]$Target = 'help', [Parameter(Position = 1)][string]$Rest = '')

$ErrorActionPreference = 'Stop'
function App([string]$cmd) { docker compose exec -T app sh -c $cmd; if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE } }

switch ($Target) {
    'up'        { docker compose up -d --wait; App 'composer install --no-interaction'; App 'php bin/console cache:warmup' }
    'down'      { docker compose down }
    'build'     { docker compose build }
    'shell'     { docker compose exec app bash }
    'console'   { App "php bin/console $Rest" }
    'composer'  { App "composer $Rest" }
    'lint'      { App 'php bin/console lint:container && php bin/console lint:yaml config --parse-tags && php bin/console lint:twig templates' }
    'stan'      { App 'php bin/console cache:warmup --env=dev >/dev/null && vendor/bin/phpstan analyse --no-progress --memory-limit=1G' }
    'test'      { App 'php bin/phpunit' }
    'check'     { foreach ($t in 'lint', 'stan', 'test') { & $PSCommandPath $t; if ($LASTEXITCODE -ne 0) { Write-Host "check failed at: $t" -ForegroundColor Red; exit $LASTEXITCODE } }; Write-Host 'check: all green' -ForegroundColor Green }
    'spike-ai'  { App 'php bin/console app:spike:ai' }
    'mcp-stdio' { docker compose exec -T app php bin/console mcp:server }
    default     { Get-Content $PSCommandPath | Select-Object -First 3 }
}
