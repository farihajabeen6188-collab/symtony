<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Application\Application;
use App\Domain\Workspace\Workspace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One normalized, redacted event as received from an application. Immutable after ingestion.
 * The id is a UUIDv7, so ids sort by ingestion time; occurredAt keeps the source timestamp.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'event')]
#[ORM\Index(name: 'idx_event_ws_time', columns: ['workspace_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_event_app_time', columns: ['application_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_event_ws_sev_time', columns: ['workspace_id', 'severity', 'occurred_at'])]
#[ORM\Index(name: 'idx_event_trace', columns: ['trace_id'])]
#[ORM\Index(name: 'idx_event_request', columns: ['request_id'])]
#[ORM\Index(name: 'idx_event_span', columns: ['span_id'])]
#[ORM\Index(name: 'idx_event_fingerprint', columns: ['fingerprint'])]
#[ORM\UniqueConstraint(name: 'uniq_event_external', columns: ['application_id', 'external_id'])]
class Event
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

    /** Client-supplied event id, if any (idempotency / cross-reference). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $externalId;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(length: 12, enumType: Severity::class)]
    private Severity $severity;

    #[ORM\Column(length: 16, enumType: EventType::class)]
    private EventType $type;

    #[ORM\Column(length: 64)]
    private string $service;

    #[ORM\Column(length: 16)]
    private string $environment;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $release;

    /** Span/operation name, e.g. "POST /checkout" or "InventoryService::reserve". */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(type: Types::TEXT)]
    private string $normalizedMessage;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $traceId;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $spanId;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $parentSpanId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $requestId;

    #[ORM\Column(nullable: true)]
    private ?float $durationMs;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $exceptionClass;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $exceptionMessage;

    /** @var list<array{class?: string, function?: string, file?: string, line?: int, in_app?: bool}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $stackTrace;

    /** Stable fingerprint for grouping, computed at ingestion for failure events. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $fingerprint = null;

    /** @var array<string, mixed> redacted user-defined + structured context (http, db, cache, ...) */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $context;

    #[ORM\Column(length: 16)]
    private string $source;

    #[ORM\Column]
    private int $redactions;

    /**
     * @param list<array{class?: string, function?: string, file?: string, line?: int, in_app?: bool}>|null $stackTrace
     * @param array<string, mixed>                                                                          $context
     */
    public function __construct(
        Application $application,
        \DateTimeImmutable $occurredAt,
        Severity $severity,
        EventType $type,
        string $service,
        string $message,
        string $normalizedMessage,
        string $source,
        ?string $environment = null,
        ?string $release = null,
        ?string $name = null,
        ?string $traceId = null,
        ?string $spanId = null,
        ?string $parentSpanId = null,
        ?string $requestId = null,
        ?float $durationMs = null,
        ?string $exceptionClass = null,
        ?string $exceptionMessage = null,
        ?array $stackTrace = null,
        array $context = [],
        ?string $externalId = null,
        int $redactions = 0,
        ?\DateTimeImmutable $receivedAt = null,
    ) {
        $this->id = Uuid::v7();
        $this->application = $application;
        $this->workspace = $application->getWorkspace();
        $this->occurredAt = $occurredAt;
        $this->receivedAt = $receivedAt ?? new \DateTimeImmutable();
        $this->severity = $severity;
        $this->type = $type;
        $this->service = $service;
        $this->message = $message;
        $this->normalizedMessage = $normalizedMessage;
        $this->source = $source;
        $this->environment = $environment ?? $application->getEnvironment()->value;
        $this->release = $release;
        $this->name = $name;
        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $this->parentSpanId = $parentSpanId;
        $this->requestId = $requestId;
        $this->durationMs = $durationMs;
        $this->exceptionClass = $exceptionClass;
        $this->exceptionMessage = $exceptionMessage;
        $this->stackTrace = $stackTrace;
        $this->context = $context;
        $this->externalId = $externalId;
        $this->redactions = $redactions;
    }

    public function assignFingerprint(string $fingerprint): void
    {
        $this->fingerprint = $fingerprint;
    }

    public function getId(): Uuid { return $this->id; }
    public function getWorkspace(): Workspace { return $this->workspace; }
    public function getApplication(): Application { return $this->application; }
    public function getExternalId(): ?string { return $this->externalId; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function getSeverity(): Severity { return $this->severity; }
    public function getType(): EventType { return $this->type; }
    public function getService(): string { return $this->service; }
    public function getEnvironment(): string { return $this->environment; }
    public function getRelease(): ?string { return $this->release; }
    public function getName(): ?string { return $this->name; }
    public function getMessage(): string { return $this->message; }
    public function getNormalizedMessage(): string { return $this->normalizedMessage; }
    public function getTraceId(): ?string { return $this->traceId; }
    public function getSpanId(): ?string { return $this->spanId; }
    public function getParentSpanId(): ?string { return $this->parentSpanId; }
    public function getRequestId(): ?string { return $this->requestId; }
    public function getDurationMs(): ?float { return $this->durationMs; }
    public function getExceptionClass(): ?string { return $this->exceptionClass; }
    public function getExceptionMessage(): ?string { return $this->exceptionMessage; }

    /** @return list<array{class?: string, function?: string, file?: string, line?: int, in_app?: bool}>|null */
    public function getStackTrace(): ?array { return $this->stackTrace; }

    public function getFingerprint(): ?string { return $this->fingerprint; }

    /** @return array<string, mixed> */
    public function getContext(): array { return $this->context; }

    public function getSource(): string { return $this->source; }
    public function getRedactions(): int { return $this->redactions; }

    /** Short, human id derived from the UUID, e.g. "ev_01JD3K…" style display. */
    public function getShortId(): string
    {
        return substr($this->id->toBase58(), -8);
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        if (null === $this->durationMs) {
            return $this->occurredAt;
        }

        return $this->occurredAt->modify(\sprintf('+%d microseconds', (int) round($this->durationMs * 1000)));
    }
}
