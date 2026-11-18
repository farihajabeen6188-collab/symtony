<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Domain\Application\Application;
use App\Domain\Incident\Incident;
use App\Domain\Investigation\Investigation;
use App\Domain\Investigation\InvestigationStatus;
use App\Domain\Notification\Notification;
use App\Domain\TestLab\RegressionTest;
use App\Domain\Workspace\Workspace;
use App\Domain\Workspace\WorkspaceMembership;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Creates notifications for every member of a workspace. Called from background handlers
 * (grouping, investigations, test results, the silent-application check). The caller flushes.
 */
final class Notifier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function criticalIncident(Incident $incident): void
    {
        $this->notify($incident->getWorkspace(), 'incident.critical', 'err', \sprintf('Critical incident %s', $incident->getReference()),
            \sprintf('%s in %s (%s).', $incident->getTitle(), $incident->getService(), $incident->getApplication()->getName()),
            $this->urls->generate('app_incident_show', ['number' => $incident->getNumber()]), 'critical:'.$incident->getId()->toRfc4122());
    }

    public function incidentReopened(Incident $incident): void
    {
        $this->notify($incident->getWorkspace(), 'incident.reopened', 'warn', \sprintf('%s reopened', $incident->getReference()),
            \sprintf('A new occurrence after it was resolved: %s', $incident->getTitle()),
            $this->urls->generate('app_incident_show', ['number' => $incident->getNumber()]), \sprintf('reopened:%s:%d', $incident->getId()->toRfc4122(), $incident->getReopenCount()));
    }

    public function investigationFinished(Investigation $inv): void
    {
        $ok = InvestigationStatus::Completed === $inv->getStatus();
        $this->notify($inv->getWorkspace(), $ok ? 'investigation.completed' : 'investigation.failed', $ok ? 'ok' : 'err',
            \sprintf('Investigation of %s %s', $inv->getIncident()->getReference(), $ok ? 'completed' : 'failed'),
            $ok ? (string) ($inv->getSummary() ?? 'The report is ready.') : (string) ($inv->getError() ?? 'A stage failed.'),
            $this->urls->generate('app_investigation_show', ['id' => $inv->getId()->toRfc4122()]), 'investigation:'.$inv->getId()->toRfc4122());
    }

    public function testVerified(RegressionTest $test, string $batchId): void
    {
        $this->notify($test->getWorkspace(), 'test.verified', 'ok', \sprintf('Regression test for %s reproduces the bug', $test->getIncident()->getReference()),
            \sprintf('%s failed on the buggy build and passed with the fix.', $test->getClassName()),
            $this->urls->generate('app_test_show', ['id' => $test->getId()->toRfc4122()]), 'verified:'.$batchId);
    }

    public function applicationSilent(Application $app): void
    {
        $last = $app->getLastEventAt();
        $this->notify($app->getWorkspace(), 'application.silent', 'warn', \sprintf('%s stopped sending events', $app->getName()),
            null !== $last ? \sprintf('No events since %s UTC.', $last->format('Y-m-d H:i')) : 'No events received.',
            $this->urls->generate('app_applications_show', ['id' => $app->getId()->toRfc4122()]), 'silent:'.$app->getId()->toRfc4122().':'.($last?->getTimestamp() ?? 0));
    }

    private function notify(Workspace $ws, string $kind, string $tone, string $title, string $body, string $url, string $dedupe): void
    {
        $memberships = $this->em->getRepository(WorkspaceMembership::class)->findBy(['workspace' => $ws]);
        $already = $this->em->getConnection()->fetchFirstColumn('SELECT user_id FROM notification WHERE dedupe_key = :k', ['k' => $dedupe]);
        foreach ($memberships as $m) {
            if (\in_array($m->getUser()->getId()->toRfc4122(), $already, true)) {
                continue;
            }
            $this->em->persist(new Notification($ws, $m->getUser(), $kind, $tone, $title, mb_substr($body, 0, 500), $url, $dedupe));
        }
    }
}
