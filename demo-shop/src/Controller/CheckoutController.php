<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\ProductNotFound;
use App\Checkout\CheckoutService;
use App\Inventory\OutOfStock;
use App\Payment\PaymentDeclined;
use App\Security\CustomerAuthenticator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CheckoutController
{
    public function __construct(
        private readonly CustomerAuthenticator $auth,
        private readonly CheckoutService $checkout,
    ) {
    }

    #[Route('/checkout', name: 'checkout', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $customer = $this->auth->authenticate($request);
        $payload = $request->toArray();
        $sku = (string) ($payload['sku'] ?? '');
        $quantity = (int) ($payload['quantity'] ?? 1);
        if ('' === $sku || $quantity < 1 || $quantity > 10) {
            return new JsonResponse(['error' => 'Send {"sku": "...", "quantity": 1-10}.'], 422);
        }

        try {
            $order = $this->checkout->checkout($customer, $sku, $quantity);
        } catch (OutOfStock $e) {
            return new JsonResponse(['error' => 'out_of_stock', 'detail' => $e->getMessage(), 'available' => $e->available], 409);
        } catch (ProductNotFound $e) {
            return new JsonResponse(['error' => 'not_found', 'detail' => $e->getMessage()], 404);
        } catch (PaymentDeclined $e) {
            return new JsonResponse(['error' => 'payment_declined', 'detail' => $e->getMessage()], 402);
        }

        return new JsonResponse($order, 201);
    }
}
