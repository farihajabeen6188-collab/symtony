<?php

declare(strict_types=1);

namespace App\Tests\Unit\Timeline;

use App\Application\Incident\Fingerprinter;
use App\Application\Timeline\Correlator;
use App\Domain\Application\Application;
use App\Domain\Application\Environment;
use App\Domain\Application\Framework;
use App\Domain\Application\IngestionMethod;
use App\Domain\Event\Event;
use App\Domain\Event\EventType;
use App\Domain\Event\Severity;
use App\Domain\Workspace\Workspace;
use PHPUnit\Framework\TestCase;

final class CorrelatorTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(new Workspace('W', 'w'), 'Shop', 'shop', Environment::Production, Framework::Symfony, IngestionMethod::HttpJson);
    }

    public function testProximityIsAlwaysLabelledInferred(): void
    {
        $start = new \DateTimeImmutable('2026-11-17 12:00:00');
        $write = $this->event(EventType::DbQuery, $start->modify('-2 seconds'), ['db' => ['operation' => 'UPDATE']]);
        $deploy = $this->event(EventType::Deploy, $start->modify('-10 minutes'));
        $read = $this->event(EventType::DbQuery, $start->modify('-1 second'), ['db' => ['operation' => 'SELECT']]);

        $out = (new Correlator())->proximity([$write, $deploy, $read], $start, $start->modify('+1 second'));

        self::assertCount(2, $out, 'Reads are not notable');
        self::assertStringContainsString('2.0 s before', $out[0]['reason']);
        self::assertStringContainsString('inferred from timing', $out[0]['reason']);
        self::assertStringContainsString('Preceded by deploy', $out[1]['reason']);
    }

    public function testLaneDerivation(): void
    {
        self::assertSame('shop/database', Correlator::lane($this->event(EventType::DbQuery, new \DateTimeImmutable()))['key']);
        self::assertSame('shop/external', Correlator::lane($this->event(EventType::ExternalHttp, new \DateTimeImmutable(), ['component' => 'external:payments.test']))['key']);
        self::assertSame('shop/inventory', Correlator::lane($this->event(EventType::ServiceCall, new \DateTimeImmutable(), ['component' => 'inventory']))['key']);
    }

    public function testFingerprintIgnoresInstrumentationFramesAndClosureLineNumbers(): void
    {
        $fp = new Fingerprinter();
        $a = $this->exception([
            ['class' => 'App\Repo', 'function' => 'decrement', 'in_app' => true],
            ['class' => 'App\Inventory', 'function' => '{closure:App\Inventory::reserve():38}', 'in_app' => true],
            ['class' => 'Symtony\Tracer', 'function' => 'span', 'in_app' => true],
            ['class' => 'App\Checkout', 'function' => 'run', 'in_app' => true],
        ]);
        $b = $this->exception([
            ['class' => 'App\Repo', 'function' => 'decrement', 'in_app' => true],
            ['class' => 'App\Inventory', 'function' => '{closure:App\Inventory::reserve():41}', 'in_app' => true],
            ['class' => 'Symtony\Tracer', 'function' => 'span', 'in_app' => false],
            ['class' => 'App\Checkout', 'function' => 'run', 'in_app' => true],
        ]);

        self::assertSame($fp->fingerprint($a), $fp->fingerprint($b));
        self::assertSame(['App\Repo::decrement', 'App\Inventory::{closure:App\Inventory::reserve()}', 'App\Checkout::run'], $fp->topFrames($a));
    }

    /** @param array<string, mixed> $context */
    private function event(EventType $type, \DateTimeImmutable $at, array $context = []): Event
    {
        return new Event($this->app, $at, Severity::Info, $type, 'shop', 'x', 'x', 'test', release: '1.4.0', context: $context);
    }

    /** @param list<array<string, mixed>> $frames */
    private function exception(array $frames): Event
    {
        return new Event($this->app, new \DateTimeImmutable(), Severity::Error, EventType::Exception, 'shop', 'boom', 'boom', 'test', exceptionClass: 'App\E', exceptionMessage: 'boom', stackTrace: $frames);
    }
}
