<?php

declare(strict_types=1);

namespace App\Application\TestLab;

final readonly class TestProposal
{
    /**
     * @param list<string> $preconditions
     * @param list<string> $citations
     */
    public function __construct(
        public string $title,
        public string $purpose,
        public array $preconditions,
        public string $scenario,
        public string $expected,
        public string $className,
        public string $code,
        public array $citations,
        public string $source,
    ) {
    }
}
