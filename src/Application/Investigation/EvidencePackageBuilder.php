<?php

declare(strict_types=1);

namespace App\Application\Investigation;

use App\Application\Timeline\Timeline;
use App\Application\Timeline\TimelineItem;
use App\Domain\Incident\Incident;
use App\Domain\Investigation\EvidenceItem;
use App\Domain\Investigation\Investigation;
use App\Infrastructure\Ingestion\Redactor;

/**
 * Turns an incident and its reconstructed timeline into numbered, redacted, size-bounded evidence.
 */
final class EvidencePackageBuilder
{
    public const MAX_ITEMS = 120;
    private const MAX_EXCERPT = 420;
    private const MAX_CONTEXT = 12;

    public function __construct(private readonly Redactor $redactor)
    {
    }

    public function build(Investigation $investigation, Incident $incident, ?Timeline $timeline): EvidencePackage
    {
        $package = new EvidencePackage($incident, $timeline);

        $package->add(new EvidenceItem($investigation, $package->nextRef(), 'incident', null,
            $incident->getReference().' summary',
            $this->scrub(\sprintf('Incident %s "%s": %d occurrences between %s and %s UTC in service %s of %s (%s). Status %s%s.',
                $incident->getReference(), $incident->getTitle(), $incident->getOccurrences(),
                $incident->getFirstSeenAt()->format('Y-m-d H:i:s'), $incident->getLastSeenAt()->format('Y-m-d H:i:s'),
                $incident->getService(), $incident->getApplication()->getName(), $incident->getApplication()->getEnvironment()->value,
                $incident->getStatus()->value, $incident->getReopenCount() > 0 ? ', reopened '.$incident->getReopenCount().' times' : '')),
            [
                'type' => 'incident',
                'occurrences' => $incident->getOccurrences(),
                'first_seen' => $incident->getFirstSeenAt()->format(\DATE_ATOM),
                'last_seen' => $incident->getLastSeenAt()->format(\DATE_ATOM),
                'exception' => $incident->getExceptionClass(),
            ],
        ));

        if (null === $timeline || $timeline->isEmpty()) {
            return $package;
        }

        foreach ($this->select($timeline) as $item) {
            $package->add($this->eventEvidence($investigation, $package->nextRef(), $item, $timeline));
        }

        return $package;
    }

    /**
     * Keep everything for normal traces; for huge ones prefer failures, proven members, context and span roots.
     *
     * @return list<TimelineItem>
     */
    private function select(Timeline $timeline): array
    {
        // Inferred context is capped: the latest deploy plus the nearest notable events before the request.
        $proven = array_values(array_filter($timeline->items, static fn (TimelineItem $i): bool => $i->isProven()));
        $start = [] !== $proven ? $proven[0]->offsetMs : 0.0;
        $context = array_values(array_filter($timeline->items, static fn (TimelineItem $i): bool => !$i->isProven()));
        $deploys = array_values(array_filter($context, static fn (TimelineItem $i): bool => 'deploy' === $i->event->getType()->value));
        $others = array_values(array_filter($context, static fn (TimelineItem $i): bool => 'deploy' !== $i->event->getType()->value));
        usort($others, static fn (TimelineItem $a, TimelineItem $b): int => abs($start - $a->offsetMs) <=> abs($start - $b->offsetMs));
        $keep = [...$proven, ...\array_slice($deploys, -1), ...\array_slice($others, 0, self::MAX_CONTEXT)];
        usort($keep, static fn (TimelineItem $a, TimelineItem $b): int => $a->offsetMs <=> $b->offsetMs);

        $items = $keep;
        if (\count($items) <= self::MAX_ITEMS) {
            return $items;
        }
        usort($items, static fn (TimelineItem $a, TimelineItem $b): int => [(int) !$a->error, (int) $a->isProven()] <=> [(int) !$b->error, (int) $b->isProven()]);
        $kept = \array_slice($items, 0, self::MAX_ITEMS);
        usort($kept, static fn (TimelineItem $a, TimelineItem $b): int => $a->offsetMs <=> $b->offsetMs);

        return $kept;
    }

