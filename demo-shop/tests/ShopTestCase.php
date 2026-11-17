<?php

declare(strict_types=1);

namespace App\Tests;

use App\Catalog\ShopSchema;
use App\Tests\Support\MockPaymentApi;
use App\Tests\Support\RecordingTransport;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ShopTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        @mkdir(static::getContainer()->getParameter('kernel.project_dir').'/var/data', 0777, true);
        static::getContainer()->get(ShopSchema::class)->install();
        static::getContainer()->get('cache.app')->clear();
        // Drop events produced by the fixture setup itself.
        static::getContainer()->get('symtony.buffer')->flush();
        RecordingTransport::reset();
        MockPaymentApi::reset();
    }

    /** @return array<string, mixed> */
    protected function checkout(string $customerToken, string $sku, int $quantity): array
    {
        $this->client->request('POST', '/checkout', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$customerToken,
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['sku' => $sku, 'quantity' => $quantity]));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
