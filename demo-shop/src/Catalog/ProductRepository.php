<?php

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

final class ProductRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{sku: string, name: string, price_cents: int, stock: int}|null */
    public function find(string $sku): ?array
    {
        $row = $this->db->fetchAssociative('SELECT sku, name, price_cents, stock FROM product WHERE sku = ?', [$sku]);

        return false === $row ? null : ['sku' => (string) $row['sku'], 'name' => (string) $row['name'], 'price_cents' => (int) $row['price_cents'], 'stock' => (int) $row['stock']];
    }

    /** @return list<array{sku: string, name: string, price_cents: int, stock: int}> */
    public function all(): array
    {
        return array_map(
            static fn (array $r): array => ['sku' => (string) $r['sku'], 'name' => (string) $r['name'], 'price_cents' => (int) $r['price_cents'], 'stock' => (int) $r['stock']],
            $this->db->fetchAllAssociative('SELECT sku, name, price_cents, stock FROM product ORDER BY sku'),
        );
    }

    public function stock(string $sku): int
    {
        $stock = $this->db->fetchOne('SELECT stock FROM product WHERE sku = ?', [$sku]);
        if (false === $stock) {
            throw new ProductNotFound($sku);
        }

        return (int) $stock;
    }

    /** Relative decrement. The CHECK constraint rejects it if stock would go negative. */
    public function decrementStock(string $sku, int $quantity): int
    {
        return (int) $this->db->executeStatement('UPDATE product SET stock = stock - ? WHERE sku = ?', [$quantity, $sku]);
    }

    /** Warehouse sync: sets the authoritative stock level directly in the database. */
    public function setStock(string $sku, int $stock): void
    {
        $this->db->executeStatement('UPDATE product SET stock = ? WHERE sku = ?', [$stock, $sku]);
    }
}
