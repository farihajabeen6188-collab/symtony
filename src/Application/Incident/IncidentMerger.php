<?php

declare(strict_types=1);

namespace App\Application\Incident;

use App\Domain\Event\Event;
use App\Domain\Incident\Incident;
use App\Infrastructure\Persistence\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * When the fingerprint algorithm improves, incidents that are now the same failure are merged:
 * the oldest incident keeps its number, notes and status; occurrences and notes of the others move to it.
 */
final class IncidentMerger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Fingerprinter $fingerprinter,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return list<string> human-readable merge log */
    public function refingerprint(): array
    {
        $log = [];
        $conn = $this->em->getConnection();
        /** @var list<Incident> $incidents */
        $incidents = $this->em->getRepository(Incident::class)->findBy([], ['number' => 'ASC']);

        $groups = [];
        foreach ($incidents as $incident) {
            $event = null !== $incident->getLastEventId() ? $this->em->find(Event::class, $incident->getLastEventId()) : null;
            if (!$event instanceof Event) {
                continue;
            }
            $fp = $this->fingerprinter->fingerprint($event);
            $groups[$incident->getApplication()->getId()->toRfc4122().'|'.$fp][] = $incident;
        }

        foreach ($groups as $key => $group) {
            [, $fp] = explode('|', $key);
            $keep = $group[0];
            $this->em->wrapInTransaction(function () use ($conn, $group, $keep, $fp, &$log): void {
                foreach (\array_slice($group, 1) as $dup) {
                    $conn->executeStatement('UPDATE incident_event SET incident_id = :keep WHERE incident_id = :dup AND event_id NOT IN (SELECT event_id FROM incident_event WHERE incident_id = :keep)', ['keep' => $keep->getId()->toRfc4122(), 'dup' => $dup->getId()->toRfc4122()]);
                    $conn->executeStatement('UPDATE incident_note SET incident_id = :keep WHERE incident_id = :dup', ['keep' => $keep->getId()->toRfc4122(), 'dup' => $dup->getId()->toRfc4122()]);
                    $this->audit->write($keep->getWorkspace(), 'system', 'fingerprint-migration', 'incident.merged', $keep, ['reference' => $keep->getReference(), 'merged' => $dup->getReference()]);
                    $log[] = \sprintf('%s merged into %s', $dup->getReference(), $keep->getReference());
                    $this->em->remove($dup);
                }
                $this->em->flush();

                // Recompute counters from the links and adopt the new fingerprint.
                $stats = $conn->fetchAssociative('SELECT count(*) AS n, min(occurred_at) AS first, max(occurred_at) AS last FROM incident_event WHERE incident_id = :id', ['id' => $keep->getId()->toRfc4122()]);
                $lastEvent = $conn->fetchOne('SELECT event_id FROM incident_event WHERE incident_id = :id ORDER BY occurred_at DESC LIMIT 1', ['id' => $keep->getId()->toRfc4122()]);
                $conn->executeStatement(
                    'UPDATE incident SET fingerprint = :fp, occurrences = :n, first_seen_at = :first, last_seen_at = :last, last_event_id = :ev WHERE id = :id',
                    ['fp' => $fp, 'n' => (int) $stats['n'], 'first' => $stats['first'], 'last' => $stats['last'], 'ev' => $lastEvent, 'id' => $keep->getId()->toRfc4122()],
                );
                $conn->executeStatement('UPDATE event SET fingerprint = :fp WHERE id IN (SELECT event_id FROM incident_event WHERE incident_id = :id)', ['fp' => $fp, 'id' => $keep->getId()->toRfc4122()]);
            });
        }
        $this->em->clear();

        return $log;
    }
}
