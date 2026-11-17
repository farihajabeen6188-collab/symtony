<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * The global time window from the top bar. Every time-based list/metric query uses it.
 */
enum TimeRange: string
{
    case Hour = '1h';
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';

    public function label(): string
    {
        return match ($this) {
            self::Hour => 'Last hour',
            self::Day => 'Last 24 hours',
            self::Week => 'Last 7 days',
            self::Month => 'Last 30 days',
        };
    }

    public function since(?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $now ??= new \DateTimeImmutable();

        return $now->sub(new \DateInterval(match ($this) {
            self::Hour => 'PT1H',
            self::Day => 'P1D',
            self::Week => 'P7D',
            self::Month => 'P30D',
        }));
    }

    /** Bucket width used by charts for this range, as a Postgres interval. */
    public function bucket(): string
    {
        return match ($this) {
            self::Hour => '1 minute',
            self::Day => '30 minutes',
            self::Week => '3 hours',
            self::Month => '12 hours',
        };
    }

    public function bucketSeconds(): int
    {
        return match ($this) {
            self::Hour => 60,
            self::Day => 1800,
            self::Week => 10800,
            self::Month => 43200,
        };
    }
}
