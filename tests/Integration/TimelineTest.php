<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Timeline\Correlator;
use App\Application\Timeline\TimelineBuilder;
use App\Domain\Application\Application;
use App\Domain\Event\Event;
use App\Domain\Event\EventType;
use App\Domain\Event\Severity;
use App\Infrastructure\Ingestion\MessageNormalizer;
use App\Tests\Support\EventFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Reconstruction of the demo failure: proven links from ids, inferred context only from timing.
 */
final class TimelineTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $em;
    private Application $app;
    private \DateTimeImmutable $t0;
    /** @var array<string, Event> */
    private array $e = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->app = (new EventFactory($this->em))->application(null, 'shop');
        $this->t0 = new \DateTimeImmutable('2026-11-17 21:28:40.000000');
        $T = 'trace-checkout';

        // Inferred context (other traces, same app)
        $this->e['deploy'] = $this->ev('deploy', EventType::Deploy, 'Deploy 1.4.0', -12000, null, null, null, null, Severity::Notice, ['component' => 'deploy']);
        $this->e['sync'] = $this->ev('sync', EventType::DbQuery, 'UPDATE product', -1500, 2.0, 'job-trace', 'sp-sync', null, Severity::Info, ['component' => 'database', 'db' => ['operation' => 'UPDATE']], 'warehouse-sync');
        $this->e['noise'] = $this->ev('noise', EventType::DbQuery, 'SELECT product', -900, 1.0, 'other-trace', 'sp-noise', null, Severity::Info, ['db' => ['operation' => 'SELECT']]);
        $this->e['old'] = $this->ev('old', EventType::DbQuery, 'UPDATE product', -600000, 1.0, 'old-trace', 'sp-old', null, Severity::Info, ['db' => ['operation' => 'UPDATE']]);

        // The failing request
        $this->e['root'] = $this->ev('root', EventType::HttpRequest, 'POST /checkout', 0, 1200, $T, 'sp-root', null, Severity::Error, ['http' => ['status' => 500], 'status' => 'error']);
        $this->e['ctrl'] = $this->ev('ctrl', EventType::Controller, 'CheckoutController::__invoke', 5, 1100, $T, 'sp-ctrl', 'sp-root', Severity::Error);
        $this->e['checkout'] = $this->ev('checkout', EventType::ServiceCall, 'CheckoutService::checkout', 6, 1090, $T, 'sp-co', 'sp-ctrl', Severity::Error, ['component' => 'checkout']);
        $this->e['cache'] = $this->ev('cache', EventType::Cache, 'cache hit stock_SKU-1042', 10, 0.4, $T, 'sp-cache', 'sp-co', Severity::Info, ['cache' => ['hit' => true, 'age_seconds' => 6.1]]);
        $this->e['ext'] = $this->ev('ext', EventType::ExternalHttp, 'POST payment-api/authorize', 12, 240, $T, 'sp-ext', 'sp-co', Severity::Info, ['component' => 'external:payment-api']);
        $this->e['pay'] = $this->ev('pay', EventType::HttpRequest, 'POST /payment-api/authorize', 90, 80, $T, 'sp-pay', 'sp-ext', Severity::Info, [], 'payment-api');
        $this->e['update'] = $this->ev('update', EventType::DbQuery, 'UPDATE product', 1000, 0.3, $T, 'sp-upd', 'sp-co', Severity::Error, ['db' => ['operation' => 'UPDATE', 'error' => 'CHECK constraint failed']]);
        $this->e['rollback'] = $this->ev('rollback', EventType::Transaction, 'ROLLBACK', 1001, null, $T, 'sp-rb', 'sp-co', Severity::Warning);
        $this->e['exception'] = $this->ev('exception', EventType::Exception, 'DriverException', 1002, null, $T, 'sp-ex', 'sp-ctrl', Severity::Error);
        $this->e['response'] = $this->ev('response', EventType::HttpResponse, 'HTTP 500', 1150, null, $T, 'sp-resp', 'sp-root', Severity::Error);

        // Another workspace's event at the same moment must never appear.
        $foreign = (new EventFactory($this->em))->application(null, 'foreign');
        $this->persist(new Event($foreign, $this->t0->modify('+500 milliseconds'), Severity::Error, EventType::Exception, 'shop', 'secret', 'secret', 'test', traceId: $T, spanId: 'sp-foreign'));
        $this->em->flush();
    }

    public function testReconstructsTheTraceWithProvenAndInferredParts(): void
    {
        $timeline = static::getContainer()->get(TimelineBuilder::class)->forEvent($this->e['exception']);
        $ids = array_map(static fn ($i): string => $i->id, $timeline->items);
        $byId = array_flip($ids);

        // Proven members: every event of the trace, in time order; nothing from the other workspace.
        foreach (['root', 'ctrl', 'checkout', 'cache', 'ext', 'pay', 'update', 'rollback', 'exception', 'response'] as $k) {
            self::assertArrayHasKey($this->id($k), $byId, "$k must be in the timeline");
            self::assertTrue($timeline->item($this->id($k))->isProven());
        }
        self::assertStringNotContainsString('secret', json_encode($timeline->toArray()));
        self::assertLessThan($byId[$this->id('update')], $byId[$this->id('ext')], 'Ordered by time');

        // Inferred context: the latest deploy and the nearby write, labelled as inferred. Old writes and reads are out.
        $sync = $timeline->item($this->id('sync'));
        self::assertNotNull($sync);
        self::assertFalse($sync->isProven());
        self::assertStringContainsString('inferred from timing', $sync->reason);
        $deploy = $timeline->item($this->id('deploy'));
        self::assertNotNull($deploy);
        self::assertStringContainsString('Preceded by deploy', $deploy->reason);
        self::assertNull($timeline->item($this->id('noise')), 'Reads from other traces are not notable context');
        self::assertNull($timeline->item($this->id('old')), 'Outside the context window');

        // Links: parent/child proven, cross-service hop proven, timing links never proven.
        $links = $timeline->links;
        $find = fn (string $from, string $to): ?array => array_values(array_filter($links, fn (array $l): bool => $l['from'] === $this->id($from) && $l['to'] === $this->id($to)))[0] ?? null;
        self::assertTrue($find('root', 'ctrl')['proven']);
        self::assertSame('cross_service', $find('ext', 'pay')['kind']);
        self::assertTrue($find('ext', 'pay')['proven']);
        foreach ($links as $l) {
            if ('inferred' === $l['kind']) {
                self::assertFalse($l['proven'], 'Time proximity is never proven');
            }
        }

        // First failure: the failing leaf (the UPDATE), not its failing parents or the 500 response.
        self::assertSame($this->id('update'), $timeline->firstFailureId);

        // Lanes are service/component and the payment hop gets its own service lane.
        $lanes = array_column($timeline->lanes, 'key');
        self::assertContains('payment-api/http', $lanes);
        self::assertContains('shop/database', $lanes);
        self::assertContains('warehouse-sync/database', $lanes);
        self::assertSame('shop/checkout', $timeline->item($this->id('rollback'))->lane, 'Point events sit on the lane of their parent span');
        self::assertSame('shop/exception', $timeline->item($this->id('exception'))->lane, 'Exceptions get their own lane so they stand out');
    }

    public function testTruncationIsReported(): void
    {
        $builder = new TimelineBuilder($this->em, new Correlator(), 4);
        $timeline = $builder->forEvent($this->e['exception']);

        self::assertTrue($timeline->truncated);
        self::assertSame(10, $timeline->total);
        self::assertCount(4, array_filter($timeline->items, static fn ($i): bool => $i->isProven()));
    }

    private function id(string $key): string
    {
        return $this->e[$key]->getId()->toRfc4122();
    }

    /** @param array<string, mixed> $context */
    private function ev(string $key, EventType $type, string $name, float $offsetMs, ?float $duration, ?string $trace, ?string $span, ?string $parent, Severity $severity, array $context = [], string $service = 'shop'): Event
    {
        $at = $this->t0->modify(\sprintf('%+d microseconds', (int) round($offsetMs * 1000)));
        $event = new Event($this->app, $at, $severity, $type, $service, $name, (new MessageNormalizer())->normalize($name), 'test',
            release: EventType::Deploy === $type ? '1.4.0' : null, name: $name, traceId: $trace, spanId: $span, parentSpanId: $parent,
            durationMs: $duration, exceptionClass: EventType::Exception === $type ? 'Doctrine\DBAL\Exception\DriverException' : null,
            exceptionMessage: EventType::Exception === $type ? 'CHECK constraint failed: stock_non_negative' : null, context: $context);

        return $this->persist($event);
    }

    private function persist(Event $event): Event
    {
        $this->em->persist($event);

        return $event;
    }
}
