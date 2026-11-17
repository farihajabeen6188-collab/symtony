<?php

declare(strict_types=1);

namespace App\Domain\Application;

enum ConnectionStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Silent = 'silent';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for first event',
            self::Connected => 'Receiving events',
            self::Silent => 'Silent',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'fg-3',
            self::Connected => 'ok',
            self::Silent => 'warn',
        };
    }
}
