<?php

declare(strict_types=1);

namespace App\Domain\Investigation;

enum InvestigationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return self::Completed === $this || self::Failed === $this;
    }

    public function badge(): string
    {
        return match ($this) {
            self::Queued => 'neutral',
            self::Running => 'warn',
            self::Completed => 'ok',
            self::Failed => 'err',
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
