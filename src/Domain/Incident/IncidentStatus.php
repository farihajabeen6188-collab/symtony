<?php

declare(strict_types=1);

namespace App\Domain\Incident;

enum IncidentStatus: string
{
    case New = 'new';
    case Investigating = 'investigating';
    case Identified = 'identified';
    case Resolved = 'resolved';
    case Reopened = 'reopened';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::New => 'accent',
            self::Investigating => 'warn',
            self::Identified => 'ai',
            self::Resolved => 'ok',
            self::Reopened => 'err',
        };
    }

    public function isOpen(): bool
    {
        return self::Resolved !== $this;
    }
}
