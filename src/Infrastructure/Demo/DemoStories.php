<?php

declare(strict_types=1);

namespace App\Infrastructure\Demo;

use App\Domain\Application\Application;
use App\Domain\Incident\Incident;
use App\Domain\Incident\IncidentNote;
use App\Domain\Incident\IncidentStatus;
use App\Domain\Workspace\User;
use App\Domain\Workspace\WorkspaceMembership;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Gives seeded demo incidents their historical outcomes (resolution notes written by the demo engineer).
 * These notes are what the knowledge base later retrieves; they are labelled as seeded history.
 */
final class DemoStories
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Target('incident')] private readonly WorkflowInterface $workflow,
    ) {
    }

    /** @return list<string> */
    public function resolveHistory(Application $app, \DateTimeImmutable $now): array
    {
        $author = $this->em->getRepository(WorkspaceMembership::class)->findOneBy(['workspace' => $app->getWorkspace()])?->getUser();
        $lines = [];

        $pricing = $this->incident($app, 'App\Pricing\OrderTotalMismatch');
        if (null !== $pricing && IncidentStatus::Resolved !== $pricing->getStatus()) {
            $resolvedAt = $now->modify('-21 days')->modify('+2 hours');
            $this->note($pricing, $author, <<<'TXT'
                Root cause: PricingService cached list prices for 15 minutes (cache key price_<sku>). A price update in the back office changed the database, but checkout kept reading the cached price, so order totals no longer matched the price list and OrderTotalMismatch was thrown.

                Fix (release 1.3.0): invalidate the price cache when a price is written, and recompute the authoritative total from the database inside the checkout transaction instead of trusting the cached value.

                Regression test: tests/Checkout/CheckoutTotalsTest::testPriceChangeInvalidatesCachedTotal — fails on 1.2.0, passes on 1.3.0.
                TXT, 'resolution', $resolvedAt);
            $this->workflow->apply($pricing, 'resolve', ['resolved_at' => $resolvedAt]);
            $lines[] = \sprintf('%s resolved 21 days ago with a resolution note.', $pricing->getReference());
        }

        $mailer = $this->incident($app, 'Symfony\Component\Mailer\Exception\TransportException');
        if (null !== $mailer && IncidentStatus::Resolved !== $mailer->getStatus() && 0 === $mailer->getReopenCount()) {
            $resolvedAt = $now->modify('-70 hours');
            $this->note($mailer, $author, 'SMTP relay smtp.acme.test was down during the provider maintenance window. Confirmed recovered; no code change.', 'resolution', $resolvedAt);
            $this->workflow->apply($mailer, 'resolve', ['resolved_at' => $resolvedAt]);
            $lines[] = \sprintf('%s resolved 70 hours ago (provider maintenance).', $mailer->getReference());
        }

        $this->em->flush();

        return $lines;
    }

    private function incident(Application $app, string $exceptionClass): ?Incident
    {
        $incident = $this->em->getRepository(Incident::class)->findOneBy(['application' => $app, 'exceptionClass' => $exceptionClass]);

        return $incident instanceof Incident ? $incident : null;
    }

    private function note(Incident $incident, ?User $author, string $body, string $kind, \DateTimeImmutable $at): void
    {
        $this->em->persist(new IncidentNote($incident, $author, $body, $kind, $at));
    }
}
