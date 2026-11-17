<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symtony\Tracer;

/**
 * A stand-in payment provider. It runs inside the shop container but reports as its own service
 * ("payment-api") so the reconstructed timeline shows the cross-service hop.
 */
final class PaymentApiController
{
    public function __construct(private readonly Tracer $tracer)
    {
    }

    #[Route('/payment-api/authorize', name: 'payment_api_authorize', methods: ['POST'], defaults: ['_symtony_service' => 'payment-api'])]
    public function authorize(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $amount = (int) ($payload['amount_cents'] ?? 0);
        if ($amount <= 0) {
            return new JsonResponse(['status' => 'declined', 'reason' => 'invalid_amount'], 422);
        }

        // Simulated provider latency.
        usleep(random_int(60, 140) * 1000);
        $authorization = 'auth_'.bin2hex(random_bytes(6));
        $this->tracer->event('log', \sprintf('Authorized %s %s, hold %s', number_format($amount / 100, 2), (string) ($payload['currency'] ?? 'EUR'), $authorization), 'notice', [
            'component' => 'payment-api',
            'payment' => ['authorization_id' => $authorization, 'amount_cents' => $amount, 'reference' => (string) ($payload['reference'] ?? '')],
        ], 'payment authorized');

        return new JsonResponse(['status' => 'authorized', 'authorization_id' => $authorization]);
    }
}
