<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stands in for the payment provider in tests (no network). Records every authorization request.
 */
final class MockPaymentApi
{
    /** @var list<array{url: string, headers: array<string, mixed>, body: array<string, mixed>}> */
    public static array $requests = [];

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $body = json_decode((string) ($options['body'] ?? '{}'), true) ?: [];
        $headers = [];
        foreach ($options['headers'] ?? [] as $line) {
            [$name, $value] = array_map('trim', explode(':', (string) $line, 2)) + [1 => ''];
            $headers[strtolower($name)] = $value;
        }
        self::$requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

        return new MockResponse((string) json_encode(['status' => 'authorized', 'authorization_id' => 'auth_test'.\count(self::$requests)]), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    public static function reset(): void
    {
        self::$requests = [];
    }
}
