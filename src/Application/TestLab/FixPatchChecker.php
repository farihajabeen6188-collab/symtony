<?php

declare(strict_types=1);

namespace App\Application\TestLab;

/**
 * Static checks for a model-written fix before anyone may run it:
 * a unified diff that only edits existing, allow-listed application source files (never tests),
 * and whose added lines call nothing dangerous. The runner repeats the path check.
 */
final class FixPatchChecker
{
    private const FORBIDDEN = [
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'eval', 'assert',
        'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_create',
        'file_put_contents', 'unlink', 'rmdir', 'chmod', 'chown', 'symlink', 'link', 'rename', 'copy',
        'mail', 'putenv', 'ini_set', 'dl', 'create_function', 'call_user_func', 'call_user_func_array',
        'file_get_contents', 'fopen', 'header', 'setcookie',
    ];

    /**
     * @param list<string> $allowed allow-listed source paths of the tested project
     *
     * @return array{issues: list<string>, files: list<string>}
     */
    public function check(string $diff, array $allowed): array
    {
        $issues = [];
        $editable = array_values(array_filter($allowed, static fn (string $p): bool => str_starts_with($p, 'src/')));
        if ('' === trim($diff)) {
            return ['issues' => ['The fix is empty.'], 'files' => []];
        }
        if (\strlen($diff) > 30000) {
            $issues[] = 'The fix is longer than 30 KB.';
        }

        $files = [];
        $added = [];
        $hunks = 0;
        foreach (preg_split('/\R/', $diff) ?: [] as $line) {
            if (str_starts_with($line, '--- ') || str_starts_with($line, '+++ ')) {
                $path = trim(explode("\t", substr($line, 4))[0]);
                if ('/dev/null' === $path) {
                    $issues[] = 'The fix may not create or delete files.';
                    continue;
                }
                if (!preg_match('#^[ab]/(.+)$#', $path, $m)) {
                    $issues[] = \sprintf('"%s" is not a path relative to the project (a/… or b/…).', $path);
                    continue;
                }
                $relative = $m[1];
                if (str_contains($relative, '..') || !\in_array($relative, $editable, true)) {
                    $issues[] = \sprintf('The fix may only change these files: %s (not %s).', implode(', ', $editable), $relative);
                    continue;
                }
                if (str_starts_with($line, '+++ ')) {
                    $files[] = $relative;
                }
                continue;
            }
            if (str_starts_with($line, '@@')) {
                ++$hunks;
                continue;
            }
            if (str_starts_with($line, '+')) {
                $added[] = substr($line, 1);
            }
        }
        if (0 === $hunks || [] === $files) {
            $issues[] = 'This is not a unified diff with at least one change (--- a/…, +++ b/…, @@ … @@).';
        }

        $code = implode("\n", $added);
        if (str_contains($code, '`')) {
            $issues[] = 'Backtick shell execution is not allowed.';
        }
        if (preg_match('/\b(eval|include|include_once|require|require_once)\b/i', $code, $m)) {
            $issues[] = \sprintf('"%s" is not allowed in a generated fix.', $m[1]);
        }
        foreach (self::FORBIDDEN as $fn) {
            if (preg_match('/(?<![\w$>:\\\\])\\\\?'.preg_quote($fn, '/').'\s*\(/i', $code)) {
                $issues[] = \sprintf('Calling %s() is not allowed in a generated fix.', $fn);
            }
        }

        return ['issues' => array_values(array_unique($issues)), 'files' => array_values(array_unique($files))];
    }
}
