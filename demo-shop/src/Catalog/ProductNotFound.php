<?php

declare(strict_types=1);

namespace App\Catalog;

final class ProductNotFound extends \RuntimeException
{
    public function __construct(public readonly string $sku)
    {
        parent::__construct(\sprintf('Product "%s" does not exist.', $sku));
    }
}
