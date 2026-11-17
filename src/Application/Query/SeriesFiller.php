<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Shared\TimeRange;

/**
 * Turns sparse SQL buckets into a continuous series (missing buckets = 0, which is a real zero:
 * the query covered that window and found nothing).
 */
final class SeriesFiller
{
    /**
     * @param array<int|string, int|string> $buckets epoch seconds (aligned to the 2000-01-01 origin) => count
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function fill(array $buckets, TimeRange $range, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $step = $range->bucketSeconds();
        $origin = 946684800; // 2000-01-01T00:00:00Z
        $start = $range->since($now)->getTimestamp();
        $start -= ($start - $origin) % $step;
        $series = [];
        for ($t = $start; $t <= $now->getTimestamp(); $t += $step) {
            $series[] = [$t, (int) ($buckets[$t] ?? $buckets[(string) $t] ?? 0)];
        }

        return $series;
    }
}
