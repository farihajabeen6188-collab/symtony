<?php

declare(strict_types=1);

namespace App\Application\Investigation;

use App\Domain\Incident\Incident;
use App\Domain\Investigation\Investigation;
use App\Domain\Investigation\InvestigationStatus;
use App\Domain\Investigation\InvestigationStep;
use App\Domain\Investigation\Stage;
use App\Domain\Workspace\User;
use App\Infrastructure\Persistence\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Creates an investigation (all stages pending) and queues it for the worker.
 * Only one investigation per incident may be in progress at a time.
 */
final class StartInvestigation
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly AuditLogger $audit,
        #[Target('incident')] private readonly WorkflowInterface $workflow,
    ) {
    }

    public function __invoke(Incident $incident, ?User $user, string $trigger = 'user'): Investigation
    {
        $running = $this->em->getRepository(Investigation::class)->findOneBy(['incident' => $incident, 'status' => [InvestigationStatus::Queued, InvestigationStatus::Running]]);
        if ($running instanceof Investigation) {
            return $running;
        }

        $investigation = new Investigation($incident, $user, $trigger);
        $this->em->persist($investigation);
        foreach (Stage::cases() as $stage) {
            $this->em->persist(new InvestigationStep($investigation, $stage));
        }
        if ($this->workflow->can($incident, 'start_investigation')) {
            $this->workflow->apply($incident, 'start_investigation');
        }
        null !== $user
            ? $this->audit->userAction($incident->getWorkspace(), 'investigation.started', $investigation, ['reference' => $incident->getReference(), 'investigation' => $investigation->getShortId()])
            : $this->audit->write($incident->getWorkspace(), $trigger, $trigger, 'investigation.started', $investigation, ['reference' => $incident->getReference(), 'investigation' => $investigation->getShortId()]);
        $this->em->flush();

        $this->bus->dispatch(new RunInvestigation($investigation->getId()->toRfc4122()));

        return $investigation;
    }
}
