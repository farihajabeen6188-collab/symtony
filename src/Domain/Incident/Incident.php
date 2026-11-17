<?php

declare(strict_types=1);

namespace App\Domain\Incident;

use App\Domain\Application\Application;
use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Persistence\Type\MicroDateTimeTzType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A group of failure events sharing one fingerprint. One incident per (application, fingerprint):
 * a new occurrence after resolution reopens it instead of creating a duplicate.
 */
#[ORM\Entity]
#[ORM\Table(name: 'incident')]
#[ORM\UniqueConstraint(name: 'uniq_incident_fingerprint', columns: ['application_id', 'fingerprint'])]
#[ORM\UniqueConstraint(name: 'uniq_incident_number', columns: ['workspace_id', 'number'])]
#[ORM\Index(name: 'idx_incident_ws_last', columns: ['workspace_id', 'last_seen_at'])]
#[ORM\Index(name: 'idx_incident_ws_status', columns: ['workspace_id', 'status'])]
class Incident
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Application $application;

    /** Human number per workspace: SYM-142. */
    #[ORM\Column]
    private int $number;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $exceptionClass;

    #[ORM\Column(length: 500)]
    private string $normalizedMessage;

    #[ORM\Column(length: 64)]
    private string $service;

    /** Workflow marking (string; see IncidentStatus). */
    #[ORM\Column(length: 16)]
    private string $status = 'new';

    #[ORM\Column(length: 12, enumType: IncidentSeverity::class)]
    private IncidentSeverity $severity;

    #[ORM\Column(type: MicroDateTimeTzType::NAME)]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: MicroDateTimeTzType::NAME)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column]
    private int $occurrences = 0;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $lastEventId = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignee = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column]
    private int $reopenCount = 0;

    /** True when created from seeded demo history (shown with a "seeded" tag). */
    #[ORM\Column]
    private bool $seeded = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Application $application,
        int $number,
        string $fingerprint,
        string $title,
        ?string $exceptionClass,
        string $normalizedMessage,
        string $service,
        IncidentSeverity $severity,
        \DateTimeImmutable $firstSeenAt,
        bool $seeded = false,
    ) {
        $this->id = Uuid::v7();
        $this->application = $application;
        $this->workspace = $application->getWorkspace();
        $this->number = $number;
        $this->fingerprint = $fingerprint;
        $this->title = mb_substr($title, 0, 255);
        $this->exceptionClass = $exceptionClass;
        $this->normalizedMessage = mb_substr($normalizedMessage, 0, 500);
        $this->service = $service;
        $this->severity = $severity;
        $this->firstSeenAt = $firstSeenAt;
        $this->lastSeenAt = $firstSeenAt;
        $this->seeded = $seeded;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getWorkspace(): Workspace { return $this->workspace; }
    public function getApplication(): Application { return $this->application; }
    public function getNumber(): int { return $this->number; }
    public function getReference(): string { return 'SYM-'.$this->number; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function getTitle(): string { return $this->title; }
    public function getExceptionClass(): ?string { return $this->exceptionClass; }
    public function getNormalizedMessage(): string { return $this->normalizedMessage; }
    public function getService(): string { return $this->service; }
    public function getSeverity(): IncidentSeverity { return $this->severity; }
    public function getFirstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function getOccurrences(): int { return $this->occurrences; }
    public function getLastEventId(): ?Uuid { return $this->lastEventId; }
    public function getAssignee(): ?User { return $this->assignee; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function getReopenCount(): int { return $this->reopenCount; }
    public function isSeeded(): bool { return $this->seeded; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getStatus(): IncidentStatus
    {
        return IncidentStatus::from($this->status);
    }

    /** Used by the Workflow marking store. */
    public function getMarking(): string
    {
        return $this->status;
    }

    /**
     * Used by the Workflow marking store; prefer applying transitions through IncidentActions.
     *
     * @param array<string, mixed> $context
     */
    public function setMarking(string $marking, array $context = []): void
    {
        $new = IncidentStatus::from($marking);
        if (IncidentStatus::Resolved === $new) {
            $this->resolvedAt = $context['resolved_at'] ?? new \DateTimeImmutable();
        } elseif (IncidentStatus::Reopened === $new) {
            ++$this->reopenCount;
            $this->resolvedAt = null;
        }
        $this->status = $new->value;
    }

    public function rename(string $title): void
    {
        $this->title = mb_substr(trim($title), 0, 255);
    }

    public function assign(?User $user): void
    {
        $this->assignee = $user;
    }

    public function changeSeverity(IncidentSeverity $severity): void
    {
        $this->severity = $severity;
    }

    public function recordOccurrences(int $count, \DateTimeImmutable $first, \DateTimeImmutable $last, Uuid $lastEventId): void
    {
        $this->occurrences += $count;
        if ($first < $this->firstSeenAt) {
            $this->firstSeenAt = $first;
        }
        if ($last >= $this->lastSeenAt) {
            $this->lastSeenAt = $last;
            $this->lastEventId = $lastEventId;
        }
    }
}
