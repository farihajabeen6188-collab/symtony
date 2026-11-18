<?php

declare(strict_types=1);

namespace App\Application\TestLab;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Picks up runner results. Re-checks every 2 s until both runs of the batch are done (or abandoned).
 */
#[AsMessageHandler]
final class CollectTestResultsHandler
{
    private const MAX_ATTEMPTS = 120;

    public function __construct(
        private readonly TestLab $lab,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(CollectTestResults $message): void
    {
        if (!$this->lab->collect($message->batchId) && $message->attempt < self::MAX_ATTEMPTS) {
            $this->bus->dispatch(new CollectTestResults($message->batchId, $message->attempt + 1), [new DelayStamp(2000)]);
        }
    }
}
