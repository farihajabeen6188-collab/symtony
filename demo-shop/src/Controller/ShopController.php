<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\ProductRepository;
use App\Inventory\InventoryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ShopController
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly InventoryService $inventory,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        try {
            $rows = $this->products->all();
        } catch (\Throwable) {
            $rows = [];
        }
        $items = '';
        foreach ($rows as $p) {
            $items .= \sprintf('<li><strong>%s</strong> <code>%s</code> — €%s · %d in stock</li>',
                htmlspecialchars($p['name']), htmlspecialchars($p['sku']), number_format($p['price_cents'] / 100, 2), $p['stock']);
        }
        $items = '' !== $items ? $items : '<li>Catalog not installed yet. SYMTONY installs it when you run the failure scenario.</li>';

        return new Response(<<<HTML
            <!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Acme Outdoor · demo shop</title>
            <style>body{font:15px/1.5 system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem;color:#111}code{color:#555}</style>
            <h1>Acme Outdoor</h1>
            <p>A small Symfony shop used to demonstrate SYMTONY. It contains a real bug introduced in release 1.4.0.</p>
            <ul>{$items}</ul>
            <p>API: <code>POST /checkout</code> with <code>Authorization: Bearer demo-customer-ada</code> and <code>{"sku":"SKU-1042","quantity":1}</code>.</p>
            HTML);
    }

    #[Route('/products/{sku}', name: 'product', methods: ['GET'])]
    public function product(string $sku): JsonResponse
    {
        $product = $this->products->find($sku) ?? throw new NotFoundHttpException('Unknown product.');

        return new JsonResponse(['sku' => $product['sku'], 'name' => $product['name'], 'price_cents' => $product['price_cents'], 'available' => $this->inventory->availableStock($sku)]);
    }
}
