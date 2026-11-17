<?php

declare(strict_types=1);

namespace Symtony\Bridge\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symtony\Trace\TraceContext;
use Symtony\Tracer;
use Symtony\Transport\EventBuffer;
use Symtony\Transport\HttpTransport;

/**
 * Monolog handler that forwards records to SYMTONY.
 *
 * Standalone (any PHP app):  new SymtonyHandler(endpoint: '…/api/v1/events', token: getenv('SYMTONY_TOKEN'), service: 'shop')
 * Inside the bundle, records are linked to the current trace/span and exceptions already captured are not duplicated.
 */
final class SymtonyHandler extends AbstractProcessingHandler
{
    private EventBuffer $buffer;
    private ?Tracer $tracer;

    public function __construct(
        string $endpoint = '',
        #[\SensitiveParameter] string $token = '',
        private readonly string $service = 'app',
        int|string|Level $level = Level::Warning,
        bool $bubble = true,
        ?EventBuffer $buffer = null,
        ?Tracer $tracer = null,
        private readonly int $flushAt = 100,
    ) {
        parent::__construct($level, $bubble);
        $this->buffer = $buffer ?? new EventBuffer(new HttpTransport($endpoint, $token, source: 'monolog'));
        $this->tracer = $tracer;
    }

    protected function write(LogRecord $record): void
    {
        // Never forward our own delivery warnings (avoids feedback loops).
        if ('symtony' === $record->channel) {
            return;
        }
        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof \Throwable && null !== $this->tracer && $this->tracer->isCaptured($exception)) {
            return;
        }
        if ($exception instanceof \Throwable && null !== $this->tracer) {
            $this->tracer->exception($exception, self::severity($record->level), ['component' => 'log', 'log' => ['channel' => $record->channel, 'message' => mb_substr($record->message, 0, 1000)]]);

            return;
        }

        $context = $this->tracer?->context();
        $event = [
            'timestamp' => Tracer::time((float) $record->datetime->format('U.u')),
            'type' => $exception instanceof \Throwable ? 'exception' : 'log',
            'severity' => self::severity($record->level),
            'service' => $this->service,
            'name' => $record->channel,
            'message' => mb_substr($record->message, 0, 8000),
            'context' => ['component' => 'log', 'log' => ['channel' => $record->channel]] + self::scalarize($record->context) + (array) ($record->extra ? ['extra' => self::scalarize($record->extra)] : []),
        ];
        if (null !== $context) {
            $event['trace_id'] = $context->traceId();
            $event['request_id'] = $context->requestId();
            $event['parent_span_id'] = $context->current()?->spanId;
            $event['span_id'] = TraceContext::newSpanId();
        }
        if ($exception instanceof \Throwable) {
            $event['exception'] = ['class' => $exception::class, 'message' => mb_substr($exception->getMessage(), 0, 2000)];
            unset($event['context']['exception']);
        }
        $this->buffer->add(array_filter($event, static fn ($v): bool => null !== $v));

        if (null === $this->tracer && $this->buffer->count() >= $this->flushAt) {
            $this->buffer->flush();
        }
    }

    public function close(): void
    {
        if (null === $this->tracer) {
            $this->buffer->flush();
        }
        parent::close();
    }

    public static function severity(Level $level): string
    {
        return match (true) {
            $level->value >= Level::Critical->value => 'critical',
            $level->value >= Level::Error->value => 'error',
            $level->value >= Level::Warning->value => 'warning',
            $level->value >= Level::Notice->value => 'notice',
            $level->value >= Level::Info->value => 'info',
            default => 'debug',
        };
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function scalarize(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = (string) $k;
            $out[$key] = match (true) {
                null === $v, \is_scalar($v) => $v,
                \is_array($v) && $depth < 4 => self::scalarize($v, $depth + 1),
                $v instanceof \Throwable => ['class' => $v::class, 'message' => mb_substr($v->getMessage(), 0, 500)],
                $v instanceof \DateTimeInterface => $v->format(\DATE_ATOM),
                $v instanceof \Stringable => mb_substr((string) $v, 0, 500),
                \is_object($v) => $v::class,
                default => get_debug_type($v),
            };
        }

        return $out;
    }
}
