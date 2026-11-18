<?php

declare(strict_types=1);

namespace App\Application\Investigation;

/**
 * A proposed finding, before persistence and grounding validation.
 */
final readonly class FindingDraft
{
    /**
     * @param 'fact'|'inference'|'hypothesis' $kind
     * @param 'low'|'medium'|'high'           $confidence
     * @param list<string>                   $citations      EV refs that support it
     * @param list<string>                   $contradictions EV refs that argue against it
     */
    public function __construct(
        public string $kind,
        public string $statement,
        public string $rationale,
        public string $confidence,
        public array $citations,
        public string $source,
        public array $contradictions = [],
        public int $weight = 0,
    ) {
    }
}
