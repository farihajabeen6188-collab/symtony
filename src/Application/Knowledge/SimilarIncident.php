<?php

declare(strict_types=1);

namespace App\Application\Knowledge;

use App\Domain\Incident\Incident;

final readonly class SimilarIncident
{
    /**
     * @param list<string> $sharedTerms terms present in both incidents (explains the match)
     */
    public function __construct(
        public Incident $incident,
        public float $score,
        public string $method,
        public ?string $resolution,
        public array $sharedTerms = [],
    ) {
    }
}
