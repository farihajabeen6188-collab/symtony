<?php

declare(strict_types=1);

namespace App\Application\Timeline;

use App\Domain\Event\Event;
use App\Domain\Event\EventType;

/**
 * Deterministic correlation rules. Proven links come from identifiers the application sent;
 * time proximity is only ever an inference and is labelled as such.
 *
 *   1. same trace_id            → proven (membership)
 *   2. parent_span_id → span_id → proven (parent/child, also across services)
 *   3. same request_id          → proven (membership)
 *   4. same application, close in time, no shared id → INFERRED ("time proximity")
 *   5. deploy of the same application shortly before → INFERRED ("preceded by deploy")
 */
final class Correlator
{
    /**
     * @param list<Event> $events events of one trace/request
     *
     * @return list<array{from: string, to: string, kind: string, proven: bool, reason: string}>
     */
    public function spanLinks(array $events): array
    {
        $bySpan = [];
        foreach ($events as $e) {
            if (null !== $e->getSpanId()) {
                $bySpan[$e->getSpanId()] = $e;
            }
        }
        $links = [];
        foreach ($events as $e) {
            $parent = null !== $e->getParentSpanId() ? ($bySpan[$e->getParentSpanId()] ?? null) : null;
            if (null === $parent || $parent === $e) {
                continue;
            }
            $cross = $parent->getService() !== $e->getService();
            $links[] = [
                'from' => $parent->getId()->toRfc4122(),
                'to' => $e->getId()->toRfc4122(),
                'kind' => $cross ? 'cross_service' : 'parent',
                'proven' => true,
                'reason' => $cross
                    ? \sprintf('%s called %s (parent span %s)', $parent->getService(), $e->getService(), $parent->getSpanId())
                    : 'Child of span '.$parent->getSpanId(),
            ];
        }

        return $links;
    }

    /**
     * Context from outside the trace. Never proven: the only evidence is timing.
     *
     * @param list<Event> $candidates same-application events from other traces in a window around the trace
     *
     * @return list<array{event: Event, reason: string}>
     */
    public function proximity(array $candidates, \DateTimeImmutable $traceStart, \DateTimeImmutable $traceEnd): array
    {
        $out = [];
        foreach ($candidates as $e) {
            if (!$this->isNotable($e)) {
                continue;
            }
            $delta = self::seconds($e->getOccurredAt(), $traceStart);
            $reason = EventType::Deploy === $e->getType()
                ? \sprintf('Preceded by deploy %s %s before the request (inferred from timing)', $e->getRelease() ?? '', self::human($delta))
                : \sprintf('Same application, %s %s the request, no shared trace or request id (inferred from timing)', self::human(abs($delta)), $delta <= 0 ? 'before' : 'after');
            if ($e->getOccurredAt() > $traceStart && $e->getOccurredAt() <= $traceEnd) {
                $reason = 'Same application, during the request, no shared trace or request id (inferred from timing)';
            }
            $out[] = ['event' => $e, 'reason' => $reason];
        }

        return $out;
    }

    /** Only events that plausibly matter as context: deploys, writes, warnings+ logs, errors. */
    public function isNotable(Event $e): bool
    {
        $op = strtoupper((string) ($e->getContext()['db']['operation'] ?? ''));

        return match ($e->getType()) {
            EventType::Deploy => true,
            EventType::DbQuery => \in_array($op, ['UPDATE', 'INSERT', 'DELETE'], true),
            EventType::Log => $e->getSeverity()->rank() >= 2,
            EventType::Exception => true,
            EventType::Queue => true,
            default => $e->getSeverity()->isFailure(),
        };
    }

    /**
     * Lane key: "<service>/<component>".
     *
     * @return array{key: string, service: string, component: string}
     */
    public static function lane(Event $e): array
    {
        $component = $e->getContext()['component'] ?? null;
        if (!\is_string($component) || '' === $component) {
            $component = match ($e->getType()) {
                EventType::HttpRequest, EventType::HttpResponse => 'http',
                EventType::Controller => 'controller',
                EventType::DbQuery, EventType::Transaction => 'database',
                EventType::Cache => 'cache',
                EventType::ExternalHttp => 'external',
                EventType::Queue => 'queue',
                EventType::Deploy => 'deploy',
                EventType::Exception => 'exception',
                EventType::Log => 'log',
                EventType::ServiceCall => 'service',
            };
        }
        if (str_starts_with($component, 'external:')) {
            $component = 'external';
        }

        return ['key' => $e->getService().'/'.$component, 'service' => $e->getService(), 'component' => $component];
    }

    public static function seconds(\DateTimeImmutable $a, \DateTimeImmutable $b): float
    {
        return (float) $a->format('U.u') - (float) $b->format('U.u');
    }

    private static function human(float $seconds): string
    {
        return match (true) {
            $seconds < 1 => \sprintf('%d ms', (int) round($seconds * 1000)),
            $seconds < 120 => \sprintf('%.1f s', $seconds),
            $seconds < 7200 => \sprintf('%d min', (int) round($seconds / 60)),
            default => \sprintf('%.1f h', $seconds / 3600),
        };
    }
}