    private function eventEvidence(Investigation $investigation, string $ref, TimelineItem $item, Timeline $timeline): EvidenceItem
    {
        $e = $item->event;
        $c = $e->getContext();
        $data = array_filter([
            'type' => $e->getType()->value,
            'service' => $e->getService(),
            'lane' => $item->lane,
            'name' => $e->getName() ?? $e->getMessage(),
            'severity' => $e->getSeverity()->value,
            'error' => $item->error,
            'offset_ms' => round($item->offsetMs, 3),
            'duration_ms' => $item->durationMs,
            'occurred_at' => $e->getOccurredAt()->format('Y-m-d\TH:i:s.u\Z'),
            'trace_member' => $item->isProven(),
            'inferred_reason' => $item->reason,
            'span_id' => $e->getSpanId(),
            'parent_span_id' => $e->getParentSpanId(),
            'release' => $e->getRelease(),
            'first_failure' => $timeline->firstFailureId === $item->id,
            'exception_class' => $e->getExceptionClass(),
            'exception_message' => $e->getExceptionMessage(),
            'http_status' => $c['http']['status'] ?? null,
            'db_operation' => $c['db']['operation'] ?? null,
            'db_table' => $c['db']['table'] ?? null,
            'db_statement' => $c['db']['statement'] ?? null,
            'db_error' => $c['db']['error'] ?? null,
            'cache_hit' => $c['cache']['hit'] ?? null,
            'cache_key' => $c['cache']['key'] ?? null,
            'cache_age_seconds' => $c['cache']['age_seconds'] ?? null,
            'external_host' => $c['external']['host'] ?? null,
            'external_status' => $c['external']['status'] ?? null,
            'payment_authorization' => $c['payment']['authorization_id'] ?? null,
            'inventory' => $c['inventory'] ?? null,
            'component' => $c['component'] ?? null,
        ], static fn ($v): bool => null !== $v && '' !== $v);

        $parts = [
            \sprintf('[+%s ms]', number_format($item->offsetMs, 1, '.', '')),
            $item->lane,
            $e->getType()->value,
            $e->getExceptionClass() ? $e->getExceptionClass().': '.$e->getExceptionMessage() : ($e->getName() ?? $e->getMessage()),
        ];
        if (null !== $item->durationMs) {
            $parts[] = number_format($item->durationMs, 1, '.', '').' ms';
        }
        if ($item->error) {
            $parts[] = 'FAILED';
        }
        foreach (['http_status' => 'status', 'db_statement' => 'sql', 'db_error' => 'db error', 'cache_hit' => 'cache hit', 'cache_age_seconds' => 'cache age s', 'external_status' => 'upstream status', 'payment_authorization' => 'authorization', 'release' => 'release'] as $k => $label) {
            if (isset($data[$k])) {
                $parts[] = $label.'='.(\is_bool($data[$k]) ? ($data[$k] ? 'yes' : 'no') : (\is_scalar($data[$k]) ? (string) $data[$k] : json_encode($data[$k])));
            }
        }
        if (!$item->isProven()) {
            $parts[] = 'NOT IN TRACE: '.$item->reason;
        }

        $label = ($e->getExceptionClass() ? substr((string) strrchr('\\'.$e->getExceptionClass(), '\\'), 1).': ' : '').($e->getName() ?? $e->getMessage());

        return new EvidenceItem($investigation, $ref, $item->isProven() ? 'event' : 'context', $e->getId(), $this->scrub($label), mb_substr($this->scrub(implode(' | ', $parts)), 0, self::MAX_EXCERPT), $data);
    }

    private function scrub(string $text): string
    {
        return $this->redactor->redactString($text)['value'];
    }
}
