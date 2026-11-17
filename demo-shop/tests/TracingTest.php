<?php

declare(strict_types=1);

namespace App\Tests;

use App\Catalog\ShopSchema;
use App\Tests\Support\MockPaymentApi;
use App\Tests\Support\RecordingTransport;

/**
 * Integration test of symtony/symfony-bundle inside a real app: what a checkout reports to SYMTONY.
 */
final class TracingTest extends ShopTestCase
{
    public function testCheckoutProducesOneLinkedTrace(): void
    {
        $this->checkout('demo-customer-ada', ShopSchema::SKU, 1);
        self::assertResponseStatusCodeSame(201);

        $events = RecordingTransport::$events;
        self::assertNotEmpty($events, 'The bundle must report the request');
        $traceIds = array_unique(array_column($events, 'trace_id'));
        self::assertCount(1, $traceIds, 'Every event of one request shares one trace id');
        $trace = $traceIds[0];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $trace);

        $byName = [];
        foreach ($events as $e) {
            $byName[$e['name']] = $e;
        }
        $root = $byName['POST /checkout'];
        self::assertSame('http_request', $root['type']);
        self::assertNull($root['parent_span_id'] ?? null, 'The request span is the trace root');
        self::assertSame(201, $root['context']['http']['status']);

        $controller = $byName['App\Controller\CheckoutController::__invoke'];
        self::assertSame($root['span_id'], $controller['parent_span_id']);
        $checkout = $byName['CheckoutService::checkout'];
        self::assertSame($controller['span_id'], $checkout['parent_span_id']);
        $reserve = $byName['InventoryService::reserve'];

        // DB spans: sanitized statement, never parameter values.
        $update = array_values(array_filter($events, static fn (array $e): bool => 'db_query' === $e['type'] && 'UPDATE' === $e['context']['db']['operation']))[0];
        self::assertSame($reserve['span_id'], $update['parent_span_id']);
        self::assertStringStartsWith('UPDATE product SET stock = stock - ? WHERE sku = ?', $update['context']['db']['statement']);
        self::assertStringNotContainsString('SKU-1042', json_encode($update['context']['db']));

        // Cache span with hit/miss, transaction events, outgoing HTTP span.
        self::assertContains('cache', array_column($events, 'type'));
        self::assertContains('BEGIN', array_column($events, 'name'));
        self::assertContains('COMMIT', array_column($events, 'name'));
        $external = array_values(array_filter($events, static fn (array $e): bool => 'external_http' === $e['type']))[0];
        self::assertSame(200, $external['context']['external']['status']);

        // W3C trace context propagated to the payment provider.
        $sent = MockPaymentApi::$requests[0]['headers']['traceparent'] ?? '';
        self::assertSame('00-'.$trace.'-'.$external['span_id'].'-01', $sent);

        // Start timestamps keep microseconds and durations are reported.
        self::assertMatchesRegularExpression('/\.\d{6}Z$/', $root['timestamp']);
        self::assertGreaterThan(0, $root['duration_ms']);
    }

    public function testRequestIdIsReturnedAndReused(): void
    {
        $this->client->request('GET', '/products/'.ShopSchema::SKU, [], [], ['HTTP_X_REQUEST_ID' => 'req-from-gateway-42']);

        self::assertResponseIsSuccessful();
        self::assertSame('req-from-gateway-42', $this->client->getResponse()->headers->get('X-Request-Id'));
        self::assertSame(['req-from-gateway-42'], array_values(array_unique(array_column(RecordingTransport::$events, 'request_id'))));
    }

    public function testIncomingTraceparentIsContinued(): void
    {
        $this->client->request('GET', '/products/'.ShopSchema::SKU, [], [], ['HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);

        $root = array_values(array_filter(RecordingTransport::$events, static fn (array $e): bool => 'http_request' === $e['type']))[0];
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $root['trace_id']);
        self::assertSame('00f067aa0ba902b7', $root['parent_span_id']);
    }
}
