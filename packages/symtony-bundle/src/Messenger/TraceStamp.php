<?php

declare(strict_types=1);

namespace Symtony\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the W3C traceparent and request id from dispatch to the worker that handles the message.
 */
final class TraceStamp implements StampInterface
{
    public function __construct(
        public readonly string $traceparent,
        public readonly ?string $requestId,
    ) {
    }
}
