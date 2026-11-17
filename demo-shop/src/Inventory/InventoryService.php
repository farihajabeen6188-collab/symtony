<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Catalog\ProductRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symtony\Tracer;

final class InventoryService
{
    /** Release 1.4.0: stock levels are cached to take load off the database during sales. */
    private const STOCK_TTL = 600;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly CacheInterface $cache,
        private readonly Tracer $tracer,
    ) {
    }

    public function availableStock(string $sku): int
    {
        return $this->tracer->span('InventoryService::availableStock', fn (): int => $this->cache->get(
            'stock_'.$sku,
            function (ItemInterface $item) use ($sku): int {
                $item->expiresAfter(self::STOCK_TTL);

                return $this->products->stock($sku);
            },
        ), context: ['component' => 'inventory', 'inventory' => ['sku' => $sku]]);
    }

    public function reserve(string $sku, int $quantity): void
    {
        $this->tracer->span('InventoryService::reserve', function () use ($sku, $quantity): void {
            $this->products->decrementStock($sku, $quantity);
        }, context: ['component' => 'inventory', 'inventory' => ['sku' => $sku, 'quantity' => $quantity]]);
    }

    public function forget(string $sku): void
    {
        $this->cache->delete('stock_'.$sku);
    }
}
