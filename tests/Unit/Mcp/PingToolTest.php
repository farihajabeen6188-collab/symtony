<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\PingTool;
use PHPUnit\Framework\TestCase;

final class PingToolTest extends TestCase
{
    public function testReturnsOkWithServerTime(): void
    {
        $result = (new PingTool())();

        self::assertSame('ok', $result['status']);
        self::assertSame('symtony', $result['server']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $result['time']));
    }
}
