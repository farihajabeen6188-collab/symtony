<?php

declare(strict_types=1);

namespace Symtony\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symtony\Tracer;

/**
 * Dispatch → queue span + TraceStamp. Handling a received message → continues the original trace
 * and flushes at the end (workers have no kernel.terminate).
 */
final class TracingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Tracer $tracer)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $class = $envelope->getMessage()::class;
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        $received = null !== $envelope->last(ReceivedStamp::class);

        if ($received) {
            $stamp = $envelope->last(TraceStamp::class);
            $this->tracer->context()->begin($stamp instanceof TraceStamp ? $stamp->traceparent : null, $stamp instanceof TraceStamp ? $stamp->requestId : null);
            $span = $this->tracer->start('handle '.$short, 'queue', ['component' => 'messenger', 'queue' => ['message' => $class, 'operation' => 'handle']]);
            try {
                return $stack->next()->handle($envelope, $stack);
            } catch (\Throwable $e) {
                $this->tracer->exception($e, 'error', ['component' => 'messenger']);
                $span->error = true;
                throw $e;
            } finally {
                $this->tracer->end($span);
                $this->tracer->flush();
            }
        }

        $span = $this->tracer->start('dispatch '.$short, 'queue', ['component' => 'messenger', 'queue' => ['message' => $class, 'operation' => 'dispatch']]);
        if (null === $envelope->last(TraceStamp::class)) {
            $envelope = $envelope->with(new TraceStamp($this->tracer->context()->traceparent($span), $this->tracer->context()->requestId()));
        }
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->tracer->end($span);
        }
    }
}

