<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingestion;

use App\Domain\Event\EventType;
use App\Domain\Event\Severity;

/**
 * A validated event payload, before redaction and persistence.
 */
final readonly class EventDraft
{
    /**
     * @param list<array{class?: string, function?: string, file?: string, line?: int, in_app?: bool}>|null $frames
     * @param array<string, mixed>                                                                          $context
     */
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public Severity $severity,
        public EventType $type,
        public string $service,
        public string $message,
        public ?string $environment,
        public ?string $release,
        public ?string $name,
        public ?string $traceId,
        public ?string $spanId,
        public ?string $parentSpanId,
        public ?string $requestId,
        public ?float $durationMs,
        public ?string $exceptionClass,
        public ?string $exceptionMessage,
        public ?array $frames,
        public array $context,
        public ?string $externalId,
    ) {
    }
}
