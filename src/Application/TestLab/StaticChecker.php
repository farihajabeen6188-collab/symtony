<?php

declare(strict_types=1);

namespace App\Application\TestLab;

/**
 * Checks a proposed test before anyone can run it. The code is parsed (TOKEN_PARSE), never executed.
 */
final class StaticChecker
{
    private const FORBIDDEN = [
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'eval', 'assert',
        'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_create',
        'file_put_contents', 'unlink', 'rmdir', 'chmod', 'chown', 'symlink', 'link', 'rename', 'copy',
        'mail', 'putenv', 'ini_set', 'dl', 'create_function', 'call_user_func', 'call_user_func_array',
    ];

    /** @return list<string> issues; empty means it passed */
    public function check(string $code, string $className, string $namespace, string $baseClass): array
    {
        $issues = [];
        if (\strlen($code) > 20000) {
            $issues[] = 'The test is longer than 20 KB.';
        }
        if (!str_starts_with(ltrim($code), '<?php')) {
            $issues[] = 'The file must start with <?php.';
        }
        if (1 !== preg_match('/^namespace\s+'.preg_quote($namespace, '/').'\s*;/m', $code)) {
            $issues[] = \sprintf('The namespace must be %s.', $namespace);
        }
        $classes = preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $code, $m);
        if (1 !== $classes || $m[1][0] !== $className) {
            $issues[] = \sprintf('The file must declare exactly one class named %s.', $className);
        }
        $short = substr((string) strrchr('\\'.$baseClass, '\\'), 1);
        if (1 !== preg_match('/class\s+'.preg_quote($className, '/').'\s+extends\s+\\\\?(?:'.preg_quote($short, '/').'|'.preg_quote($baseClass, '/').')\b/', $code)) {
            $issues[] = \sprintf('The test class must extend %s.', $baseClass);
        }
        if (0 === preg_match('/public\s+function\s+test\w*\s*\(/', $code)) {
            $issues[] = 'There is no public test method (named test…).';
        }

        try {
            $tokens = token_get_all($code, \TOKEN_PARSE);
        } catch (\ParseError $e) {
            $issues[] = \sprintf('Syntax error on line %d: %s', $e->getLine(), $e->getMessage());
            $tokens = token_get_all($code);
        }
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $t = $tokens[$i];
            if (!\is_array($t)) {
                if ('`' === $t) {
                    $issues[] = 'Backtick shell execution is not allowed.';
                }
                continue;
            }
            [$id, $text] = $t;
            if (\in_array($id, [\T_EVAL, \T_INCLUDE, \T_INCLUDE_ONCE, \T_REQUIRE, \T_REQUIRE_ONCE], true)) {
                $issues[] = \sprintf('"%s" is not allowed in a generated test.', $text);
            }
            if (\in_array($id, [\T_STRING, \T_NAME_FULLY_QUALIFIED], true) && \in_array(strtolower(ltrim($text, '\\')), self::FORBIDDEN, true)) {
                // A function call: the next meaningful token is "(" and the previous is not -> or :: (methods are fine).
                $j = $i + 1;
                while ($j < $count && \is_array($tokens[$j]) && \T_WHITESPACE === $tokens[$j][0]) {
                    ++$j;
                }
                $k = $i - 1;
                while ($k >= 0 && \is_array($tokens[$k]) && \T_WHITESPACE === $tokens[$k][0]) {
                    --$k;
                }
                $prev = $k >= 0 ? (\is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k]) : '';
                if ($j < $count && '(' === $tokens[$j] && !\in_array($prev, ['->', '::', '?->', 'function'], true)) {
                    $issues[] = \sprintf('Calling %s() is not allowed in a generated test.', $text);
                }
            }
        }

        return array_values(array_unique($issues));
    }
}
