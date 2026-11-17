<?php

declare(strict_types=1);

namespace Symtony\Tests;

use PHPUnit\Framework\TestCase;
use Symtony\Doctrine\SqlSanitizer;
use Symtony\Trace\TraceContext;

final class TraceContextTest extends TestCase
{
    public function testParsesValidTraceparent(): void
    {
        self::assertSame(
            ['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'span_id' => '00f067aa0ba902b7'],
            TraceContext::parseTraceparent('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'),
        );
    }

    public function testRejectsInvalidTraceparents(): void
    {
        self::assertNull(TraceContext::parseTraceparent('garbage'));
        self::assertNull(TraceContext::parseTraceparent('00-00000000000000000000000000000000-00f067aa0ba902b7-01'));
        self::assertNull(TraceContext::parseTraceparent('01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7'));
    }

    public function testSpansNestAndChildrenPointToParents(): void
    {
        $ctx = new TraceContext();
        $ctx->begin('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', 'req-1');

        $root = $ctx->open('root', 'http_request');
        $child = $ctx->open('child', 'service_call');
        $detached = $ctx->open('async', 'external_http', detached: true);

        self::assertSame('00f067aa0ba902b7', $root->parentSpanId, 'Root continues the remote parent');
        self::assertSame($root->spanId, $child->parentSpanId);
        self::assertSame($child->spanId, $detached->parentSpanId);
        self::assertSame($child, $ctx->current(), 'Detached spans are not pushed');
        self::assertSame('req-1', $ctx->requestId());

        $ctx->close($root);
        self::assertTrue($child->isEnded(), 'Closing a parent closes children left open');
        self::assertNull($ctx->current());
    }

    public function testBeginResetsMuteAndServiceOverride(): void
    {
        $ctx = new TraceContext();
        $ctx->mute();
        $ctx->setService('payment-api');
        $ctx->begin();

        self::assertFalse($ctx->isMuted());
        self::assertNull($ctx->service());
        self::assertMatchesRegularExpression('/^req_[0-9a-f]{16}$/', (string) $ctx->requestId());
    }

    public function testSqlIsSanitized(): void
    {
        self::assertSame(
            "SELECT * FROM users WHERE email = ? AND id = ? AND name = ?",
            SqlSanitizer::sanitize("SELECT *\n  FROM users WHERE email = 'ann@example.com' AND id = 42 AND name = 'O''Brien'"),
        );
        self::assertSame('UPDATE product SET stock = stock - ? WHERE sku = ?', SqlSanitizer::sanitize('UPDATE product SET stock = stock - ? WHERE sku = ?'));
        self::assertSame(['operation' => 'UPDATE', 'table' => 'product'], SqlSanitizer::describe('UPDATE product SET stock = 1'));
        self::assertSame(['operation' => 'INSERT', 'table' => 'orders'], SqlSanitizer::describe('INSERT INTO orders (id) VALUES (?)'));
    }
}
