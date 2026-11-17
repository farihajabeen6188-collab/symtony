<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

enum ThemePreference: string
{
    case Dark = 'dark';
    case Light = 'light';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Dark => 'Dark',
            self::Light => 'Light',
            self::System => 'Match system',
        };
    }
}
