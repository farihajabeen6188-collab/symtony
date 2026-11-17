<?php

declare(strict_types=1);

namespace App\Domain\Incident;

use App\Domain\Workspace\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'incident_note')]
#[ORM\Index(name: 'idx_note_incident', columns: ['incident_id', 'created_at'])]
class IncidentNote
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Incident $incident;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    /** "note" | "resolution" | "status" */
    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Incident $incident, ?User $author, string $body, string $kind = 'note', ?\DateTimeImmutable $createdAt = null)
    {
        $this->id = Uuid::v7();
        $this->incident = $incident;
        $this->author = $author;
        $this->body = mb_substr(trim($body), 0, 10000);
        $this->kind = $kind;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getIncident(): Incident { return $this->incident; }
    public function getAuthor(): ?User { return $this->author; }
    public function getBody(): string { return $this->body; }
    public function getKind(): string { return $this->kind; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
