<?php

declare(strict_types=1);

namespace App\Domain\Investigation;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One piece of evidence exactly as the analyzers and the model saw it (already redacted). Referenced as EV-n.
 */
#[ORM\Entity]
#[ORM\Table(name: 'evidence_item')]
#[ORM\UniqueConstraint(name: 'uniq_evidence_ref', columns: ['investigation_id', 'ref'])]
class EvidenceItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Investigation $investigation;

    #[ORM\Column(length: 12)]
    private string $ref;

    /** event | context | incident | history */
    #[ORM\Column(length: 12)]
    private string $kind;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $eventId;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(type: Types::TEXT)]
    private string $excerpt;

    /** @var array<string, mixed> structured facts used by analyzers and validation */
    #[ORM\Column(type: Types::JSON)]
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(Investigation $investigation, string $ref, string $kind, ?Uuid $eventId, string $label, string $excerpt, array $data = [])
    {
        $this->id = Uuid::v7();
        $this->investigation = $investigation;
        $this->ref = $ref;
        $this->kind = $kind;
        $this->eventId = $eventId;
        $this->label = mb_substr($label, 0, 255);
        $this->excerpt = $excerpt;
        $this->data = $data;
    }

    public function getId(): Uuid { return $this->id; }
    public function getInvestigation(): Investigation { return $this->investigation; }
    public function getRef(): string { return $this->ref; }
    public function getKind(): string { return $this->kind; }
    public function getEventId(): ?Uuid { return $this->eventId; }
    public function getLabel(): string { return $this->label; }
    public function getExcerpt(): string { return $this->excerpt; }
    /** @return array<string, mixed> */
    public function getData(): array { return $this->data; }
}
