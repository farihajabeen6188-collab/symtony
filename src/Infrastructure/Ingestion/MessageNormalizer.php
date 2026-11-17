<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingestion;

/**
 * Strips variable parts from messages so repeated failures group together:
 * "Order 4411 failed for 'ann@x.io' (id 9f8c…)" → "Order {n} failed for '{str}' (id {uuid})".
 */
final class MessageNormalizer
{
    private const RULES = [
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
        '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/' => '{email}',
        '/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?\b/' => '{time}',
        '/"[^"\n]{0,200}"/' => '"{str}"',
        "/'[^'\n]{0,200}'/" => "'{str}'",
        '/\b0x[0-9a-f]+\b/i' => '{hex}',
        '/\b[0-9a-f]{12,}\b/i' => '{hex}',
        // Digits not glued to a word ("30s", "id42" → "{n}s", "id{n}") but never inside placeholders.
        '/(?<![A-Za-z_{])\d+(\.\d+)?/' => '{n}',
    ];

    public function normalize(string $message): string
    {
        $out = preg_replace(array_keys(self::RULES), array_values(self::RULES), $message) ?? $message;
        $out = (string) preg_replace('/\s+/', ' ', trim($out));

        return mb_substr($out, 0, 500);
    }
}
