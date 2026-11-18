<?php

declare(strict_types=1);

namespace App\Application\Retention;

use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes events older than each workspace's retention period. Events that belong to an incident or
 * are cited as investigation evidence are kept, so incidents and reports never lose their proof.
 * Runs daily from the scheduler and as `symtony:retention:purge`.
 */
#[AsMessageHandler(handles: PurgeExpiredEvents::class)]
final class RetentionPurge
{
    private const BATCH = 5000;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array<string, int> workspace slug → events deleted */
    public function __invoke(?PurgeExpiredEvents $message = null, bool $dryRun = false): array
    {
        $conn = $this->em->getConnection();
        $out = [];
        foreach ($this->em->getRepository(Workspace::class)->findAll() as $ws) {
            $params = ['ws' => $ws->getId()->toRfc4122(), 'before' => (new \DateTimeImmutable(\sprintf('-%d days', $ws->getRetentionDays())))->format('Y-m-d H:i:sP')];
            $where = 'e.workspace_id = :ws AND e.occurred_at < :before
                AND NOT EXISTS (SELECT 1 FROM incident_event ie WHERE ie.event_id = e.id)
                AND NOT EXISTS (SELECT 1 FROM evidence_item ev WHERE ev.event_id = e.id)';
            if ($dryRun) {
                $out[$ws->getSlug()] = (int) $conn->fetchOne("SELECT count(*) FROM event e WHERE $where", $params);
                continue;
            }
            $deleted = 0;
            do {
                $n = $conn->executeStatement("DELETE FROM event WHERE id IN (SELECT e.id FROM event e WHERE $where LIMIT ".self::BATCH.')', $params);
                $deleted += $n;
            } while ($n === self::BATCH);
            $out[$ws->getSlug()] = $deleted;
        }

        return $out;
    }
}
