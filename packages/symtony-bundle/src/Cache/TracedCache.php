<?php

declare(strict_types=1);

namespace Symtony\Cache;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symtony\Tracer;

/**
 * Wraps a contracts cache: each get() becomes a cache span with hit/miss and the age of the cached value.
 * Values are stored in a small envelope that records when they were computed; reads unwrap it transparently.
 */
final class TracedCache implements CacheInterface
{
    public function __construct(
        private readonly CacheInterface $inner,
        private readonly Tracer $tracer,
        private readonly string $pool = 'cache.app',
    ) {
    }

    /** @param array<mixed>|null $metadata */
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        $span = $this->tracer->start('cache get '.$key, 'cache', ['component' => 'cache', 'cache' => ['pool' => $this->pool, 'key' => $key]]);
        $computed = false;
        try {
            /** @var mixed $envelope values cached before this decorator existed are not wrapped */
            $envelope = $this->inner->get($key, static function (ItemInterface $item, bool &$save) use ($callback, &$computed): CachedValue {
                $computed = true;

                return new CachedValue($callback($item, $save), microtime(true));
            }, $beta, $metadata);
        } catch (\Throwable $e) {
            $this->tracer->end($span, ['cache' => ['error' => $e::class]], true);
            throw $e;
        }

        $value = $envelope instanceof CachedValue ? $envelope->value : $envelope;
        $age = $envelope instanceof CachedValue && !$computed ? round(microtime(true) - $envelope->computedAt, 3) : 0.0;
        $span->name = ($computed ? 'cache miss ' : 'cache hit ').$key;
        $this->tracer->end($span, ['cache' => ['hit' => !$computed, 'age_seconds' => $age]]);

        return $value;
    }

    public function delete(string $key): bool
    {
        $this->tracer->event('cache', 'cache delete '.$key, 'info', ['component' => 'cache', 'cache' => ['pool' => $this->pool, 'key' => $key, 'operation' => 'delete']]);

        return $this->inner->delete($key);
    }
}

