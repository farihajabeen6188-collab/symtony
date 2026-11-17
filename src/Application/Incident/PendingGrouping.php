<?php

declare(strict_types=1);

namespace App\Application\Incident;

use Doctrine\DBAL\Connection;

/**
 * Finds candidate events without an incident link and groups them in chronological chunks.
 */
final class PendingGrouping
{
    /** @return array{grouped: int, created: int, reopened: int} */
    public static function run(Connection $db, IncidentGrouper $grouper, ?string $applicationId = null): array
    {
        $total = ['grouped' => 0, 'created' => 0, 'reopened' => 0];
        $after = '1970-01-01 00:00:00+00';
        do {
            $rows = $db->fetchAllAssociative(
                "SELECT e.id, e.occurred_at FROM event e
                 LEFT JOIN incident_event ie ON ie.event_id = e.id
                 WHERE ie.event_id IS NULL
                   AND e.type IN ('exception', 'log') AND e.severity IN ('warning', 'error', 'critical')
                   AND e.occurred_at > :after".(null !== $applicationId ? ' AND e.application_id = :app' : '').'
                 ORDER BY e.occurred_at ASC LIMIT 500',
                array_filter(['after' => $after, 'app' => $applicationId]),
            );
            if ([] === $rows) {
                break;
            }
            $result = $grouper->group(array_map(static fn (array $r): string => (string) $r['id'], $rows));
            foreach ($total as $k => $v) {
                $total[$k] = $v + $result[$k];
            }
            $after = (string) $rows[\count($rows) - 1]['occurred_at'];
        } while (500 === \count($rows));

        return $total;
    }
}
