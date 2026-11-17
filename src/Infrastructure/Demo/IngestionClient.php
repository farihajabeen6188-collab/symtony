<?php

declare(strict_types=1);

namespace App\Infrastructure\Demo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends events to SYMTONY's own public ingestion API over HTTP, exactly like an external client.
 */
final class IngestionClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'SYMTONY_INTERNAL_URL')] private readonly string $baseUrl,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return array{accepted: int, duplicates: int, rejected: int}
     */
    public function send(string $token, array $events, string $source = 'demo'): array
    {
        $totals = ['accepted' => 0, 'duplicates' => 0, 'rejected' => 0];
        foreach (array_chunk($events, 500) as $batch) {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/api/v1/events', [
                'headers' => ['Authorization' => 'Bearer '.$token, 'X-Symtony-Source' => $source],
                'json' => ['events' => $batch],
                'timeout' => 60,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
            if (202 !== $status) {
                throw new \RuntimeException(\sprintf('Ingestion API answered %d: %s', $status, json_encode($body)));
            }
            $totals['accepted'] += (int) $body['accepted'];
            $totals['duplicates'] += (int) $body['duplicates'];
            $totals['rejected'] += \count($body['rejected']);
        }

        return $totals;
    }
}
