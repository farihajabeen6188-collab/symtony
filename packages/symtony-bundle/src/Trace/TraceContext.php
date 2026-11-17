<?php

declare(strict_types=1);

namespace Symtony\Trace;

/**
 * Holds the current trace: trace id, request id and the stack of open spans.
 * Compatible with W3C Trace Context (traceparent: 00-<32 hex trace>-<16 hex span>-<flags>).
 */
final class TraceContext
{
    private string $traceId;
    private ?string $requestId = null;
    private ?string $remoteParentSpanId = null;
    /** Per-request service name override (route default "_symtony_service"). */
    private ?string $service = null;
    /** @var list<Span> */
    private array $stack = [];

    /** When muted (ignored route), nothing is recorded until the next begin(). */
    private bool $muted = false;

    public function mute(): void
    {
        $this->muted = true;
    }

    public function isMuted(): bool
    {
        return $this->muted;
    }

    public function service(): ?string
    {
        return $this->service;
    }

    public function setService(?string $service): void
    {
        $this->service = null !== $service && '' !== $service ? $service : null;
    }

    public function __construct()
    {
        $this->traceId = self::newTraceId();
    }

    public function begin(?string $traceparent = null, ?string $requestId = null): void
    {
        $this->stack = [];
        $this->remoteParentSpanId = null;
        $this->service = null;
        $this->muted = false;
        $parsed = null !== $traceparent ? self::parseTraceparent($traceparent) : null;
        $this->traceId = $parsed['trace_id'] ?? self::newTraceId();
        $this->remoteParentSpanId = $parsed['span_id'] ?? null;
        $this->requestId = null !== $requestId && 1 === preg_match('/^[A-Za-z0-9._:\/-]{1,64}$/', $requestId) ? $requestId : self::newRequestId();
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function current(): ?Span
    {
        return $this->stack[\count($this->stack) - 1] ?? null;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function open(string $name, string $type, array $context = [], bool $detached = false): Span
    {
        $span = new Span(
            $this->traceId,
            self::newSpanId(),
            $this->current()->spanId ?? $this->remoteParentSpanId,
            $name,
            $type,
            microtime(true),
            $context,
        );
        if (!$detached) {
            $this->stack[] = $span;
        }

        return $span;
    }

    public function close(Span $span): void
    {
        $span->endedAt = microtime(true);
        $i = array_search($span, $this->stack, true);
        if (false !== $i) {
            // Close any children left open (e.g. an exception skipped their end).
            foreach (\array_slice($this->stack, $i + 1) as $child) {
                $child->endedAt = $child->endedAt ?: $span->endedAt;
            }
            $this->stack = \array_slice($this->stack, 0, $i);
        }
    }

    /** @return list<Span> open spans, outermost first */
    public function openSpans(): array
    {
        return $this->stack;
    }

    public function traceparent(?Span $span = null): string
    {
        $spanId = $span->spanId ?? $this->current()->spanId ?? self::newSpanId();

        return \sprintf('00-%s-%s-01', $this->traceId, $spanId);
    }

    /** @return array{trace_id: string, span_id: string}|null */
    public static function parseTraceparent(string $header): ?array
    {
        if (1 !== preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', trim(strtolower($header)), $m)) {
            return null;
        }
        if (str_repeat('0', 32) === $m[1] || str_repeat('0', 16) === $m[2]) {
            return null;
        }

        return ['trace_id' => $m[1], 'span_id' => $m[2]];
    }

    public static function newTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function newRequestId(): string
    {
        return 'req_'.bin2hex(random_bytes(8));
    }
}
