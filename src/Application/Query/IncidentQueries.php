<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Event\Event;
use App\Domain\Incident\Incident;
use App\Domain\Incident\IncidentNote;
use App\Domain\Shared\TimeRange;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read models for incident pages. Every query is workspace-scoped and paginated.
 */
final class IncidentQueries
{
    public const PAGE_SIZE = 25;
    private const SORTS = [
        'last_seen' => 'i.lastSeenAt',
        'first_seen' => 'i.firstSeenAt',
        'occurrences' => 'i.occurrences',
        'severity' => 'severity_rank',
        'number' => 'i.number',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param array{q?: string, status?: string, severity?: string, app?: string, environment?: ?string, sort?: string, dir?: string} $filters
     *
     * @return array{items: list<Incident>, total: int, page: int, pages: int}
     */
    public function list(Workspace $workspace, array $filters, int $page = 1): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('i', 'a', 'u')
            ->addSelect("CASE i.severity WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END AS HIDDEN severity_rank")
            ->from(Incident::class, 'i')
            ->join('i.application', 'a')
            ->leftJoin('i.assignee', 'u')
            ->where('i.workspace = :ws')
            ->setParameter('ws', $workspace);

        if ('' !== ($q = trim($filters['q'] ?? ''))) {
            if (1 === preg_match('/^(?:SYM-)?(\d+)$/i', $q, $m)) {
                $qb->andWhere('i.number = :num')->setParameter('num', (int) $m[1]);
            } else {
                $qb->andWhere('LOWER(i.title) LIKE :q OR LOWER(i.exceptionClass) LIKE :q OR LOWER(i.service) LIKE :q')->setParameter('q', '%'.mb_strtolower($q).'%');
            }
        }
        $status = $filters['status'] ?? 'open';
        if ('open' === $status) {
            $qb->andWhere("i.status <> 'resolved'");
        } elseif ('' !== $status && 'all' !== $status) {
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }
        if ('' !== ($filters['severity'] ?? '')) {
            $qb->andWhere('i.severity = :sev')->setParameter('sev', $filters['severity']);
        }
        if ('' !== ($filters['app'] ?? '')) {
            $qb->andWhere('a.slug = :app')->setParameter('app', $filters['app']);
        }
        if (null !== ($filters['environment'] ?? null)) {
            $qb->andWhere('a.environment = :env')->setParameter('env', $filters['environment']);
        }

        $sort = self::SORTS[$filters['sort'] ?? 'last_seen'] ?? self::SORTS['last_seen'];
        $dir = 'asc' === ($filters['dir'] ?? 'desc') ? 'ASC' : 'DESC';
        $qb->orderBy($sort, $dir)->addOrderBy('i.number', 'DESC');

        $page = max(1, $page);
        $qb->setFirstResult(($page - 1) * self::PAGE_SIZE)->setMaxResults(self::PAGE_SIZE);
        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);
        $total = \count($paginator);

