<?php

declare(strict_types=1);

namespace App\Domain\Incident;

use App\Domain\Event\Event;
use App\Infrastructure\Persistence\Type\MicroDateTimeTzType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Links an occurrence event to its incident. Written with INSERT … ON CONFLICT DO NOTHING (idempotent).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'incident_event')]
#[ORM\Index(name: 'idx_incident_event_time', columns: ['incident_id', 'occurred_at'])]
class IncidentEvent
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Incident $incident;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(type: MicroDateTimeTzType::NAME)]
    private \DateTimeImmutable $occurredAt;

    public function __construct(Incident $incident, Event $event)
    {
        $this->incident = $incident;
        $this->event = $event;
        $this->occurredAt = $event->getOccurredAt();
    }

    public function getIncident(): Incident { return $this->incident; }
    public function getEvent(): Event { return $this->event; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
