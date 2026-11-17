<?php

declare(strict_types=1);

namespace App\Tests;

use App\Catalog\ProductRepository;
use App\Catalog\ShopSchema;
use App\Inventory\InventoryService;
use App\Tests\Support\RecordingTransport;

/**
 * What a failed request reports. Holds for the buggy and the fixed shop alike: whatever fails,
 * the request root span must be reported so the timeline has a root.
 */
final class FailureTracingTest extends ShopTestCase
{
    public function testUnhandledFailureStillReportsTheRequestRoot(): void
    {
        $this->client->catchExceptions(true);
        // Force an unhandled exception path regardless of the inventory implementation.
        static::getContainer()->get(ProductRepository::class)->setStock(ShopSchema::SKU, 0);
        $this->client->request('POST', '/checkout', [], [], ['HTTP_AUTHORIZATION' => 'Bearer demo-customer-ada', 'CONTENT_TYPE' => 'application/json'], '{not json');

        $status = $this->client->getResponse()->getStatusCode();
        self::assertGreaterThanOrEqual(400, $status);

        $roots = array_values(array_filter(RecordingTransport::$events, static fn (array $e): bool => 'http_request' === $e['type']));
        self::assertCount(1, $roots, 'The request root span must be reported even when the request fails');
        self::assertSame($status, $roots[0]['context']['http']['status']);
        $responses = array_values(array_filter(RecordingTransport::$events, static fn (array $e): bool => 'http_response' === $e['type']));
        self::assertSame($roots[0]['span_id'], $responses[0]['parent_span_id']);
    }

    public function testStaleCacheFailureReportsRootAndException(): void
    {
        $container = static::getContainer();
        $container->get(InventoryService::class)->availableStock(ShopSchema::SKU);
        $container->get(ProductRepository::class)->setStock(ShopSchema::SKU, 0);
        $container->get('symtony.buffer')->flush();
        RecordingTransport::reset();

        $this->checkout('demo-customer-linus', ShopSchema::SKU, 1);
        $status = $this->client->getResponse()->getStatusCode();

        $types = array_count_values(array_column(RecordingTransport::$events, 'type'));
        self::assertSame(1, $types['http_request'] ?? 0, 'Exactly one root span (status '.$status.')');
        self::assertCount(1, array_unique(array_column(RecordingTransport::$events, 'trace_id')));
    }
}
