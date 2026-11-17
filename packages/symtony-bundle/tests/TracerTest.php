<?php

declare(strict_types=1);

namespace Symtony\Tests;

use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symtony\Bridge\Monolog\SymtonyHandler;
use Symtony\HttpClient\TracingHttpClient;
use Symtony\Trace\TraceContext;
use Symtony\Tracer;
use Symtony\Transport\EventBuffer;
use Symtony\Transport\HttpTransport;
use Symtony\Transport\TransportInterface;

final class TracerTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];
    private Tracer $tracer;

    protected function setUp(): void
    {
        $transport = new class($this->sent) implements TransportInterface {
            /** @param list<array<string, mixed>> $sink */
            public function __construct(private array &$sink)
            {
            }

            public function send(array $events): bool
            {
                array_push($this->sink, ...$events);

                return true;
            }
        };
        $this->tracer = new Tracer(new TraceContext(), new EventBuffer($transport), 'shop', '1.4.0', null, '/srv/app');
        $this->tracer->context()->begin(null, 'req-7');
    }

    public function testSpanRecordsErrorAndRethrows(): void
    {
        try {
            $this->tracer->span('InventoryService::reserve', static function (): never {
                throw new \DomainException('constraint failed');
            });
            self::fail('Exception must propagate');
        } catch (\DomainException) {
        }
        $this->tracer->flush();

        $span = $this->sent[0];
        self::assertSame('service_call', $span['type']);
        self::assertSame('error', $span['severity']);
        self::assertSame('error', $span['context']['status']);
        self::assertSame(\DomainException::class, $span['context']['error']['class']);
        self::assertSame('shop', $span['service']);
        self::assertSame('1.4.0', $span['release']);
        self::assertSame('req-7', $span['request_id']);
    }

    public function testExceptionFramesPairEachFunctionWithItsOwnLine(): void
    {
        $e = $this->throwFromHelper();
        $this->tracer->exception($e);
        $this->tracer->exception($e); // captured once
        $this->tracer->flush();

        self::assertCount(1, $this->sent);
        $frames = $this->sent[0]['exception']['frames'];
        self::assertSame('throwFromHelper', $frames[0]['function'], 'Top frame is the function that threw');
        self::assertSame($e->getLine(), $frames[0]['line']);
        self::assertSame(self::class, $frames[0]['class']);
        self::assertSame('testExceptionFramesPairEachFunctionWithItsOwnLine', $frames[1]['function']);
        self::assertTrue($this->tracer->isCaptured($e));
    }

    public function testMutedContextRecordsNothing(): void
    {
        $this->tracer->context()->mute();
        $this->tracer->span('ignored', static fn (): int => 1);
        $this->tracer->event('log', 'ignored');
        $this->tracer->exception(new \RuntimeException('ignored'));
        $this->tracer->flush();

        self::assertSame([], $this->sent);
    }

    public function testHttpClientPropagatesTraceparentAndRecordsStatus(): void
    {
        $seen = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options['normalized_headers']['traceparent'][0] ?? '';

            return new MockResponse('{"ok":true}', ['http_code' => 502]);
        });
        $client = new TracingHttpClient($mock, $this->tracer);
        $client->request('POST', 'https://payments.test:8443/authorize')->getContent(false);
        $this->tracer->flush();

        $span = $this->sent[0];
        self::assertSame('external_http', $span['type']);
        self::assertSame('POST payments.test:8443/authorize', $span['name']);
        self::assertSame(502, $span['context']['external']['status']);
        self::assertSame('error', $span['severity'], '5xx marks the span as failed');
        self::assertSame('traceparent: 00-'.$this->tracer->context()->traceId().'-'.$span['span_id'].'-01', $seen);
    }

    public function testMonologHandlerLinksRecordsToTheTrace(): void
    {
        $handler = new SymtonyHandler(service: 'shop', level: Level::Warning, buffer: $this->tracer->buffer(), tracer: $this->tracer);
        $logger = new Logger('shop', [$handler]);
        $span = $this->tracer->start('CheckoutService::checkout');
        $logger->info('ignored below threshold');
        $logger->warning('Payment slow: {ms} ms', ['ms' => 950, 'customer' => new \ArrayObject()]);
        $this->tracer->end($span);
        $this->tracer->flush();

        $log = array_values(array_filter($this->sent, static fn (array $e): bool => 'log' === $e['type']));
        self::assertCount(1, $log);
        self::assertSame('warning', $log[0]['severity']);
        self::assertSame($span->spanId, $log[0]['parent_span_id']);
        self::assertSame($this->tracer->context()->traceId(), $log[0]['trace_id']);
        self::assertSame(\ArrayObject::class, $log[0]['context']['customer']);
    }

    public function testTransportNeverThrowsWhenSymtonyIsDown(): void
    {
        $reasons = [];
        $transport = new HttpTransport('http://127.0.0.1:9/api/v1/events', 'sym_test_x', 0.5, onError: static function (string $r) use (&$reasons): void {
            $reasons[] = $r;
        });

        self::assertFalse($transport->send([['timestamp' => '2026-11-17T10:00:00Z', 'message' => 'x']]));
        self::assertCount(1, $reasons);
        self::assertNotNull($transport->lastError());
    }

    private function throwFromHelper(): \LogicException
    {
        try {
            throw new \LogicException('boom');
        } catch (\LogicException $e) {
            return $e;
        }
    }
}
