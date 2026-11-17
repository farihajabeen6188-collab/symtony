<?php

declare(strict_types=1);

namespace Symtony\Trace;

/**
 * A unit of timed work. Mutable until ended; ending it turns it into one SYMTONY event.
 */
final class Span
{
    public float $endedAt = 0.0;
    public bool $error = false;
    public string $severity = 'info';

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public string $name,
        public readonly string $type,
        public readonly float $startedAt,
        public array $context = [],
    ) {
    }

    public function durationMs(): float
    {
        return round((($this->endedAt ?: microtime(true)) - $this->startedAt) * 1000, 3);
    }

    public function isEnded(): bool
    {
        return $this->endedAt > 0.0;
    }
}
