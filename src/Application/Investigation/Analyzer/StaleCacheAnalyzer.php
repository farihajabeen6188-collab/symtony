<?php

declare(strict_types=1);

namespace App\Application\Investigation\Analyzer;

use App\Application\Investigation\EvidencePackage;
use App\Application\Investigation\FindingDraft;
use App\Domain\Investigation\EvidenceItem;

/**
 * Detects a decision made from a cached value that predates a write to the underlying data:
 *  cache hit (age A) at t_read → a write happened in (t_read − A, t_read) → a later write in the request failed.
 * The write usually comes from another trace, so the link is inferred and the result is a hypothesis.
 */
final class StaleCacheAnalyzer implements Analyzer
{
    public const SOURCE = 'heuristic:stale_cache';

    public function name(): string
    {
        return 'stale_cache';
    }

    public function analyze(EvidencePackage $p): array
    {
        $failure = EvidenceQuery::firstFailure($p);
        if (null === $failure) {
            return [];
        }
        $failedAt = EvidenceQuery::offset($failure);

        foreach (EvidenceQuery::inTrace($p, 'cache') as $read) {
            $d = $read->getData();
            if (true !== ($d['cache_hit'] ?? null) || !isset($d['cache_age_seconds']) || EvidenceQuery::offset($read) >= $failedAt) {
                continue;
            }
            $readAt = EvidenceQuery::offset($read);
            $computedAt = $readAt - (float) $d['cache_age_seconds'] * 1000;

            // Writes (any trace) after the cached value was computed and before it was read.
            $writes = $p->where(static function (array $w, EvidenceItem $i) use ($computedAt, $readAt): bool {
                $isWrite = \in_array(strtoupper((string) ($w['db_operation'] ?? '')), ['UPDATE', 'INSERT', 'DELETE'], true)
                    || isset($w['inventory']['from']);
                $at = (float) ($w['offset_ms'] ?? -1);

                return $isWrite && 'event' !== $i->getKind() && $at > $computedAt && $at < $readAt;
            });
            if ([] === $writes) {
                continue;
            }

            $detail = '';
            foreach ($writes as $w) {
                $inv = $w->getData()['inventory'] ?? null;
                if (\is_array($inv) && isset($inv['from'], $inv['to'])) {
                    $detail = \sprintf(' (%s changed from %s to %s)', (string) ($inv['sku'] ?? 'the item'), (string) $inv['from'], (string) $inv['to']);
                }
            }
            $key = (string) ($d['cache_key'] ?? 'a cached value');
            $cites = array_values(array_unique([$read->getRef(), ...array_map(static fn (EvidenceItem $w): string => $w->getRef(), $writes), $failure->getRef()]));

            return [new FindingDraft('hypothesis',
                \sprintf('The request decided using %s that was %s old, computed before the data was changed%s; the later write then failed against the real state.', $key, EvidenceQuery::seconds((float) $d['cache_age_seconds'] * 1000), $detail),
                \sprintf('The cache hit happened %s before the failing write. A write to the same data occurred after the cached value was computed (inferred from timing: it is in another trace), so the cached value was stale when it was used.', EvidenceQuery::seconds($failedAt - $readAt)),
                'medium', $cites, self::SOURCE, weight: 100)];
        }

        return [];
    }
}
