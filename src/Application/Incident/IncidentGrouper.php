<?php

declare(strict_types=1);

namespace App\Application\Incident;

use App\Domain\Event\Event;
use App\Domain\Incident\Incident;
use App\Domain\Incident\IncidentSeverity;
use App\Domain\Incident\IncidentStatus;
use App\Infrastructure\Persistence\AuditLogger;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Groups failure events into incidents. Safe under concurrent workers and message redelivery:
 *  - a transaction-scoped advisory lock per fingerprint serializes creation,
 *  - (application, fingerprint) is unique, so there is never more than one incident per failure,
 *  - incident_event inserts are ON CONFLICT DO NOTHING and only newly linked events are counted.
 */
final class IncidentGrouper
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Fingerprinter $fingerprinter,
        private readonly AuditLogger $audit,
        #[Target('incident')] private readonly WorkflowInterface $workflow,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $eventIds
     *
     * @return array{grouped: int, created: int, reopened: int}
     */
    public function group(array $eventIds): array
    {
        $stats = ['grouped' => 0, 'created' => 0, 'reopened' => 0];
        if ([] === $eventIds) {
            return $stats;
        }

        /** @var list<Event> $events */
        $events = $this->em->createQuery('SELECT e, a FROM '.Event::class.' e JOIN e.application a WHERE e.id IN (:ids) ORDER BY e.occurredAt ASC')
            ->setParameter('ids', array_map(static fn (string $id): string => Uuid::fromString($id)->toRfc4122(), $eventIds))
            ->getResult();

        $buckets = [];
        foreach ($events as $event) {
            if (!$this->fingerprinter->isCandidate($event)) {
                continue;
            }
            $fp = $event->getFingerprint() ?? $this->fingerprinter->fingerprint($event);
            $buckets[$event->getApplication()->getId()->toRfc4122()."\0".$fp][] = $event;
        }

        foreach ($buckets as $key => $bucket) {
            [, $fp] = explode("\0", $key);
            $result = $this->em->wrapInTransaction(fn (): array => $this->groupBucket($fp, $bucket));
            $stats['grouped'] += $result['linked'];
            $stats['created'] += $result['created'] ? 1 : 0;
            $stats['reopened'] += $result['reopened'] ? 1 : 0;
        }

        return $stats;
    }

    /**
     * @param non-empty-list<Event> $events same application + fingerprint, oldest first
     *
     * @return array{linked: int, created: bool, reopened: bool}
     */
    private function groupBucket(string $fingerprint, array $events): array
    {
        $conn = $this->em->getConnection();
        $first = $events[0];
        $app = $first->getApplication();
        $conn->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:k, 0))', ['k' => $app->getId()->toRfc4122().$fingerprint]);

        // Persist the fingerprint on the events (Event is read-only in the ORM; update in SQL).
        $conn->executeStatement(
            'UPDATE event SET fingerprint = :fp WHERE id IN (:ids) AND fingerprint IS NULL',
            ['fp' => $fingerprint, 'ids' => array_map(static fn (Event $e): string => $e->getId()->toRfc4122(), $events)],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $incident = $this->em->getRepository(Incident::class)->findOneBy(['application' => $app, 'fingerprint' => $fingerprint]);
        $created = false;
        $reopened = false;

        if (!$incident instanceof Incident) {
            $ws = $app->getWorkspace()->getId()->toRfc4122();
            $conn->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:k, 0))', ['k' => 'incident-number:'.$ws]);
            $number = (int) $conn->fetchOne('SELECT COALESCE(MAX(number), 0) + 1 FROM incident WHERE workspace_id = :ws', ['ws' => $ws]);
            $incident = new Incident(
                $app,
                $number,
                $fingerprint,
                $this->fingerprinter->title($first),
                $first->getExceptionClass(),
                $first->getNormalizedMessage(),
                $first->getService(),
                $this->severityFor($events),
                $first->getOccurredAt(),
                (bool) ($first->getContext()['seeded'] ?? false),
            );
            $this->em->persist($incident);
            $this->em->flush();
            $created = true;
            $this->audit->write($app->getWorkspace(), 'system', 'grouping', 'incident.created', $incident, ['reference' => $incident->getReference(), 'title' => $incident->getTitle()]);
        }

        // Link occurrences idempotently; count only rows actually inserted.
        $values = [];
        $params = ['incident' => $incident->getId()->toRfc4122()];
        foreach ($events as $i => $event) {
            $values[] = "(:incident, :e$i, :t$i)";
            $params["e$i"] = $event->getId()->toRfc4122();
            $params["t$i"] = $event->getOccurredAt()->format('Y-m-d H:i:s.uP');
        }
        $inserted = $conn->fetchFirstColumn(
            'INSERT INTO incident_event (incident_id, event_id, occurred_at) VALUES '.implode(',', $values).' ON CONFLICT DO NOTHING RETURNING event_id',
            $params,
        );
        if ([] === $inserted) {
            return ['linked' => 0, 'created' => $created, 'reopened' => false];
        }

        $linked = array_values(array_filter($events, static fn (Event $e): bool => \in_array($e->getId()->toRfc4122(), $inserted, true)));
        $last = $linked[\count($linked) - 1];

        // A new occurrence after resolution reopens the incident.
        if (IncidentStatus::Resolved === $incident->getStatus() && $last->getOccurredAt() > $incident->getResolvedAt()) {
            $this->workflow->apply($incident, 'reopen');
            $reopened = true;
            $this->audit->write($app->getWorkspace(), 'system', 'grouping', 'incident.reopened', $incident, ['reference' => $incident->getReference()]);
            $this->logger->info('Incident {ref} reopened by a new occurrence', ['ref' => $incident->getReference()]);
        }

        $severity = $this->severityFor($linked);
        if ($severity->rank() > $incident->getSeverity()->rank()) {
            $incident->changeSeverity($severity);
        }
        $incident->recordOccurrences(\count($linked), $linked[0]->getOccurredAt(), $last->getOccurredAt(), $last->getId());
        $this->em->flush();

        return ['linked' => \count($linked), 'created' => $created, 'reopened' => $reopened];
    }

    /** @param non-empty-list<Event> $events */
    private function severityFor(array $events): IncidentSeverity
    {
        $best = IncidentSeverity::Info;
        foreach ($events as $e) {
            $status = $e->getContext()['http']['status'] ?? null;
            $s = IncidentSeverity::fromEvent($e->getSeverity(), null !== $e->getExceptionClass(), \is_int($status) ? $status : null);
            if ($s->rank() > $best->rank()) {
                $best = $s;
            }
        }

        return $best;
    }
}
