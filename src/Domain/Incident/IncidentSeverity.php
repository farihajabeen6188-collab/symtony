<?php

declare(strict_types=1);

namespace App\Domain\Incident;

use App\Domain\Event\Severity;

enum IncidentSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    /**
     * Deterministic initial severity from the triggering event:
     * critical → Critical, unhandled error → High, handled error log → Medium, warning → Low, notice → Info.
     */
    public static function fromEvent(Severity $severity, bool $isException, ?int $httpStatus): self
    {
        return match (true) {
            Severity::Critical === $severity => self::Critical,
            Severity::Error === $severity && ($isException || ($httpStatus ?? 0) >= 500) => self::High,
            Severity::Error === $severity => self::Medium,
            Severity::Warning === $severity => self::Low,
            default => self::Info,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
            self::Info => 0,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Critical => 'err-solid',
            self::High => 'err',
            self::Medium => 'warn',
            self::Low => 'blue',
            self::Info => 'neutral',
        };
    }
}
