<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Domain\Event\EventType;
use App\Domain\Event\Severity;
use App\Infrastructure\Ingestion\MessageNormalizer;
use App\Infrastructure\Ingestion\PayloadParser;
use App\Infrastructure\Ingestion\TokenGenerator;
use App\Domain\Application\Environment;
use PHPUnit\Framework\TestCase;

final class PayloadParserTest extends TestCase
{
    private PayloadParser $parser;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->parser = new PayloadParser();
        $this->now = new \DateTimeImmutable('2026-11-17T14:05:00Z');
    }

    public function testParsesAFullExceptionEvent(): void
    {
        $result = $this->parser->parse([
            'id' => 'evt-1',
            'timestamp' => '2026-11-17T14:02:31.482913Z',
            'level' => 'ERROR',
            'service' => 'inventory',
            'message' => 'Stock update failed',
            'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
            'span_id' => '00f067aa0ba902b7',
            'duration_ms' => 12.5,
            'exception' => [
                'class' => 'Doctrine\DBAL\Exception\DriverException',
                'message' => 'new row violates check constraint "stock_non_negative"',
                'frames' => [
                    ['class' => 'App\Inventory\InventoryService', 'function' => 'reserve', 'file' => '/app/src/Inventory/InventoryService.php', 'line' => 58],
                    ['class' => 'Doctrine\DBAL\Connection', 'function' => 'executeStatement', 'file' => '/app/vendor/doctrine/dbal/src/Connection.php', 'line' => 1203],
                ],
            ],
            'db' => ['operation' => 'UPDATE', 'table' => 'product'],
        ], $this->now);

        self::assertSame([], $result['errors']);
        $d = $result['draft'];
        self::assertNotNull($d);
        self::assertSame(Severity::Error, $d->severity);
        self::assertSame(EventType::Exception, $d->type, 'An exception payload without an explicit type is an exception event');
        self::assertSame('2026-11-17 14:02:31.482913', $d->occurredAt->format('Y-m-d H:i:s.u'));
        self::assertSame('inventory', $d->service);
        self::assertSame(12.5, $d->durationMs);
        self::assertTrue($d->frames[0]['in_app']);
        self::assertFalse($d->frames[1]['in_app']);
        self::assertSame(['operation' => 'UPDATE', 'table' => 'product'], $d->context['db']);
    }

    public function testAcceptsUnixSecondsAndMilliseconds(): void
    {
        $base = $this->now->getTimestamp() - 60;
        $s = $this->parser->parse(['timestamp' => $base + 0.25, 'message' => 'x'], $this->now)['draft'];
        $ms = $this->parser->parse(['timestamp' => $base * 1000 + 250, 'message' => 'x'], $this->now)['draft'];

        self::assertNotNull($s);
        self::assertNotNull($ms);
        self::assertEquals($s->occurredAt, $ms->occurredAt);
    }

    public function testReportsEveryInvalidField(): void
    {
        $result = $this->parser->parse([
            'timestamp' => 'yesterday',
            'severity' => 'loud',
            'type' => 'mystery',
            'service' => 'bad service!',
            'duration_ms' => -4,
            'trace_id' => 'has spaces',
            'context' => 'string',
        ], $this->now);

        self::assertNull($result['draft']);
        foreach (['timestamp', 'severity', 'type', 'service', 'duration_ms', 'trace_id', 'context', 'message'] as $field) {
            self::assertArrayHasKey($field, $result['errors'], "Expected an error for $field");
        }
    }

    public function testRejectsFutureTimestampsBeyondSkew(): void
    {
        $result = $this->parser->parse(['timestamp' => '2026-11-17T15:00:00Z', 'message' => 'x'], $this->now);

        self::assertArrayHasKey('timestamp', $result['errors']);
    }

    public function testRejectsNonObjects(): void
    {
        self::assertArrayHasKey('$', $this->parser->parse([1, 2], $this->now)['errors']);
        self::assertArrayHasKey('$', $this->parser->parse('event', $this->now)['errors']);
    }

    public function testMessageNormalizerStripsVariableParts(): void
    {
        $n = new MessageNormalizer();

        self::assertSame(
            'Order {n} failed for {email} (id {uuid}) at {time}',
            $n->normalize('Order 4411 failed for ann@x.io (id 0b5c6a1e-5d2b-4f8e-9a51-2c3d4e5f6a7b) at 2026-11-17T14:02:31Z'),
        );
        self::assertSame(
            'new row violates check constraint "{str}"',
            $n->normalize('new row violates check constraint "stock_non_negative"'),
        );
        self::assertSame($n->normalize('Timeout after 30s calling 10.0.0.12'), $n->normalize('Timeout after 45s calling 10.0.0.99'));
    }

    public function testTokenFormatAndHashing(): void
    {
        $t = (new TokenGenerator())->generate(Environment::Production);

        self::assertMatchesRegularExpression('/^sym_prod_[0-9A-Za-z]{32}$/', $t['token']);
        self::assertSame(substr($t['token'], 0, 13), $t['prefix']);
        self::assertSame(hash('sha256', $t['token']), $t['hash']);
        self::assertTrue(TokenGenerator::looksValid($t['token']));
        self::assertFalse(TokenGenerator::looksValid('sym_prod_short'));
        self::assertNotSame($t['token'], (new TokenGenerator())->generate(Environment::Production)['token']);
    }
}
