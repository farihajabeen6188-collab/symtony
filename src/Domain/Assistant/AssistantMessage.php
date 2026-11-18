<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Incident\Incident;
use App\Domain\Investigation\Investigation;
use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One turn of the assistant conversation of an incident: the user's question, the model's answer
 * (with the tools it really called and the evidence refs it cited), or a failure shown as such.
 */
#[ORM\Entity]
#[ORM\Table(name: 'assistant_message')]
#[ORM\Index(name: 'idx_assistant_incident_time', columns: ['incident_id', 'created_at'])]
class AssistantMessage
{
    public const USER = 'user';
    public const ASSISTANT = 'assistant';
    public const ERROR = 'error';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne(targetEntity: Incident::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Incident $incident;

    #[ORM\ManyToOne(targetEntity: Investigation::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Investigation $investigation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column(length: 12)]
    private string $role;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $toolCalls;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $evidence;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $source;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $toolCalls
     * @param list<string> $evidence
     */
    public function __construct(Incident $incident, ?Investigation $investigation, ?User $user, string $role, string $content, array $toolCalls = [], array $evidence = [], ?string $source = null)
    {
        $this->id = Uuid::v7();
        $this->workspace = $incident->getWorkspace();
        $this->incident = $incident;
        $this->investigation = $investigation;
        $this->user = $user;
        $this->role = $role;
        $this->content = $content;
        $this->toolCalls = $toolCalls;
        $this->evidence = $evidence;
        $this->source = $source;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getIncident(): Incident { return $this->incident; }
    public function getInvestigation(): ?Investigation { return $this->investigation; }
    public function getUser(): ?User { return $this->user; }
    public function getRole(): string { return $this->role; }
    public function getContent(): string { return $this->content; }
    /** @return list<string> */
    public function getToolCalls(): array { return $this->toolCalls; }
    /** @return list<string> */
    public function getEvidence(): array { return $this->evidence; }
    public function getSource(): ?string { return $this->source; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
