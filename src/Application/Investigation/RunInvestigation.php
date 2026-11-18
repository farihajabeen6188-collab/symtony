<?php

declare(strict_types=1);

namespace App\Application\Investigation;

final readonly class RunInvestigation
{
    public function __construct(public string $investigationId)
    {
    }
}
