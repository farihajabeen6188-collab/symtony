<?php

declare(strict_types=1);

namespace App\Application\Timeline;

use App\Domain\Event\Event;

/**
 * A reconstructed sequence of events: lanes, items (spans and points), links with proven/inferred flags.
 * Offsets are milliseconds from the first item.
 */
final readonly class Timeline
{
    /**
     * @param list<array{key: string, service: string, component: string, label: string}>                                         $lanes
     * @param list<TimelineItem>                                                                                                    $items
     * @param list<array{from: string, to: string, kind: string, proven: bool, reason: string}>                                     $links
     */
    public function __construct(
        public ?string $traceId,
        public \DateTimeImmutable $start,
        public float $durationMs,
        public array $lanes,
        public array $items,
        public array $links,
        public ?string $firstFailureId,
        public bool $truncated,
        public int $total,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }

    public function item(string $eventId): ?TimelineItem
    {
        foreach ($this->items as $item) {
            if ($item->id === $eventId) {
                return $item;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'start' => $this->start->format('Y-m-d\TH:i:s.u\Z'),
            'duration_ms' => $this->durationMs,
            'lanes' => $this->lanes,
            'items' => array_map(static fn (TimelineItem $i): array => $i->toArray(), $this->items),
            'links' => $this->links,
            'first_failure' => $this->firstFailureId,
            'truncated' => $this->truncated,
            'total' => $this->total,
        ];
    }
}
