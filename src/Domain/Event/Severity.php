<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Event severity (Monolog / PSR-3 compatible, collapsed to six levels).
 */
enum Severity: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    public static function fromLoose(string|int|null $value): self
    {
        if (\is_int($value)) {
            // Monolog numeric levels
            return match (true) {
                $value >= 500 => self::Critical,
                $value >= 400 => self::Error,
                $value >= 300 => self::Warning,
                $value >= 250 => self::Notice,
                $value >= 200 => self::Info,
                default => self::Debug,
            };
        }

        return match (mb_strtolower(trim((string) $value))) {
            'debug', 'trace' => self::Debug,
            '', 'info', 'information', 'informational' => self::Info,
            'notice' => self::Notice,
            'warn', 'warning' => self::Warning,
            'error', 'err' => self::Error,
            'critical', 'crit', 'alert', 'emergency', 'emerg', 'fatal' => self::Critical,
            default => throw new \InvalidArgumentException(\sprintf('Unknown severity "%s".', (string) $value)),
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Notice => 2,
            self::Warning => 3,
            self::Error => 4,
            self::Critical => 5,
        };
    }

    public function isFailure(): bool
    {
        return $this->rank() >= self::Error->rank();
    }

    public function tone(): string
    {
        return match ($this) {
            self::Debug, self::Info => 'neutral',
            self::Notice => 'blue',
            self::Warning => 'warn',
            self::Error => 'err',
            self::Critical => 'err-solid',
        };
    }

    /** @return list<self> */
    public static function atLeast(self $min): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s): bool => $s->rank() >= $min->rank()));
    }
}
