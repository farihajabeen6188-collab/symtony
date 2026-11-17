<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Application\Application;
use App\Domain\Application\IngestionMethod;

/**
 * Setup instructions for each ingestion method. These must match what packages/symtony-bundle
 * and the ingestion API actually implement — keep them in sync (tests render every method).
 */
final class IntegrationSnippets
{
    public const TOKEN_PLACEHOLDER = 'sym_xxxx_your_token_here';

    /**
     * @return list<array{title: string, description: string, lang: string, filename: ?string, code: string}>
     */
    public function for(Application $app, string $endpoint, ?string $token): array
    {
        $token ??= self::TOKEN_PLACEHOLDER;
        $service = $app->getSlug();

        return match ($app->getIngestionMethod()) {
            IngestionMethod::SymfonyBundle => [
                [
                    'title' => 'Install the bundle',
                    'description' => 'The bundle is not on Packagist yet. Clone SYMTONY and point Composer at its packages/symtony-bundle folder.',
                    'lang' => 'bash', 'filename' => null,
                    'code' => "composer config repositories.symtony path /path/to/symtony/packages/symtony-bundle\ncomposer require symtony/symfony-bundle:@dev",
                ],
                [
                    'title' => 'Configure it',
                    'description' => 'Register the bundle (Flex does this automatically) and add the configuration.',
                    'lang' => 'yaml', 'filename' => 'config/packages/symtony.yaml',
                    'code' => "symtony:\n    endpoint: '%env(SYMTONY_ENDPOINT)%'\n    token: '%env(SYMTONY_TOKEN)%'\n    service: '{$service}'\n    release: '%env(default::APP_RELEASE)%'",
                ],
                [
                    'title' => 'Add your credentials',
                    'description' => 'Keep the token out of version control.',
                    'lang' => 'bash', 'filename' => '.env.local',
                    'code' => "SYMTONY_ENDPOINT={$endpoint}\nSYMTONY_TOKEN={$token}",
                ],
            ],
            IngestionMethod::Monolog => [
                [
                    'title' => 'Install the handler',
                    'description' => 'The Monolog handler ships in the same package and works without Symfony.',
                    'lang' => 'bash', 'filename' => null,
                    'code' => "composer config repositories.symtony path /path/to/symtony/packages/symtony-bundle\ncomposer require symtony/symfony-bundle:@dev",
                ],
                [
                    'title' => 'Push it onto your logger',
                    'description' => 'Records at warning level and above are sent in batches when the request ends.',
                    'lang' => 'php', 'filename' => null,
                    'code' => "use Monolog\\Level;\nuse Symtony\\Bridge\\Monolog\\SymtonyHandler;\n\n\$logger->pushHandler(new SymtonyHandler(\n    endpoint: '{$endpoint}',\n    token: getenv('SYMTONY_TOKEN'),\n    service: '{$service}',\n    level: Level::Warning,\n));",
                ],
                [
                    'title' => 'Set the token',
                    'description' => 'Read it from the environment, never hard-code it.',
                    'lang' => 'bash', 'filename' => '.env.local',
                    'code' => "SYMTONY_TOKEN={$token}",
                ],
            ],
            IngestionMethod::HttpJson, IngestionMethod::Demo => [
                [
                    'title' => 'Send an event',
                    'description' => 'POST one event object, or {"events": [...]} with up to 500 events. Only "timestamp" and "message" are required.',
                    'lang' => 'bash', 'filename' => null,
                    'code' => "curl -X POST {$endpoint} \\\n  -H 'Authorization: Bearer {$token}' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\n    \"timestamp\": \"".gmdate('Y-m-d\TH:i:s.000\Z')."\",\n    \"severity\": \"error\",\n    \"service\": \"{$service}\",\n    \"type\": \"exception\",\n    \"message\": \"Payment declined for order 1042\",\n    \"trace_id\": \"4bf92f3577b34da6a3ce929d0e0e4736\",\n    \"exception\": {\"class\": \"App\\\\\\\\PaymentDeclined\", \"message\": \"Card declined\"}\n  }'",
                ],
                [
                    'title' => 'Read the response',
                    'description' => '202 means accepted. Invalid events are listed per index with field errors; valid ones in the same batch are still stored.',
                    'lang' => 'json', 'filename' => null,
                    'code' => "{\n  \"accepted\": 1,\n  \"duplicates\": 0,\n  \"rejected\": [],\n  \"redacted_values\": 0,\n  \"event_ids\": [\"019a…\"]\n}",
                ],
            ],
        };
    }
}
