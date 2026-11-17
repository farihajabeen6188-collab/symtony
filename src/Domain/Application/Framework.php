<?php

declare(strict_types=1);

namespace App\Domain\Application;

enum Framework: string
{
    case Symfony = 'symfony';
    case Laravel = 'laravel';
    case PlainPhp = 'php';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Symfony => 'Symfony',
            self::Laravel => 'Laravel',
            self::PlainPhp => 'Plain PHP',
            self::Other => 'Other (any language)',
        };
    }
}
