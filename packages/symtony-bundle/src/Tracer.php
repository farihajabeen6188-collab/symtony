<?php

declare(strict_types=1);

namespace Symtony;

use Symtony\Trace\Span;
use Symtony\Trace\TraceContext;
use Symtony\Transport\EventBuffer;

/**
 * Public instrumentation API.
 *
 *     $tracer->span('InventoryService::reserve', fn () => $this->reserve($sku, $qty), context: ['inventory' => ['sku' => $sku]]);
 *     $tracer->event('cache', 'stock cache hit', context: ['cache' => ['hit' => true, 'age_seconds' => 612]]);
 *     $tracer->exception($e);
 *
 * Every span becomes one event (start timestamp + duration) linked by trace/span/parent ids.
 */
final class Tracer
{
    /** @var \WeakMap<\Throwable, true> */
    private \WeakMap $captured;

    public function __construct(
        private readonly TraceContext $context,
        private readonly EventBuffer $buffer,
        private readonly string $service = 'app',
        private readonly ?string $release = null,
        private readonly ?string $environment = null,
        private readonly string $projectDir = '',
    ) {
        $this->captured = new \WeakMap();
        // Safety net for fatal errors and aborted requests: close open spans (marked unfinished) and deliver.
        register_shutdown_function(function (): void {
            if ([] !== $this->context->openSpans() || $this->buffer->count() > 0) {
                $this->flush();
            }
        });
    }

    public function context(): TraceContext
    {
        return $this->context;
    }

    public function buffer(): EventBuffer
    {
        return $this->buffer;
    }

