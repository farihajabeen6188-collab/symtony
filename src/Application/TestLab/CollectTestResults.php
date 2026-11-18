<?php

declare(strict_types=1);

namespace App\Application\TestLab;

final readonly class CollectTestResults
{
    public function __construct(public string $batchId, public int $attempt)
    {
    }
}
