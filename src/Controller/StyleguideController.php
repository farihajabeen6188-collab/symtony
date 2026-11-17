<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StyleguideController extends AbstractController
{
    #[Route('/styleguide', name: 'app_styleguide')]
    public function __invoke(): Response
    {
        // Static, clearly-labelled sample values: this page documents components, it is not product data.
        $series = [];
        $start = (new \DateTimeImmutable('2026-01-01 00:00:00'))->getTimestamp();
        foreach ([3, 4, 2, 6, 5, 9, 14, 8, 6, 4, 3, 5, 7, 4, 3, 2] as $i => $v) {
            $series[] = [$start + $i * 1800, $v];
        }

        return $this->render('styleguide/index.html.twig', [
            'sample_series' => $series,
            'sample_php' => <<<'PHP'
                public function testCheckoutRejectsStaleStock(): void
                {
                    $product = $this->product(stock: 1);
                    $this->cache->prime($product, stock: 5); // stale value

                    $response = $this->checkout($product, quantity: 2);

                    self::assertSame(409, $response->getStatusCode());
                }
                PHP,
            'sample_json' => json_encode(['type' => 'exception', 'service' => 'shop', 'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'context' => ['sku' => 'SKU-1042', 'password' => '[redacted]']], \JSON_PRETTY_PRINT),
        ]);
    }
}
