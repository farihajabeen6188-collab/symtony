<?php

declare(strict_types=1);

namespace Symtony\Cache;

/**
 * Envelope stored by TracedCache so reads can report how old a cached value is.
 *
 * @internal
 */
final class CachedValue
{
    public function __construct(
        public readonly mixed $value,
        public readonly float $computedAt,
    ) {
    }
}
