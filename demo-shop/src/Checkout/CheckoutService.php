<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Catalog\ProductNotFound;
use App\Catalog\ProductRepository;
use App\Inventory\InventoryService;
use App\Inventory\OutOfStock;
use App\Payment\PaymentGateway;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symtony\Tracer;

final class CheckoutService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly InventoryService $inventory,
        private readonly PaymentGateway $payments,
        private readonly Connection $db,
        private readonly Tracer $tracer,
        #[Autowire(service: 'monolog.logger.shop')] private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{order_id: string, sku: string, quantity: int, total_cents: int, payment_authorization: string} */
    public function checkout(string $customer, string $sku, int $quantity): array
    {
        return $this->tracer->span('CheckoutService::checkout', function () use ($customer, $sku, $quantity): array {
            $product = $this->products->find($sku) ?? throw new ProductNotFound($sku);

            $available = $this->inventory->availableStock($sku);
            if ($available < $quantity) {
                throw new OutOfStock($sku, $quantity, $available);
            }

            $orderId = Uuid::v7()->toRfc4122();
            $total = $product['price_cents'] * $quantity;
            $authorization = $this->payments->authorize($customer, $total, $orderId);

            $this->db->transactional(function () use ($orderId, $sku, $quantity, $customer, $total, $authorization): void {
                $this->db->insert('orders', [
                    'id' => $orderId,
                    'sku' => $sku,
                    'quantity' => $quantity,
                    'customer' => $customer,
                    'total_cents' => $total,
                    'payment_authorization' => $authorization,
                    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
                $this->inventory->reserve($sku, $quantity);
            });

            $this->logger->info('Order {order} placed for {sku} x{quantity}', ['order' => $orderId, 'sku' => $sku, 'quantity' => $quantity]);

            return ['order_id' => $orderId, 'sku' => $sku, 'quantity' => $quantity, 'total_cents' => $total, 'payment_authorization' => $authorization];
        }, context: ['component' => 'checkout', 'checkout' => ['sku' => $sku, 'quantity' => $quantity]]);
    }
}
