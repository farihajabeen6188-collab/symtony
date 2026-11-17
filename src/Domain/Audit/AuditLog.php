<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Workspace\Workspace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only record of security-relevant and state-changing actions (users, tokens, MCP clients, system).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_ws_time', columns: ['workspace_id', 'created_at'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 12)]
    private string $actorType;

    #[ORM\Column(length: 180)]
    private string $actor;

    #[ORM\Column(length: 64)]
    private string $action;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $subjectType;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $subjectId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $metadata */
    public function __construct(Workspace $workspace, string $actorType, string $actor, string $action, ?string $subjectType = null, ?string $subjectId = null, array $metadata = [])
    {
        $this->id = Uuid::v7();
        $this->workspace = $workspace;
        $this->actorType = $actorType;
        $this->actor = mb_substr($actor, 0, 180);
        $this->action = $action;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getWorkspace(): Workspace { return $this->workspace; }
    public function getActorType(): string { return $this->actorType; }
    public function getActor(): string { return $this->actor; }
    public function getAction(): string { return $this->action; }
    public function getSubjectType(): ?string { return $this->subjectType; }
    public function getSubjectId(): ?string { return $this->subjectId; }
    /** @return array<string, mixed> */
    public function getMetadata(): array { return $this->metadata; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
