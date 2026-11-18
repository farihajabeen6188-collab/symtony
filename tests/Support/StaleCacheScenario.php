<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Incident\IncidentGrouper;
use App\Domain\Application\Application;
use App\Domain\Event\Event;
use App\Domain\Event\EventType;
use App\Domain\Event\Severity;
use App\Domain\Incident\Incident;
use App\Infrastructure\Ingestion\MessageNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The demo failure as raw events: a stale cached stock level lets checkout authorize payment,
 * then the stock write violates stock_non_negative. Includes a secret that must never leak.
 */
final class StaleCacheScenario
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly Application $app)
    {
    }

    public function create(IncidentGrouper $grouper): Incident
    {
        $t0 = new \DateTimeImmutable('-2 minutes');
        $T = 'trace-checkout';
        $at = static fn (int $ms): \DateTimeImmutable => $t0->modify(\sprintf('%+d milliseconds', $ms));

        // Context from other traces: deploy, the warehouse job changing stock.
        $this->ev(EventType::Deploy, 'Deploy 1.4.0', $at(-9000), null, null, null, null, Severity::Notice, ['component' => 'deploy'], release: '1.4.0');
        $this->ev(EventType::DbQuery, 'UPDATE product', $at(-1300), 2.0, 'job', 'sync', null, Severity::Info, ['component' => 'database', 'db' => ['operation' => 'UPDATE', 'table' => 'product']], 'warehouse-sync');
        $this->ev(EventType::Log, 'Stock for SKU-1042 updated from 3 to 1', $at(-1290), null, 'job', 'sync-log', 'sync', Severity::Notice, ['component' => 'warehouse', 'inventory' => ['sku' => 'SKU-1042', 'from' => 3, 'to' => 1]], 'warehouse-sync');

        // The failing request
        $this->ev(EventType::HttpRequest, 'POST /checkout', $at(0), 1200.0, $T, 'root', null, Severity::Error, ['http' => ['status' => 500], 'status' => 'error', 'authorization' => 'Bearer topsecret-should-not-leak']);
        $this->ev(EventType::Controller, 'CheckoutController::__invoke', $at(5), 1100.0, $T, 'ctrl', 'root', Severity::Error);
        $this->ev(EventType::ServiceCall, 'CheckoutService::checkout', $at(6), 1090.0, $T, 'co', 'ctrl', Severity::Error, ['component' => 'checkout']);
        $this->ev(EventType::Cache, 'cache hit stock_SKU-1042', $at(10), 0.4, $T, 'cache', 'co', Severity::Info, ['component' => 'cache', 'cache' => ['hit' => true, 'key' => 'stock_SKU-1042', 'age_seconds' => 6.1]]);
        $this->ev(EventType::ExternalHttp, 'POST localhost/payment-api/authorize', $at(12), 240.0, $T, 'ext', 'co', Severity::Info, ['component' => 'external:localhost', 'external' => ['host' => 'localhost', 'status' => 200]]);
        $this->ev(EventType::Log, 'payment authorized', $at(200), null, $T, 'paylog', 'ext', Severity::Notice, ['component' => 'payment-api', 'payment' => ['authorization_id' => 'auth_9f3a1c', 'amount_cents' => 12900]], 'payment-api');
        $this->ev(EventType::DbQuery, 'UPDATE product', $at(1000), 0.3, $T, 'upd', 'co', Severity::Error, ['component' => 'database', 'db' => ['operation' => 'UPDATE', 'table' => 'product', 'statement' => 'UPDATE product SET stock = stock - ? WHERE sku = ?', 'error' => 'SQLSTATE[23000]: CHECK constraint failed: stock_non_negative']]);
        $this->ev(EventType::Transaction, 'ROLLBACK', $at(1001), null, $T, 'rb', 'co', Severity::Warning, ['component' => 'database']);
        $exception = $this->ev(EventType::Exception, 'DriverException', $at(1002), null, $T, 'ex', 'ctrl', Severity::Error, ['component' => 'exception'], exception: 'Doctrine\DBAL\Exception\DriverException');
        $this->ev(EventType::HttpResponse, 'HTTP 500', $at(1150), null, $T, 'resp', 'root', Severity::Error, ['http' => ['status' => 500]]);
        $this->em->flush();

        $grouper->group([$exception->getId()->toRfc4122()]);

        $incident = $this->em->getRepository(Incident::class)->findOneBy(['application' => $this->app]);
        \assert($incident instanceof Incident);

        return $incident;
    }

    /** @param array<string, mixed> $context */
    private function ev(EventType $type, string $name, \DateTimeImmutable $at, ?float $duration, ?string $trace, ?string $span, ?string $parent, Severity $severity, array $context = [], string $service = 'shop', ?string $release = null, ?string $exception = null): Event
    {
        $e = new Event($this->app, $at, $severity, $type, $service, $name, (new MessageNormalizer())->normalize($name), 'test',
            release: $release, name: $name, traceId: $trace, spanId: $span, parentSpanId: $parent, durationMs: $duration,
            exceptionClass: $exception, exceptionMessage: null !== $exception ? 'An exception occurred while executing a query: SQLSTATE[23000]: CHECK constraint failed: stock_non_negative' : null,
            stackTrace: null !== $exception ? [['class' => 'App\Catalog\ProductRepository', 'function' => 'decrementStock', 'in_app' => true]] : null,
            context: $context);
        $this->em->persist($e);

        return $e;
    }
}
