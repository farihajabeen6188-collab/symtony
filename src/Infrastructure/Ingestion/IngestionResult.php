<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingestion;

final readonly class IngestionResult
{
    /**
     * @param list<string>                                         $acceptedIds
     * @param list<array{index: int, errors: array<string, string>}> $rejected
     */
    public function __construct(
        public array $acceptedIds,
        public array $rejected,
        public int $duplicates,
        public int $redactions,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'accepted' => \count($this->acceptedIds),
            'duplicates' => $this->duplicates,
            'rejected' => $this->rejected,
            'redacted_values' => $this->redactions,
            'event_ids' => $this->acceptedIds,
        ];
    }
}
