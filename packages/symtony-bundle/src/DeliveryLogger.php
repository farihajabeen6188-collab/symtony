<?php

declare(strict_types=1);

namespace Symtony;

use Psr\Log\LoggerInterface;

/**
 * Reports delivery problems once per process to the app's own log (channel "symtony", never re-forwarded).
 *
 * @internal
 */
final class DeliveryLogger
{
    private bool $reported = false;

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public function onError(string $reason): void
    {
        if ($this->reported) {
            return;
        }
        $this->reported = true;
        $this->logger?->warning('SYMTONY could not receive events: {reason}', ['reason' => $reason]);
    }
}
