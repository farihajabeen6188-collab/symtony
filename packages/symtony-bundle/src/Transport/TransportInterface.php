<?php

declare(strict_types=1);

namespace Symtony\Transport;

interface TransportInterface
{
    /**
     * Sends a batch of events. Must never throw; returns false on any failure.
     *
     * @param list<array<string, mixed>> $events
     */
    public function send(array $events): bool;
}