        return [
            'items' => array_values(iterator_to_array($paginator)),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
        ];
    }

    public function byNumber(Workspace $workspace, int $number): Incident
    {
        $incident = $this->em->getRepository(Incident::class)->findOneBy(['workspace' => $workspace, 'number' => $number]);
        if (!$incident instanceof Incident) {
            throw new NotFoundHttpException(\sprintf('Incident SYM-%d not found.', $number));
        }

        return $incident;
    }

    /**
     * Occurrence events, newest first, cursor-paginated.
     *
     * @return array{items: list<Event>, next: ?string}
     */
    public function occurrences(Incident $incident, ?string $before = null, int $limit = 25): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(Event::class, 'e')
            ->join(\App\Domain\Incident\IncidentEvent::class, 'ie', 'WITH', 'ie.event = e')
            ->where('ie.incident = :incident')
            ->setParameter('incident', $incident)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit + 1);
        if (null !== $before && null !== ($cursor = EventCursor::decode($before))) {
            $qb->andWhere('(e.occurredAt < :t OR (e.occurredAt = :t AND e.id < :id))')
                ->setParameter('t', $cursor['t'], 'datetimetz_micro')
                ->setParameter('id', $cursor['id'], 'uuid');
        }
        /** @var list<Event> $rows */
        $rows = $qb->getQuery()->getResult();
        $next = null;
        if (\count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[\count($rows) - 1];
            $next = EventCursor::encode($last);
        }

        return ['items' => $rows, 'next' => $next];
    }

    public function latestOccurrence(Incident $incident): ?Event
    {
        if (null === $incident->getLastEventId()) {
            return null;
        }
        $event = $this->em->getRepository(Event::class)->find($incident->getLastEventId());

        return $event instanceof Event ? $event : null;
    }

    /** @return list<IncidentNote> */
    public function notes(Incident $incident): array
    {
        /** @var list<IncidentNote> */
        return $this->em->getRepository(IncidentNote::class)->findBy(['incident' => $incident], ['createdAt' => 'ASC']);
    }

    /**
     * Incidents related by exception class or by sharing a trace, with the reason.
     *
     * @return list<array{incident: Incident, reason: string}>
     */
    public function related(Incident $incident, int $limit = 10): array
    {
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(<<<'SQL'
            SELECT i.id, 'Same exception class in another service or message' AS reason
            FROM incident i
            WHERE i.workspace_id = :ws AND i.id <> :id AND i.exception_class IS NOT NULL AND i.exception_class = :class
            UNION
            SELECT DISTINCT ie2.incident_id, 'Occurred in the same request trace' AS reason
            FROM incident_event ie1
            JOIN event e1 ON e1.id = ie1.event_id
            JOIN event e2 ON e2.trace_id = e1.trace_id AND e2.workspace_id = e1.workspace_id
            JOIN incident_event ie2 ON ie2.event_id = e2.id
            WHERE ie1.incident_id = :id AND ie2.incident_id <> :id AND e1.trace_id IS NOT NULL
            LIMIT 50
            SQL, [
            'ws' => $incident->getWorkspace()->getId()->toRfc4122(),
            'id' => $incident->getId()->toRfc4122(),
            'class' => $incident->getExceptionClass(),
        ]);
        $reasons = [];
        foreach ($rows as $row) {
            $reasons[(string) $row['id']] ??= (string) $row['reason'];
        }
        if ([] === $reasons) {
            return [];
        }
        $incidents = $this->em->getRepository(Incident::class)->findBy(['id' => array_keys($reasons)], ['lastSeenAt' => 'DESC'], $limit);

        return array_map(static fn (Incident $i): array => ['incident' => $i, 'reason' => $reasons[$i->getId()->toRfc4122()]], $incidents);
    }

    /**
     * Occurrence counts per time bucket for a sparkline/chart.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function occurrenceSeries(Incident $incident, TimeRange $range): array
    {
        $rows = $this->em->getConnection()->fetchAllKeyValue(
            "SELECT extract(epoch FROM date_bin(:bucket::interval, occurred_at, :origin))::bigint AS b, count(*)
             FROM incident_event WHERE incident_id = :id AND occurred_at >= :since GROUP BY b",
            [
                'bucket' => $range->bucket(),
                'origin' => '2000-01-01 00:00:00+00',
                'id' => $incident->getId()->toRfc4122(),
                'since' => $range->since()->format('Y-m-d H:i:sP'),
            ],
        );

        return SeriesFiller::fill($rows, $range);
    }

    /** @return array<string, int> */
    public function statusCounts(Workspace $workspace): array
    {
        return array_map('intval', $this->em->getConnection()->fetchAllKeyValue(
            'SELECT status, count(*) FROM incident WHERE workspace_id = :ws GROUP BY status',
            ['ws' => $workspace->getId()->toRfc4122()],
        ));
    }

    /** @return list<string> distinct services touched by the incident's occurrence traces */
    public function affectedServices(Incident $incident): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT e2.service FROM incident_event ie
             JOIN event e ON e.id = ie.event_id
             JOIN event e2 ON e2.trace_id = e.trace_id AND e2.workspace_id = e.workspace_id
             WHERE ie.incident_id = :id AND e.trace_id IS NOT NULL
             UNION SELECT :own ORDER BY 1',
            ['id' => $incident->getId()->toRfc4122(), 'own' => $incident->getService()],
        ));
    }
}
