<?php

declare(strict_types=1);

namespace App\Domain\Investigation;

use App\Domain\Incident\Incident;
use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One evidence-grounded investigation of an incident. Immutable once completed; re-running creates a new one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'investigation')]
#[ORM\Index(name: 'idx_investigation_ws_created', columns: ['workspace_id', 'created_at'])]
#[ORM\Index(name: 'idx_investigation_incident', columns: ['incident_id', 'created_at'])]
class Investigation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Incident $incident;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedBy;

    /** "user" | "mcp" | "system" */
    #[ORM\Column(length: 12)]
    private string $trigger;

    #[ORM\Column(length: 12, enumType: InvestigationStatus::class)]
    private InvestigationStatus $status = InvestigationStatus::Queued;

    /** "heuristic" or "llm:<provider>/<model>" once known. */
    #[ORM\Column(length: 160)]
    private string $engine = 'heuristic';

    #[ORM\Column(length: 16)]
    private string $promptVersion = '';

    /** The occurrence whose trace was investigated. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $focusEventId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $traceId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    /** @var array<string, mixed> recommended fix, next steps, impact, reproduction, limitations (each with citations) */
    #[ORM\Column(type: Types::JSON)]
    private array $report = [];

    /** @var array<string, int|float|null> prompt/completion tokens and latency reported by the provider */
    #[ORM\Column(type: Types::JSON)]
    private array $usage = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(Incident $incident, ?User $requestedBy, string $trigger = 'user')
    {
        $this->id = Uuid::v7();
        $this->incident = $incident;
        $this->workspace = $incident->getWorkspace();
        $this->requestedBy = $requestedBy;
        $this->trigger = $trigger;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getWorkspace(): Workspace { return $this->workspace; }
    public function getIncident(): Incident { return $this->incident; }
    public function getRequestedBy(): ?User { return $this->requestedBy; }
    public function getTrigger(): string { return $this->trigger; }
    public function getStatus(): InvestigationStatus { return $this->status; }
    public function getEngine(): string { return $this->engine; }
    public function getPromptVersion(): string { return $this->promptVersion; }
    public function getFocusEventId(): ?Uuid { return $this->focusEventId; }
    public function getTraceId(): ?string { return $this->traceId; }
    public function getSummary(): ?string { return $this->summary; }
    /** @return array<string, mixed> */
    public function getReport(): array { return $this->report; }
    /** @return array<string, int|float|null> */
    public function getUsage(): array { return $this->usage; }
    public function getError(): ?string { return $this->error; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }

    public function getShortId(): string
    {
        return 'INV-'.strtoupper(substr($this->id->toBase58(), -6));
    }

    public function durationSeconds(): ?float
    {
        if (null === $this->startedAt || null === $this->finishedAt) {
            return null;
        }

        return round((float) $this->finishedAt->format('U.u') - (float) $this->startedAt->format('U.u'), 2);
    }

    public function start(): void
    {
        $this->status = InvestigationStatus::Running;
        $this->startedAt = new \DateTimeImmutable();
        $this->error = null;
    }

    public function focus(?Uuid $eventId, ?string $traceId): void
    {
        $this->focusEventId = $eventId;
        $this->traceId = $traceId;
    }

    public function useEngine(string $engine, string $promptVersion = ''): void
    {
        $this->engine = mb_substr($engine, 0, 160);
        $this->promptVersion = $promptVersion;
    }

    /** @param array<string, int|float|null> $usage */
    public function recordUsage(array $usage): void
    {
        $this->usage = $usage;
    }

    /** @param array<string, mixed> $report */
    public function complete(string $summary, array $report): void
    {
        $this->summary = $summary;
        $this->report = $report;
        $this->status = InvestigationStatus::Completed;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function fail(string $error): void
    {
        $this->error = mb_substr($error, 0, 4000);
        $this->status = InvestigationStatus::Failed;
        $this->finishedAt = new \DateTimeImmutable();
    }
}