    /**
     * @template T
     *
     * @param callable(Span): T    $work
     * @param array<string, mixed> $context
     *
     * @return T
     */
    public function span(string $name, callable $work, string $type = 'service_call', array $context = []): mixed
    {
        $span = $this->start($name, $type, $context);
        try {
            return $work($span);
        } catch (\Throwable $e) {
            $span->error = true;
            $span->severity = 'error';
            $span->context['error'] = ['class' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 500)];
            throw $e;
        } finally {
            $this->end($span);
        }
    }

    /** @param array<string, mixed> $context */
    public function start(string $name, string $type = 'service_call', array $context = [], bool $detached = false): Span
    {
        return $this->context->open($name, $type, $context, $detached);
    }

    /** @param array<string, mixed> $context merged into the span context */
    public function end(Span $span, array $context = [], ?bool $error = null): void
    {
        if ($span->isEnded()) {
            return;
        }
        $span->context = array_replace_recursive($span->context, $context);
        if (null !== $error) {
            $span->error = $error;
            if ($error && 'info' === $span->severity) {
                $span->severity = 'error';
            }
        }
        $this->context->close($span);
        if ($this->context->isMuted()) {
            return;
        }
        $this->buffer->add($this->spanToEvent($span));
    }

    /** Closes a span without reporting it. */
    public function discard(Span $span): void
    {
        $this->context->close($span);
    }

    /**
     * A point-in-time event in the current span (no duration).
     *
     * @param array<string, mixed> $context
     */
    public function event(string $type, string $message, string $severity = 'info', array $context = [], ?string $name = null): void
    {
        if ($this->context->isMuted()) {
            return;
        }
        $current = $this->context->current();
        $this->buffer->add($this->base() + [
            'timestamp' => self::time(microtime(true)),
            'type' => $type,
            'severity' => $severity,
            'name' => $name ?? $message,
            'message' => $message,
            'span_id' => TraceContext::newSpanId(),
            'parent_span_id' => $current?->spanId,
            'context' => $context,
        ]);
    }

    /**
     * Records an exception once (repeat calls for the same throwable are ignored).
     *
     * @param array<string, mixed> $context
     */
    public function exception(\Throwable $e, string $severity = 'error', array $context = []): void
    {
        if (isset($this->captured[$e])) {
            return;
        }
        $this->captured[$e] = true;
        if ($this->context->isMuted()) {
            return;
        }
        $current = $this->context->current();
        if (null !== $current) {
            $current->error = true;
            $current->severity = $severity;
        }

        $event = $this->base() + [
            'timestamp' => self::time(microtime(true)),
            'type' => 'exception',
            'severity' => $severity,
            'name' => (new \ReflectionClass($e))->getShortName(),
            'message' => mb_substr($e->getMessage(), 0, 2000),
            'span_id' => TraceContext::newSpanId(),
            'parent_span_id' => $current?->spanId,
            'exception' => [
                'class' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 2000),
                'frames' => $this->frames($e),
            ],
            'context' => $context,
        ];
        if (null !== $e->getPrevious()) {
            $event['context']['previous'] = ['class' => $e->getPrevious()::class, 'message' => mb_substr($e->getPrevious()->getMessage(), 0, 500)];
        }
        $this->buffer->add($event);
    }

    public function isCaptured(\Throwable $e): bool
    {
        return isset($this->captured[$e]);
    }

    /** Sends a deploy marker immediately. */
    public function deploy(string $release, ?string $commit = null, ?string $author = null): bool
    {
        $this->buffer->add($this->base() + [
            'timestamp' => self::time(microtime(true)),
            'type' => 'deploy',
            'severity' => 'notice',
            'release' => $release,
            'name' => 'Deploy '.$release,
            'message' => \sprintf('Release %s deployed%s', $release, null !== $commit ? ' ('.substr($commit, 0, 12).')' : ''),
            'deploy' => array_filter(['release' => $release, 'commit' => $commit, 'author' => $author]),
        ]);

        return $this->buffer->flush();
    }

    public function flush(): bool
    {
        // Spans still open at flush time (fatal paths) are closed and reported as unfinished.
        foreach (array_reverse($this->context->openSpans()) as $span) {
            $this->end($span, ['unfinished' => true]);
        }

        return $this->buffer->flush();
    }

    /** @return array<string, mixed> */
    private function spanToEvent(Span $span): array
    {
        $severity = $span->error && 'info' === $span->severity ? 'error' : $span->severity;

        return $this->base() + [
            'timestamp' => self::time($span->startedAt),
            'type' => $span->type,
            'severity' => $severity,
            'name' => mb_substr($span->name, 0, 200),
            'message' => mb_substr($span->name, 0, 2000),
            'span_id' => $span->spanId,
            'parent_span_id' => $span->parentSpanId,
            'duration_ms' => $span->durationMs(),
            'context' => $span->context + ($span->error ? ['status' => 'error'] : []),
        ];
    }

    /** @return array<string, mixed> */
    private function base(): array
    {
        return array_filter([
            'service' => $this->context->service() ?? $this->service,
            'release' => $this->release,
            'environment' => $this->environment,
            'trace_id' => $this->context->traceId(),
            'request_id' => $this->context->requestId(),
        ], static fn ($v): bool => null !== $v && '' !== $v);
    }

    /** @return list<array<string, mixed>> */
    private function frames(\Throwable $e): array
    {
        // PHP trace entries name the called function and the *caller's* file/line, so shift by one:
        // frame N = function of trace[N] executing at the file/line recorded by trace[N-1].
        $frames = [];
        $file = $e->getFile();
        $line = $e->getLine();
        foreach (\array_slice($e->getTrace(), 0, 50) as $f) {
            $frames[] = $this->frame($f['class'] ?? null, $f['function'], $file, $line);
            $file = $f['file'] ?? '';
            $line = $f['line'] ?? null;
        }
        if ('' !== $file && \count($frames) < 51) {
            $frames[] = $this->frame(null, '{main}', $file, $line);
        }

        return $frames;
    }

    /** @return array<string, mixed> */
    private function frame(?string $class, string $function, string $file, ?int $line): array
    {
        return array_filter([
            'class' => $class,
            'function' => $function,
            'file' => '' !== $file ? $this->relative($file) : null,
            'line' => $line,
            // The SDK's own frames are never "application" code, even when installed via a symlinked path repository.
            'in_app' => '' !== $file && !str_contains($file, '/vendor/') && !str_starts_with($file, __DIR__.'/'),
        ], static fn ($v): bool => null !== $v);
    }

    private function relative(string $file): string
    {
        return '' !== $this->projectDir && str_starts_with($file, $this->projectDir.'/') ? substr($file, \strlen($this->projectDir) + 1) : $file;
    }

    public static function time(float $microtime): string
    {
        $dt = \DateTimeImmutable::createFromFormat('U.u', number_format($microtime, 6, '.', ''), new \DateTimeZone('UTC'));

        return false === $dt ? gmdate('Y-m-d\TH:i:s\Z') : $dt->format('Y-m-d\TH:i:s.u\Z');
    }
}
