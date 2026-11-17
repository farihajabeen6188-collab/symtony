<?php

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * Creates and seeds the shop database. Stock can never go negative: the database enforces it.
 */
final class ShopSchema
{
    public const SKU = 'SKU-1042';

    public function __construct(private readonly Connection $db)
    {
    }

    public function install(): void
    {
        $this->db->executeStatement('DROP TABLE IF EXISTS orders');
        $this->db->executeStatement('DROP TABLE IF EXISTS product');
        $this->db->executeStatement(<<<'SQL'
            CREATE TABLE product (
                sku VARCHAR(32) PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                price_cents INTEGER NOT NULL,
                stock INTEGER NOT NULL,
                CONSTRAINT stock_non_negative CHECK (stock >= 0)
            )
            SQL);
        $this->db->executeStatement(<<<'SQL'
            CREATE TABLE orders (
                id VARCHAR(36) PRIMARY KEY,
                sku VARCHAR(32) NOT NULL REFERENCES product (sku),
                quantity INTEGER NOT NULL,
                customer VARCHAR(64) NOT NULL,
                total_cents INTEGER NOT NULL,
                payment_authorization VARCHAR(64) NOT NULL,
                created_at VARCHAR(32) NOT NULL
            )
            SQL);

        foreach ([
            [self::SKU, 'Trail Runner GTX', 12900, 3],
            ['SKU-2210', 'Merino Hiking Socks', 1900, 40],
            ['SKU-3305', 'Ultralight Rain Shell', 18900, 12],
        ] as [$sku, $name, $price, $stock]) {
            $this->db->insert('product', ['sku' => $sku, 'name' => $name, 'price_cents' => $price, 'stock' => $stock]);
        }
    }
}
