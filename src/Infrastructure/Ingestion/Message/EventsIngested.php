<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingestion\Message;

/**
 * Dispatched after a batch is committed. Handlers enrich and group events asynchronously.
 */
final readonly class EventsIngested
{
    /** @param list<string> $eventIds RFC 4122 UUID strings */
    public function __construct(
        public string $applicationId,
        public array $eventIds,
    ) {
    }
}
