<?php

declare(strict_types=1);

namespace Symtony\Doctrine;

/**
 * Makes SQL safe to ship: literals become "?", whitespace collapses, length is capped.
 * Bound parameters are never captured in the first place.
 */
final class SqlSanitizer
{
    public static function sanitize(string $sql): string
    {
        $sql = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", '?', $sql);
        $sql = (string) preg_replace('/(?<![A-Za-z_$:?])\b\d+(\.\d+)?\b/', '?', $sql);
        $sql = (string) preg_replace('/\s+/', ' ', trim($sql));

        return mb_substr($sql, 0, 1000);
    }

    /** @return array{operation: string, table: ?string} */
    public static function describe(string $sql): array
    {
        $operation = strtoupper((string) strtok(ltrim($sql), " \n\t("));
        $table = null;
        if (1 === preg_match('/\b(?:FROM|INTO|UPDATE|JOIN)\s+["`]?([A-Za-z_][A-Za-z0-9_.]*)/i', $sql, $m)) {
            $table = $m[1];
        }

        return ['operation' => '' !== $operation ? $operation : 'SQL', 'table' => $table];
    }
}
