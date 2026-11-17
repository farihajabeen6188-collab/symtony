<?php

declare(strict_types=1);

namespace App\Tests\Unit\Incident;

use App\Application\Incident\Fingerprinter;
use App\Domain\Application\Application;
use App\Domain\Application\Environment;
use App\Domain\Application\Framework;
use App\Domain\Application\IngestionMethod;
use App\Domain\Event\Event;
use App\Domain\Event\EventType;
use App\Domain\Event\Severity;
use App\Domain\Incident\IncidentSeverity;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Ingestion\MessageNormalizer;
use PHPUnit\Framework\TestCase;

final class FingerprinterTest extends TestCase
{
    private Fingerprinter $fp;
    private Application $app;

    protected function setUp(): void
    {
        $this->fp = new Fingerprinter();
        $this->app = new Application(new Workspace('W', 'w'), 'Shop', 'shop', Environment::Production, Framework::Symfony, IngestionMethod::HttpJson);
    }

    public function testStableAcrossIdsAndLineNumbers(): void
    {
        $a = $this->event('Order 1042 failed: stock for SKU-1 is -1', [['class' => 'App\Inv', 'function' => 'reserve', 'line' => 58, 'in_app' => true]]);
        $b = $this->event('Order 9981 failed: stock for SKU-1 is -3', [['class' => 'App\Inv', 'function' => 'reserve', 'line' => 61, 'in_app' => true]]);

        self::assertSame($this->fp->fingerprint($a), $this->fp->fingerprint($b));
    }

    public function testDifferentFailuresDiffer(): void
    {
        $base = $this->event('Timeout', [['class' => 'App\A', 'function' => 'x', 'in_app' => true]]);
        $otherClass = $this->event('Timeout', [['class' => 'App\A', 'function' => 'x', 'in_app' => true]], 'App\OtherException');
        $otherFrame = $this->event('Timeout', [['class' => 'App\B', 'function' => 'y', 'in_app' => true]]);
        $otherService = $this->event('Timeout', [['class' => 'App\A', 'function' => 'x', 'in_app' => true]], service: 'billing');

        $fps = array_map($this->fp->fingerprint(...), [$base, $otherClass, $otherFrame, $otherService]);
        self::assertCount(4, array_unique($fps));
    }

    public function testVendorFramesDoNotAffectTheFingerprint(): void
    {
        $a = $this->event('X', [['class' => 'Vendor\Lib', 'function' => 'a', 'in_app' => false], ['class' => 'App\A', 'function' => 'x', 'in_app' => true]]);
        $b = $this->event('X', [['class' => 'Vendor\Lib', 'function' => 'b', 'in_app' => false], ['class' => 'App\A', 'function' => 'x', 'in_app' => true]]);

        self::assertSame($this->fp->fingerprint($a), $this->fp->fingerprint($b));
    }

    public function testOnlyExceptionsAndWarningLogsAreCandidates(): void
    {
        self::assertTrue($this->fp->isCandidate($this->event('x', [])));
        self::assertFalse($this->fp->isCandidate($this->span(EventType::DbQuery, Severity::Error)), 'Error spans are evidence, not incidents');
        self::assertFalse($this->fp->isCandidate($this->span(EventType::HttpResponse, Severity::Error)));
        self::assertTrue($this->fp->isCandidate($this->span(EventType::Log, Severity::Warning)));
        self::assertFalse($this->fp->isCandidate($this->span(EventType::Log, Severity::Info)));
    }

    public function testTitleDropsGenericWrapperPrefix(): void
    {
        $e = $this->event('An exception occurred while executing a query: SQLSTATE[23000]: CHECK constraint failed: stock_non_negative', [], 'Doctrine\DBAL\Exception\DriverException');

        self::assertSame('DriverException: SQLSTATE[23000]: CHECK constraint failed: stock_non_negative', $this->fp->title($e));
    }

    public function testSeverityDerivation(): void
    {
        self::assertSame(IncidentSeverity::Critical, IncidentSeverity::fromEvent(Severity::Critical, true, 500));
        self::assertSame(IncidentSeverity::High, IncidentSeverity::fromEvent(Severity::Error, true, null));
        self::assertSame(IncidentSeverity::Medium, IncidentSeverity::fromEvent(Severity::Error, false, null));
        self::assertSame(IncidentSeverity::Low, IncidentSeverity::fromEvent(Severity::Warning, false, null));
    }

    /** @param list<array<string, mixed>> $frames */
    private function event(string $message, array $frames, string $class = 'App\StockException', string $service = 'shop'): Event
    {
        return new Event($this->app, new \DateTimeImmutable(), Severity::Error, EventType::Exception, $service, $message, (new MessageNormalizer())->normalize($message), 'test', exceptionClass: $class, exceptionMessage: $message, stackTrace: $frames);
    }

    private function span(EventType $type, Severity $severity): Event
    {
        return new Event($this->app, new \DateTimeImmutable(), $severity, $type, 'shop', 'x', 'x', 'test');
    }
}
