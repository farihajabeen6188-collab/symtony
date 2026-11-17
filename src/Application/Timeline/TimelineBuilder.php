<?php

declare(strict_types=1);

namespace App\Application\Timeline;

use App\Domain\Event\Event;
use App\Domain\Event\EventType;
use App\Domain\Incident\Incident;
use App\Domain\Incident\IncidentEvent;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Reconstructs what happened around a failure from stored evidence only.
 */
final class TimelineBuilder
{
    public const MAX_EVENTS = 2000;
    /** Context window before the request for non-deploy events (seconds). */
    private const CONTEXT_BEFORE = 60;
    /** Deploys are looked for further back (seconds). */
    private const DEPLOY_BEFORE = 1800;
    private const CONTEXT_AFTER = 2;
    private const MAX_CONTEXT = 50;

    /** Point events are drawn on the lane of the span they happened in. */
    private const POINT_TYPES = [EventType::HttpResponse, EventType::Transaction, EventType::Log];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Correlator $correlator,
        private readonly int $maxEvents = self::MAX_EVENTS,
    ) {
    }

    public function forIncident(Incident $incident, ?string $occurrenceId = null): ?Timeline
    {
        $event = null;
        if (null !== $occurrenceId && Uuid::isValid($occurrenceId)) {
            $event = $this->em->createQueryBuilder()->select('e')->from(Event::class, 'e')
                ->join(IncidentEvent::class, 'ie', 'WITH', 'ie.event = e')
                ->where('ie.incident = :i')->andWhere('e.id = :id')
                ->setParameter('i', $incident)->setParameter('id', Uuid::fromString($occurrenceId), 'uuid')
                ->getQuery()->getOneOrNullResult();
        }
        // Default: the latest occurrence that carries a trace (otherwise the latest one).
        $event ??= $this->em->createQueryBuilder()->select('e')->from(Event::class, 'e')
            ->join(IncidentEvent::class, 'ie', 'WITH', 'ie.event = e')
            ->where('ie.incident = :i')
            ->setParameter('i', $incident)
            ->orderBy('CASE WHEN e.traceId IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('e.occurredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $event instanceof Event ? $this->forEvent($event) : null;
    }

    public function forEvent(Event $event): Timeline
    {
        if (null !== $event->getTraceId()) {
            return $this->build($event->getWorkspace(), 'traceId', $event->getTraceId(), $event);
        }
        if (null !== $event->getRequestId()) {
            return $this->build($event->getWorkspace(), 'requestId', $event->getRequestId(), $event);
        }

        return $this->assemble($event->getWorkspace(), null, [$event], $event, false, 1);
    }

    public function forTrace(Workspace $workspace, string $traceId): ?Timeline
    {
        $timeline = $this->build($workspace, 'traceId', $traceId, null);

        return $timeline->isEmpty() ? null : $timeline;
    }

    private function build(Workspace $workspace, string $field, string $value, ?Event $focus): Timeline
    {
        $total = (int) $this->em->createQueryBuilder()->select('COUNT(e.id)')->from(Event::class, 'e')
            ->where('e.workspace = :ws')->andWhere("e.$field = :v")
            ->setParameter('ws', $workspace)->setParameter('v', $value)
            ->getQuery()->getSingleScalarResult();

        /** @var list<Event> $events */
        $events = $this->em->createQueryBuilder()->select('e', 'a')->from(Event::class, 'e')->join('e.application', 'a')
            ->where('e.workspace = :ws')->andWhere("e.$field = :v")
            ->setParameter('ws', $workspace)->setParameter('v', $value)
            ->orderBy('e.occurredAt', 'ASC')->addOrderBy('e.id', 'ASC')
            ->setMaxResults($this->maxEvents)
            ->getQuery()->getResult();

        if (null !== $focus && !\in_array($focus, $events, true) && [] === $events) {
            $events = [$focus];
        }

        return $this->assemble($workspace, 'traceId' === $field ? $value : null, $events, $focus, $total > $this->maxEvents, $total);
    }

    /** @param list<Event> $events */
    private function assemble(Workspace $workspace, ?string $traceId, array $events, ?Event $focus, bool $truncated, int $total): Timeline
    {
        if ([] === $events) {
            return new Timeline($traceId, new \DateTimeImmutable(), 0.0, [], [], [], null, false, 0);
        }

        $start = $events[0]->getOccurredAt();
        $end = $start;
        foreach ($events as $e) {
            if ($e->getEndsAt() > $end) {
                $end = $e->getEndsAt();
            }
        }

        // Inferred context: same application(s), other traces, close in time.
        $context = $this->correlator->proximity($this->contextCandidates($workspace, $events, $start, $end), $start, $end);

        $t0 = $start;
        foreach ($context as $c) {
            if ($c['event']->getOccurredAt() < $t0) {
                $t0 = $c['event']->getOccurredAt();
            }
        }

        $bySpan = [];
        foreach ($events as $e) {
            if (null !== $e->getSpanId()) {
                $bySpan[$e->getSpanId()] = $e;
            }
        }

        $lanes = [];
        $items = [];
        $add = function (Event $e, string $membership, ?string $reason) use (&$lanes, &$items, $bySpan, $t0): void {
            $laneSource = $e;
            if (\in_array($e->getType(), self::POINT_TYPES, true) && null !== $e->getParentSpanId() && isset($bySpan[$e->getParentSpanId()])) {
                $laneSource = $bySpan[$e->getParentSpanId()];
            }
            $lane = Correlator::lane($laneSource);
            $lanes[$lane['key']] ??= $lane + ['label' => $lane['component'], 'first' => Correlator::seconds($e->getOccurredAt(), $t0)];
            $items[] = new TimelineItem(
                $e->getId()->toRfc4122(),
                $e,
                $lane['key'],
                Correlator::seconds($e->getOccurredAt(), $t0) * 1000,
                $e->getDurationMs(),
                $e->getSeverity()->isFailure() || 'error' === ($e->getContext()['status'] ?? null),
                $membership,
                $reason,
            );
        };

        foreach ($events as $e) {
            $add($e, null !== $traceId ? 'trace' : 'request', null);
        }
        foreach (\array_slice($context, 0, self::MAX_CONTEXT) as $c) {
            $add($c['event'], 'context', $c['reason']);
        }
        usort($items, static fn (TimelineItem $a, TimelineItem $b): int => $a->offsetMs <=> $b->offsetMs);

        // Lanes in order of first appearance; context-only lanes keep their chronological place.
        uasort($lanes, static fn (array $a, array $b): int => $a['first'] <=> $b['first']);
        $lanes = array_values(array_map(static fn (array $l): array => ['key' => $l['key'], 'service' => $l['service'], 'component' => $l['component'], 'label' => $l['label']], $lanes));

        $links = $this->correlator->spanLinks($events);
        $rootId = $events[0]->getId()->toRfc4122();
        foreach (\array_slice($context, 0, self::MAX_CONTEXT) as $c) {
            $links[] = ['from' => $c['event']->getId()->toRfc4122(), 'to' => $rootId, 'kind' => 'inferred', 'proven' => false, 'reason' => $c['reason']];
        }

        $endMs = 0.0;
        foreach ($items as $i) {
            $endMs = max($endMs, $i->offsetMs + ($i->durationMs ?? 0));
        }

        return new Timeline($traceId, $t0, $endMs, $lanes, $items, $links, $this->firstFailure($items), $truncated, $total);
    }

    /**
     * The earliest failing leaf: an error with no failing children, by end time. Parents that failed
     * because a child failed are symptoms, not the first failure.
     *
     * @param list<TimelineItem> $items
     */
    private function firstFailure(array $items): ?string
    {
        $errorParents = [];
        foreach ($items as $i) {
            if ($i->error && $i->isProven() && null !== $i->event->getParentSpanId()) {
                $errorParents[$i->event->getParentSpanId()] = true;
            }
        }
        // A failed request root is a symptom whenever something inside it failed; use it only as a last resort.
        $hasInnerFailure = false;
        foreach ($items as $i) {
            if ($i->error && $i->isProven() && !\in_array($i->event->getType(), [EventType::HttpRequest, EventType::HttpResponse], true)) {
                $hasInnerFailure = true;
                break;
            }
        }
        $best = null;
        $bestEnd = \PHP_FLOAT_MAX;
        foreach ($items as $i) {
            if (!$i->error || !$i->isProven() || EventType::HttpResponse === $i->event->getType()) {
                continue;
            }
            if ($hasInnerFailure && EventType::HttpRequest === $i->event->getType()) {
                continue;
            }
            if (null !== $i->event->getSpanId() && isset($errorParents[$i->event->getSpanId()])) {
                continue;
            }
            $endAt = $i->offsetMs + ($i->durationMs ?? 0);
            if ($endAt < $bestEnd) {
                $bestEnd = $endAt;
                $best = $i->id;
            }
        }

        return $best;
    }

    /**
     * @param list<Event> $events
     *
     * @return list<Event>
     */
    private function contextCandidates(Workspace $workspace, array $events, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $apps = [];
        $traces = [];
        foreach ($events as $e) {
            $apps[$e->getApplication()->getId()->toRfc4122()] = $e->getApplication();
            if (null !== $e->getTraceId()) {
                $traces[$e->getTraceId()] = true;
            }
        }

        $qb = $this->em->createQueryBuilder()->select('e')->from(Event::class, 'e')
            ->where('e.workspace = :ws')->andWhere('e.application IN (:apps)')
            ->andWhere('e.occurredAt BETWEEN :from AND :to')
            ->andWhere("(e.type = 'deploy' OR e.occurredAt >= :near)")
            ->setParameter('ws', $workspace)
            ->setParameter('apps', array_values($apps))
            ->setParameter('from', $start->modify('-'.self::DEPLOY_BEFORE.' seconds'), 'datetimetz_micro')
            ->setParameter('near', $start->modify('-'.self::CONTEXT_BEFORE.' seconds'), 'datetimetz_micro')
            ->setParameter('to', $end->modify('+'.self::CONTEXT_AFTER.' seconds'), 'datetimetz_micro')
            ->orderBy('e.occurredAt', 'ASC')
            ->setMaxResults(500);
        if ([] !== $traces) {
            $qb->andWhere('(e.traceId IS NULL OR e.traceId NOT IN (:traces))')->setParameter('traces', array_keys($traces));
        }

        /** @var list<Event> $rows */
        $rows = $qb->getQuery()->getResult();

        // Keep only the latest deploy: earlier ones are superseded.
        $latestDeploy = null;
        foreach ($rows as $e) {
            if (EventType::Deploy === $e->getType()) {
                $latestDeploy = $e;
            }
        }

        return array_values(array_filter($rows, static fn (Event $e): bool => EventType::Deploy !== $e->getType() || $e === $latestDeploy));
    }
}
