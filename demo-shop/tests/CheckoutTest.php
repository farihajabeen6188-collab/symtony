<?php

declare(strict_types=1);

namespace App\Tests;

use App\Catalog\ProductRepository;
use App\Catalog\ShopSchema;
use App\Tests\Support\MockPaymentApi;

/**
 * Documents the intended checkout behaviour.
 */
final class CheckoutTest extends ShopTestCase
{
    public function testCheckoutWithStockPlacesAnOrderAndAuthorizesPayment(): void
    {
        $order = $this->checkout('demo-customer-ada', ShopSchema::SKU, 2);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(25800, $order['total_cents']);
        self::assertStringStartsWith('auth_test', $order['payment_authorization']);
        self::assertSame(1, static::getContainer()->get(ProductRepository::class)->stock(ShopSchema::SKU));
        self::assertCount(1, MockPaymentApi::$requests);
    }

    public function testCheckoutBeyondKnownStockIsRejectedWithoutCharging(): void
    {
        $this->checkout('demo-customer-ada', ShopSchema::SKU, 5);

        self::assertResponseStatusCodeSame(409);
        self::assertCount(0, MockPaymentApi::$requests);
        self::assertSame(3, static::getContainer()->get(ProductRepository::class)->stock(ShopSchema::SKU));
    }

    public function testUnknownCustomerIsRejected(): void
    {
        $this->checkout('nobody', ShopSchema::SKU, 1);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownProductIs404(): void
    {
        $this->checkout('demo-customer-ada', 'SKU-0000', 1);

        self::assertResponseStatusCodeSame(404);
    }

    public function testDatabaseRefusesNegativeStock(): void
    {
        $this->expectException(\Doctrine\DBAL\Exception::class);

        static::getContainer()->get(ProductRepository::class)->decrementStock(ShopSchema::SKU, 4);
    }
}
