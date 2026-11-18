<?php

declare(strict_types=1);

namespace App\Application\Investigation;

use App\Application\Timeline\Timeline;
use App\Domain\Incident\Incident;
use App\Domain\Investigation\EvidenceItem;

/**
 * Everything an investigation may reason about. Analyzers and the model only see this; the validator only
 * accepts citations that exist here.
 */
final class EvidencePackage
{
    /** @var array<string, EvidenceItem> ref => item */
    private array $items = [];

    public function __construct(
        public readonly Incident $incident,
        public readonly ?Timeline $timeline,
    ) {
    }

    public function add(EvidenceItem $item): void
    {
        $this->items[$item->getRef()] = $item;
    }

    public function nextRef(): string
    {
        return 'EV-'.(\count($this->items) + 1);
    }

    public function has(string $ref): bool
    {
        return isset($this->items[$ref]);
    }

    public function get(string $ref): ?EvidenceItem
    {
        return $this->items[$ref] ?? null;
    }

    /** @return array<string, EvidenceItem> */
    public function all(): array
    {
        return $this->items;
    }

    /** @return list<EvidenceItem> */
    public function ofKind(string $kind): array
    {
        return array_values(array_filter($this->items, static fn (EvidenceItem $i): bool => $kind === $i->getKind()));
    }

    /**
     * Event evidence matching a predicate on its structured data.
     *
     * @param callable(array<string, mixed>, EvidenceItem): bool $predicate
     *
     * @return list<EvidenceItem>
     */
    public function where(callable $predicate): array
    {
        return array_values(array_filter($this->items, static fn (EvidenceItem $i): bool => $predicate($i->getData(), $i)));
    }

    /** Compact index for the model: one line per item. */
    public function index(): string
    {
        $lines = [];
        foreach ($this->items as $ref => $item) {
            $lines[] = $ref.' '.$item->getExcerpt();
        }

        return implode("\n", $lines);
    }
}
