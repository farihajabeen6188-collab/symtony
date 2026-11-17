<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symtony\Transport\TransportInterface;

/**
 * Captures every event the SYMTONY bundle would send, for assertions.
 */
final class RecordingTransport implements TransportInterface
{
    /** @var list<array<string, mixed>> */
    public static array $events = [];

    public function send(array $events): bool
    {
        array_push(self::$events, ...$events);

        return true;
    }

    public static function reset(): void
    {
        self::$events = [];
    }
}
