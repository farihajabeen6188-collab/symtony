<?php

declare(strict_types=1);

namespace App\Application\Investigation\Analyzer;

use App\Application\Investigation\EvidencePackage;
use App\Application\Investigation\FindingDraft;

/**
 * Frequency and recurrence facts from the incident itself.
 */
final class OccurrencePatternAnalyzer implements Analyzer
{
    public function name(): string
    {
        return 'occurrence_pattern';
    }

    public function analyze(EvidencePackage $p): array
    {
        $incident = $p->ofKind('incident')[0] ?? null;
        if (null === $incident) {
            return [];
        }
        $i = $p->incident;
        $span = (float) $i->getLastSeenAt()->format('U.u') - (float) $i->getFirstSeenAt()->format('U.u');
        $window = match (true) {
            $span < 120 => \sprintf('%d seconds', (int) round($span)),
            $span < 7200 => \sprintf('%d minutes', (int) round($span / 60)),
            $span < 172800 => \sprintf('%.1f hours', $span / 3600),
            default => \sprintf('%d days', (int) round($span / 86400)),
        };
        $out = [new FindingDraft('fact',
            \sprintf('The failure occurred %d times over %s.', $i->getOccurrences(), $window),
            'Counted from the events grouped into this incident.', 'high', [$incident->getRef()], 'heuristic:'.$this->name(), weight: 10)];
        if ($i->getReopenCount() > 0) {
            $out[] = new FindingDraft('fact', \sprintf('It recurred after being resolved (%d time%s).', $i->getReopenCount(), 1 === $i->getReopenCount() ? '' : 's'), 'The incident was reopened by new occurrences.', 'high', [$incident->getRef()], 'heuristic:'.$this->name(), weight: 15);
        }

        return $out;
    }
}
