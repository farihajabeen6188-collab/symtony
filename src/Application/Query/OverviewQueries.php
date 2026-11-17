<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Audit\AuditLog;
use App\Domain\Incident\Incident;
use App\Domain\Shared\TimeRange;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Overview dashboard. Every number here is a query result; nothing is estimated or invented.
 */
final class OverviewQueries
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array{applications: int, connected: int, open_incidents: int, critical_open: int, events: int, errors: int} */
    public function metrics(Workspace $workspace, TimeRange $range, ?string $environment): array
    {
        $conn = $this->em->getConnection();
        $ws = $workspace->getId()->toRfc4122();
        $envSql = null !== $environment ? ' AND a.environment = :env' : '';
        $params = array_filter(['ws' => $ws, 'env' => $environment, 'since' => $range->since()->format('Y-m-d H:i:sP'), 'silent' => (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:sP')], static fn ($v): bool => null !== $v);

        $apps = $conn->fetchAssociative("SELECT count(*) AS total, count(*) FILTER (WHERE a.last_event_at >= :silent) AS connected FROM application a WHERE a.workspace_id = :ws$envSql", array_intersect_key($params, array_flip(['ws', 'env', 'silent'])));
        $incidents = $conn->fetchAssociative("SELECT count(*) FILTER (WHERE i.status <> 'resolved') AS open, count(*) FILTER (WHERE i.status <> 'resolved' AND i.severity = 'critical') AS critical FROM incident i JOIN application a ON a.id = i.application_id WHERE i.workspace_id = :ws$envSql", array_intersect_key($params, array_flip(['ws', 'env'])));
        $events = $conn->fetchAssociative("SELECT count(*) AS events, count(*) FILTER (WHERE e.severity IN ('error','critical')) AS errors FROM event e WHERE e.workspace_id = :ws AND e.occurred_at >= :since".(null !== $environment ? ' AND e.environment = :env' : ''), array_intersect_key($params, array_flip(['ws', 'env', 'since'])));

        return [
            'applications' => (int) ($apps['total'] ?? 0),
            'connected' => (int) ($apps['connected'] ?? 0),
            'open_incidents' => (int) ($incidents['open'] ?? 0),
            'critical_open' => (int) ($incidents['critical'] ?? 0),
            'events' => (int) ($events['events'] ?? 0),
            'errors' => (int) ($events['errors'] ?? 0),
        ];
    }

    /** @return list<array{0: int, 1: int}> incident occurrences per bucket */
    public function occurrenceSeries(Workspace $workspace, TimeRange $range, ?string $environment): array
    {
        $rows = $this->em->getConnection()->fetchAllKeyValue(
            'SELECT extract(epoch FROM date_bin(:bucket::interval, ie.occurred_at, :origin))::bigint AS b, count(*)
             FROM incident_event ie JOIN incident i ON i.id = ie.incident_id JOIN application a ON a.id = i.application_id
             WHERE i.workspace_id = :ws AND ie.occurred_at >= :since'.(null !== $environment ? ' AND a.environment = :env' : '').'
             GROUP BY b',
            array_filter([
                'bucket' => $range->bucket(),
                'origin' => '2000-01-01 00:00:00+00',
                'ws' => $workspace->getId()->toRfc4122(),
                'since' => $range->since()->format('Y-m-d H:i:sP'),
                'env' => $environment,
            ], static fn ($v): bool => null !== $v),
        );

        return SeriesFiller::fill($rows, $range);
    }

    /**
     * @return list<array{service: string, events: int, errors: int, error_rate: float, last_event: \DateTimeImmutable, open_incidents: int}>
     */
    public function serviceHealth(Workspace $workspace, TimeRange $range, ?string $environment): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT e.service, count(*) AS events, count(*) FILTER (WHERE e.severity IN ('error','critical')) AS errors, max(e.occurred_at) AS last_event,
                    (SELECT count(*) FROM incident i WHERE i.workspace_id = :ws AND i.service = e.service AND i.status <> 'resolved') AS open_incidents
             FROM event e WHERE e.workspace_id = :ws AND e.occurred_at >= :since".(null !== $environment ? ' AND e.environment = :env' : '').'
             GROUP BY e.service ORDER BY errors DESC, events DESC LIMIT 12',
            array_filter(['ws' => $workspace->getId()->toRfc4122(), 'since' => $range->since()->format('Y-m-d H:i:sP'), 'env' => $environment], static fn ($v): bool => null !== $v),
        );

        return array_map(static fn (array $r): array => [
            'service' => (string) $r['service'],
            'events' => (int) $r['events'],
            'errors' => (int) $r['errors'],
            'error_rate' => (int) $r['events'] > 0 ? round((int) $r['errors'] / (int) $r['events'] * 100, 1) : 0.0,
            'last_event' => new \DateTimeImmutable((string) $r['last_event']),
            'open_incidents' => (int) $r['open_incidents'],
        ], $rows);
    }

    /** @return list<Incident> */
    public function recentIncidents(Workspace $workspace, ?string $environment, int $limit = 8): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('i', 'a')
            ->from(Incident::class, 'i')
            ->join('i.application', 'a')
            ->where('i.workspace = :ws')
            ->setParameter('ws', $workspace)
            ->orderBy("CASE WHEN i.status = 'resolved' THEN 1 ELSE 0 END", 'ASC')
            ->addOrderBy('i.lastSeenAt', 'DESC')
            ->setMaxResults($limit);
        if (null !== $environment) {
            $qb->andWhere('a.environment = :env')->setParameter('env', $environment);
        }

        /** @var list<Incident> */
        return $qb->getQuery()->getResult();
    }

    /** @return list<AuditLog> */
    public function activity(Workspace $workspace, int $limit = 12): array
    {
        /** @var list<AuditLog> */
        return $this->em->createQueryBuilder()
            ->select('l')
            ->from(AuditLog::class, 'l')
            ->where('l.workspace = :ws')
            ->andWhere("l.action NOT LIKE 'credential.%'")
            ->setParameter('ws', $workspace)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
