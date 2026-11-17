<?php

declare(strict_types=1);

namespace App\Domain\Application;

enum IngestionMethod: string
{
    case SymfonyBundle = 'symfony_bundle';
    case Monolog = 'monolog';
    case HttpJson = 'http_json';
    case Demo = 'demo';

    public function label(): string
    {
        return match ($this) {
            self::SymfonyBundle => 'Symfony bundle',
            self::Monolog => 'Monolog handler',
            self::HttpJson => 'HTTP JSON API',
            self::Demo => 'Demo shop',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SymfonyBundle => 'Captures requests, controllers, Doctrine queries, HTTP calls, Messenger and logs with trace context. Richest timelines.',
            self::Monolog => 'Sends your existing Monolog records. Works in any PHP app that uses Monolog.',
            self::HttpJson => 'POST structured JSON events from any language or tool.',
            self::Demo => 'A bundled shop app with a real checkout bug. Best way to see SYMTONY end to end.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::SymfonyBundle => 'lucide:package',
            self::Monolog => 'lucide:scroll-text',
            self::HttpJson => 'lucide:braces',
            self::Demo => 'lucide:shopping-cart',
        };
    }
}
