<?php

declare(strict_types=1);

namespace App\Payment;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symtony\Tracer;

/**
 * Talks to the payment provider over HTTP. Authorizing reserves money on the customer's card.
 */
final class PaymentGateway
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Tracer $tracer,
        #[Autowire(env: 'PAYMENT_API_URL')] private readonly string $baseUrl,
    ) {
    }

    public function authorize(string $customer, int $amountCents, string $reference): string
    {
        return $this->tracer->span('PaymentGateway::authorize', function () use ($customer, $amountCents, $reference): string {
            $response = $this->httpClient->request('POST', $this->baseUrl.'/authorize', [
                'json' => ['customer' => $customer, 'amount_cents' => $amountCents, 'currency' => 'EUR', 'reference' => $reference],
                'timeout' => 5,
            ]);
            $data = $response->toArray();

            if ('authorized' !== ($data['status'] ?? null)) {
                throw new PaymentDeclined((string) ($data['reason'] ?? 'declined'));
            }

            return (string) $data['authorization_id'];
        }, context: ['component' => 'payment', 'payment' => ['amount_cents' => $amountCents, 'currency' => 'EUR']]);
    }
}
