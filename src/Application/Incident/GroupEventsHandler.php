<?php

declare(strict_types=1);

namespace App\Application\Incident;

use App\Infrastructure\Ingestion\Message\EventsIngested;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Async: groups freshly ingested failure events into incidents. Idempotent on redelivery.
 */
#[AsMessageHandler]
final class GroupEventsHandler
{
    public function __construct(private readonly IncidentGrouper $grouper)
    {
    }

    public function __invoke(EventsIngested $message): void
    {
        $this->grouper->group($message->eventIds);
    }
}
