<?php

declare(strict_types=1);

namespace App\Application\Investigation\Analyzer;

use App\Application\Investigation\EvidencePackage;
use App\Domain\Investigation\EvidenceItem;

/**
 * Small helpers shared by analyzers.
 */
final class EvidenceQuery
{
    public static function firstFailure(EvidencePackage $p): ?EvidenceItem
    {
        return $p->where(static fn (array $d): bool => true === ($d['first_failure'] ?? false))[0] ?? null;
    }

    /** @return list<EvidenceItem> */
    public static function inTrace(EvidencePackage $p, string $type): array
    {
        return $p->where(static fn (array $d, EvidenceItem $i): bool => 'event' === $i->getKind() && $type === ($d['type'] ?? null));
    }

    public static function offset(EvidenceItem $i): float
    {
        return (float) ($i->getData()['offset_ms'] ?? 0.0);
    }

    public static function end(EvidenceItem $i): float
    {
        return self::offset($i) + (float) ($i->getData()['duration_ms'] ?? 0.0);
    }

    public static function exceptionEvidence(EvidencePackage $p): ?EvidenceItem
    {
        return $p->where(static fn (array $d, EvidenceItem $i): bool => 'event' === $i->getKind() && 'exception' === ($d['type'] ?? null))[0] ?? null;
    }

    public static function seconds(float $ms): string
    {
        return $ms >= 1000 ? number_format($ms / 1000, 1).' s' : number_format($ms, 0).' ms';
    }
}
