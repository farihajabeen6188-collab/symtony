<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\ProductRepository;
use App\Catalog\ShopSchema;
use App\Inventory\InventoryService;
use App\Security\CustomerAuthenticator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symtony\Tracer;

/**
 * Control endpoints used by SYMTONY's "Run failure scenario" button. Not traced themselves
 * (_symtony_ignore): only the customer traffic and the warehouse job they trigger reach SYMTONY.
 */
#[Route('/_demo', defaults: ['_symtony_ignore' => true])]
final class DemoController
{
    public function __construct(
        private readonly ShopSchema $schema,
        private readonly ProductRepository $products,
        private readonly InventoryService $inventory,
        private readonly Tracer $tracer,
        #[Autowire(env: 'DEMO_SHOP_SECRET')] private readonly string $secret,
        #[Autowire(env: 'APP_RELEASE')] private readonly string $release,
    ) {
    }

    #[Route('/reset', name: 'demo_reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        if (null !== ($denied = $this->guard($request))) {
            return $denied;
        }
        $this->schema->install();
        $this->inventory->forget(ShopSchema::SKU);

        return new JsonResponse(['reset' => true, 'products' => $this->products->all()]);
    }

    /**
     * Reproduces the production incident with real requests:
     * deploy 1.4.0 → a shopper views the product (stock 3 is cached) → the warehouse sync corrects
     * stock to 1 in the database → three shoppers check out.
     */
    #[Route('/scenario', name: 'demo_scenario', methods: ['POST'])]
    public function scenario(Request $request): JsonResponse
    {
        if (null !== ($denied = $this->guard($request))) {
            return $denied;
        }

        $this->schema->install();
        $this->inventory->forget(ShopSchema::SKU);
        $steps = [];

        $this->tracer->context()->begin();
        $deployed = $this->tracer->deploy($this->release, 'a41c9e2', 'release-pipeline');
        $steps[] = ['step' => 'deploy', 'release' => $this->release, 'reported' => $deployed];

        // Plain HTTP client on purpose: each shopper request starts its own trace, like real traffic.
        $http = HttpClient::create(['base_uri' => 'http://localhost', 'timeout' => 15]);
        usleep(400_000);
        $view = $http->request('GET', '/products/'.ShopSchema::SKU);
        $steps[] = ['step' => 'shopper views product', 'status' => $view->getStatusCode(), 'body' => $view->toArray(false)];

        usleep(600_000);
        $this->warehouseSync(ShopSchema::SKU, 1);
        $steps[] = ['step' => 'warehouse sync sets stock to 1 in the database'];

        foreach (CustomerAuthenticator::demoTokens() as $token) {
            usleep(700_000);
            $response = $http->request('POST', '/checkout', [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'json' => ['sku' => ShopSchema::SKU, 'quantity' => 1],
            ]);
            $steps[] = ['step' => 'checkout', 'customer' => $token, 'status' => $response->getStatusCode()];
        }

        return new JsonResponse(['scenario' => 'stale-stock-cache', 'release' => $this->release, 'steps' => $steps]);
    }

    /** A separate background job: updates the authoritative stock level in the database. */
    private function warehouseSync(string $sku, int $stock): void
    {
        $this->tracer->context()->begin();
        $this->tracer->context()->setService('warehouse-sync');
        $this->tracer->span('WarehouseSync::run', function () use ($sku, $stock): void {
            $before = $this->products->stock($sku);
            $this->products->setStock($sku, $stock);
            $this->tracer->event('log', \sprintf('Stock for %s updated from %d to %d', $sku, $before, $stock), 'notice', [
                'component' => 'warehouse', 'inventory' => ['sku' => $sku, 'from' => $before, 'to' => $stock],
            ], 'stock adjusted');
        }, 'queue', ['component' => 'warehouse']);
        $this->tracer->flush();
    }

    private function guard(Request $request): ?JsonResponse
    {
        $given = (string) $request->headers->get('X-Demo-Secret', '');

        return '' !== $this->secret && hash_equals($this->secret, $given) ? null : new JsonResponse(['error' => 'forbidden'], 403);
    }
}
