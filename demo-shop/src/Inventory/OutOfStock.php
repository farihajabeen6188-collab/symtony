<?php

declare(strict_types=1);

namespace App\Inventory;

final class OutOfStock extends \RuntimeException
{
    public function __construct(public readonly string $sku, public readonly int $requested, public readonly int $available)
    {
        parent::__construct(\sprintf('Only %d of %s left, %d requested.', $available, $sku, $requested));
    }
}
