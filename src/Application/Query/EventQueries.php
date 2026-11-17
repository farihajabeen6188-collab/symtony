<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Event\Event;
use App\Domain\Event\EventType;
use App\Domain\Event\Severity;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Event Explorer: keyset-paginated search. Never loads unbounded result sets.
 */
final class EventQueries
{
    public const PAGE_SIZE = 50;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param array{q?: string, severity?: string, service?: string, app?: string, type?: string, request_id?: string, trace_id?: string, environment?: ?string} $filters
     *
     * @return array{items: list<Event>, next: ?string}
     */
    public function search(Workspace $workspace, array $filters, \DateTimeImmutable $since, ?string $before = null): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e', 'a')
            ->from(Event::class, 'e')
            ->join('e.application', 'a')
            ->where('e.workspace = :ws')
            ->setParameter('ws', $workspace)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(self::PAGE_SIZE + 1);

        // Trace and request filters are exact lookups and ignore the time window.
        $exact = false;
        foreach (['trace_id' => 'e.traceId', 'request_id' => 'e.requestId'] as $key => $field) {
            if ('' !== ($v = trim($filters[$key] ?? ''))) {
                $qb->andWhere("$field = :$key")->setParameter($key, $v);
                $exact = true;
            }
        }
        if (!$exact) {
            $qb->andWhere('e.occurredAt >= :since')->setParameter('since', $since, 'datetimetz_micro');
        }
        if ('' !== ($q = trim($filters['q'] ?? ''))) {
            $qb->andWhere('LOWER(e.message) LIKE :q OR LOWER(e.name) LIKE :q OR LOWER(e.exceptionClass) LIKE :q')->setParameter('q', '%'.mb_strtolower($q).'%');
        }
        if (null !== ($sev = Severity::tryFrom($filters['severity'] ?? ''))) {
            $qb->andWhere('e.severity IN (:sevs)')->setParameter('sevs', array_map(static fn (Severity $s): string => $s->value, Severity::atLeast($sev)));
        }
        if (null !== ($type = EventType::tryFrom($filters['type'] ?? ''))) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type->value);
        }
        if ('' !== ($filters['service'] ?? '')) {
            $qb->andWhere('e.service = :service')->setParameter('service', $filters['service']);
        }
        if ('' !== ($filters['app'] ?? '')) {
            if (Uuid::isValid($filters['app'])) {
                $qb->andWhere('a.id = :app')->setParameter('app', Uuid::fromString($filters['app']), 'uuid');
            } else {
                $qb->andWhere('a.slug = :app')->setParameter('app', $filters['app']);
            }
        }
        if (null !== ($filters['environment'] ?? null)) {
            $qb->andWhere('e.environment = :env')->setParameter('env', $filters['environment']);
        }
        if (null !== $before && null !== ($cursor = EventCursor::decode($before))) {
            $qb->andWhere('(e.occurredAt < :t OR (e.occurredAt = :t AND e.id < :cid))')
                ->setParameter('t', $cursor['t'], 'datetimetz_micro')
                ->setParameter('cid', $cursor['id'], 'uuid');
        }

        /** @var list<Event> $rows */
        $rows = $qb->getQuery()->getResult();
        $next = null;
        if (\count($rows) > self::PAGE_SIZE) {
            array_pop($rows);
            $next = EventCursor::encode($rows[\count($rows) - 1]);
        }

        return ['items' => $rows, 'next' => $next];
    }

    public function get(Workspace $workspace, string $id): Event
    {
        $event = Uuid::isValid($id) ? $this->em->getRepository(Event::class)->findOneBy(['id' => Uuid::fromString($id), 'workspace' => $workspace]) : null;
        if (!$event instanceof Event) {
            throw new NotFoundHttpException('Event not found.');
        }

        return $event;
    }

    /**
     * Events sharing the trace (or, without a trace, the request), in time order. Capped.
     *
     * @return list<Event>
     */
    public function related(Event $event, int $limit = 200): array
    {
        $field = null !== $event->getTraceId() ? 'traceId' : (null !== $event->getRequestId() ? 'requestId' : null);
        if (null === $field) {
            return [];
        }

        /** @var list<Event> */
        return $this->em->createQueryBuilder()
            ->select('e')
            ->from(Event::class, 'e')
            ->where('e.workspace = :ws')
            ->andWhere("e.$field = :v")
            ->setParameter('ws', $event->getWorkspace())
            ->setParameter('v', 'traceId' === $field ? $event->getTraceId() : $event->getRequestId())
            ->orderBy('e.occurredAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<string> */
    public function services(Workspace $workspace): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT service FROM event WHERE workspace_id = :ws AND occurred_at >= now() - interval \'30 days\' ORDER BY 1',
            ['ws' => $workspace->getId()->toRfc4122()],
        ));
    }

    /** Incident linked to this event, if it is an occurrence. */
    public function incidentNumber(Event $event): ?int
    {
        $n = $this->em->getConnection()->fetchOne(
            'SELECT i.number FROM incident_event ie JOIN incident i ON i.id = ie.incident_id WHERE ie.event_id = :id',
            ['id' => $event->getId()->toRfc4122()],
        );

        return false === $n ? null : (int) $n;
    }
}
