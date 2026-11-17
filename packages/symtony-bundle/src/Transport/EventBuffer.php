<?php

declare(strict_types=1);

namespace Symtony\Transport;

/**
 * Collects events during a request/command and sends them in batches at the end.
 */
final class EventBuffer
{
    private const MAX_EVENTS = 2000;
    private const BATCH = 500;

    /** @var list<array<string, mixed>> */
    private array $events = [];
    private int $dropped = 0;

    public function __construct(private readonly TransportInterface $transport)
    {
    }

    /** @param array<string, mixed> $event */
    public function add(array $event): void
    {
        if (\count($this->events) >= self::MAX_EVENTS) {
            ++$this->dropped;

            return;
        }
        $this->events[] = $event;
    }

    public function count(): int
    {
        return \count($this->events);
    }

    /** @return list<array<string, mixed>> */
    public function pending(): array
    {
        return $this->events;
    }

    public function flush(): bool
    {
        if ([] === $this->events) {
            return true;
        }
        $events = $this->events;
        $this->events = [];
        $ok = true;
        foreach (array_chunk($events, self::BATCH) as $batch) {
            $ok = $this->transport->send($batch) && $ok;
        }
        $this->dropped = 0;

        return $ok;
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    public function __destruct()
    {
        $this->flush();
    }
}
