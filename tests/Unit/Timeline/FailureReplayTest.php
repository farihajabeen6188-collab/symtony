<?php

declare(strict_types=1);

namespace App\Tests\Unit\Timeline;

use App\Application\Timeline\FailureReplay;
use PHPUnit\Framework\TestCase;

final class FailureReplayTest extends TestCase
{
    public function testTheDemoCheckoutFailureReplaysAsTheTraceRecordedIt(): void
    {
        $timeline = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/config/landing/demo-timeline.json'), true);
        $r = (new FailureReplay())->build($timeline);
        self::assertNotNull($r);

        $names = array_column($r['steps'], 'name');
        self::assertSame('POST /checkout', $names[0], 'Starts with the request');
        self::assertSame('UPDATE product', $r['steps'][$r['failure']]['name']);
        self::assertSame('ROLLBACK', $r['steps'][$r['rollback']]['name']);
        self::assertGreaterThan($r['failure'], $r['rollback']);

        self::assertSame(['INSERT orders'], array_map(static fn (int $i): string => $names[$i], $r['undone']), 'Only writes inside the rolled-back transaction');
        self::assertSame(['POST localhost/payment-api/authorize'], array_map(static fn (int $i): string => $names[$i], $r['survived']), 'The payment call cannot be undone');
        self::assertSame('shop HTTP 500', $r['outcome']);

        $positions = array_column($r['steps'], 'position');
        self::assertSame($positions, (static function (array $p): array { sort($p); return $p; })($positions), 'Steps are in time order');
        self::assertSame(0.0, $r['steps'][0]['t_ms']);

        foreach ($r['steps'] as $s) {
            foreach ($s['details'] as $d) {
                self::assertNotSame('', $d['value'], 'No empty details');
            }
        }
        $failure = array_column($r['steps'][$r['failure']]['details'], 'value', 'label');
        self::assertStringContainsString('stock_non_negative', $failure['Error']);

        $context = array_column($r['context'], 'service');
        self::assertContains('warehouse-sync', $context, 'The stock change from another trace is offered as inferred context');
        self::assertNotContains('payment-api', $context, 'Earlier traffic of the request\'s own services is not context');
    }

    public function testNoRollbackMeansNoConsequences(): void
    {
        $r = (new FailureReplay())->build(['first_failure' => 'b', 'items' => [
            ['id' => 'a', 'proven' => true, 'offset_ms' => 0, 'type' => 'http_request', 'service' => 'api', 'name' => 'GET /x', 'error' => true, 'context' => ['status' => 500]],
            ['id' => 'b', 'proven' => true, 'offset_ms' => 5, 'type' => 'exception', 'service' => 'api', 'name' => 'RuntimeException', 'error' => true, 'context' => []],
            ['id' => 'c', 'proven' => false, 'offset_ms' => 3, 'type' => 'log', 'service' => 'api', 'name' => 'noise', 'error' => false, 'context' => []],
        ]]);

        self::assertCount(2, $r['steps'], 'Inferred items are never steps');
        self::assertSame(1, $r['failure']);
        self::assertNull($r['rollback']);
        self::assertSame([], $r['undone']);
        self::assertSame([], $r['survived']);
    }

    public function testNothingProvenMeansNothingToReplay(): void
    {
        self::assertNull((new FailureReplay())->build(['items' => [['id' => 'x', 'proven' => false, 'offset_ms' => 0, 'type' => 'log', 'service' => 'a', 'name' => 'n']]]));
    }
}
