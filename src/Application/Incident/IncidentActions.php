<?php

declare(strict_types=1);

namespace App\Application\Incident;

use App\Domain\Incident\Incident;
use App\Domain\Incident\IncidentNote;
use App\Domain\Workspace\User;
use App\Infrastructure\Persistence\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * User actions on incidents. Status changes go through the incident state machine; everything is audited.
 */
final class IncidentActions
{
    public const TRANSITION_LABELS = [
        'start_investigation' => 'Mark investigating',
        'identify' => 'Mark identified',
        'resolve' => 'Resolve',
        'reopen' => 'Reopen',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Target('incident')] private readonly WorkflowInterface $workflow,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return list<string> transitions currently allowed */
    public function availableTransitions(Incident $incident): array
    {
        return array_values(array_unique(array_map(static fn ($t): string => $t->getName(), $this->workflow->getEnabledTransitions($incident))));
    }

    public function transition(Incident $incident, string $transition, User $user, ?string $note = null): void
    {
        if (!$this->workflow->can($incident, $transition)) {
            throw new \DomainException(\sprintf('Cannot %s an incident that is %s.', self::TRANSITION_LABELS[$transition] ?? $transition, $incident->getStatus()->value));
        }
        $from = $incident->getStatus();
        $this->workflow->apply($incident, $transition);
        $text = \sprintf('Status changed from %s to %s.', $from->label(), $incident->getStatus()->label());
        $this->em->persist(new IncidentNote($incident, $user, null !== $note && '' !== trim($note) ? $text."\n\n".trim($note) : $text, 'resolve' === $transition && null !== $note && '' !== trim($note) ? 'resolution' : 'status'));
        $this->audit->userAction($incident->getWorkspace(), 'incident.'.$transition, $incident, ['reference' => $incident->getReference(), 'from' => $from->value, 'to' => $incident->getStatus()->value]);
        $this->em->flush();
    }

    public function assign(Incident $incident, ?User $user): void
    {
        $incident->assign($user);
        $this->audit->userAction($incident->getWorkspace(), 'incident.assigned', $incident, ['reference' => $incident->getReference(), 'assignee' => $user?->getEmail()]);
        $this->em->flush();
    }

    public function addNote(Incident $incident, User $user, string $body): IncidentNote
    {
        $body = trim($body);
        if ('' === $body) {
            throw new \DomainException('Write something before adding a note.');
        }
        $note = new IncidentNote($incident, $user, $body);
        $this->em->persist($note);
        $this->audit->userAction($incident->getWorkspace(), 'incident.note_added', $incident, ['reference' => $incident->getReference()]);
        $this->em->flush();

        return $note;
    }
}
