<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingestion;

/**
 * Removes secrets and personal data before anything is stored or sent to an AI provider.
 *
 * Two passes: (1) values under sensitive keys are replaced wholesale; (2) every string is scanned for
 * secret-shaped values (bearer tokens, JWTs, API keys, card numbers, DSN passwords, optionally emails).
 */
final class Redactor
{
    public const MASK = '[redacted]';
    public const EMAIL_MASK = '[email]';

    /** Normalized key names (lowercase, no separators) whose values are always redacted. Match = equals or ends with. */
    private const SENSITIVE_KEY_SUFFIXES = [
        'password', 'passwd', 'pwd', 'passphrase', 'secret', 'token', 'apikey', 'accesskey', 'privatekey',
        'authorization', 'auth', 'cookie', 'setcookie', 'session', 'sessionid', 'sessid', 'phpsessid',
        'creditcard', 'cardnumber', 'cvv', 'cvc', 'iban', 'ssn', 'signature', 'clientsecret', 'dsn',
    ];

    /** Keys that end with a sensitive suffix but are harmless counters/flags. */
    private const SAFE_KEYS = ['tokencount', 'tokensused', 'totaltokens', 'tokenusage', 'sessionstarted'];

    /** @var list<array{0: string, 1: string}> */
    private const VALUE_PATTERNS = [
        ['/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*/i', '$1 '.self::MASK],
        ['/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/', self::MASK],
        ['/\bsym_[a-z]{4}_[0-9A-Za-z]{32}\b/', self::MASK],
        ['/\bsmcp_[0-9A-Za-z]{40}\b/', self::MASK],
        ['/\bhf_[A-Za-z0-9]{20,}\b/', self::MASK],
        ['/\bsk-(?:ant-|proj-)?[A-Za-z0-9_-]{20,}\b/', self::MASK],
        ['/\bgh[pousr]_[A-Za-z0-9]{30,}\b/', self::MASK],
        ['/\bAKIA[0-9A-Z]{16}\b/', self::MASK],
        ['/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/', self::MASK],
        // scheme://user:password@host  →  scheme://user:[redacted]@host
        ['#\b([a-z][a-z0-9+.-]*://[^\s:/@]+):([^\s@/]+)@#i', '$1:'.self::MASK.'@'],
        // key=value pairs in query strings / log lines
        ['/\b(password|passwd|secret|token|api_key|apikey|access_token|refresh_token|client_secret)=([^&\s"\']+)/i', '$1='.self::MASK],
    ];

    private const EMAIL_PATTERN = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/';

    /**
     * @param array<mixed>  $data
     * @param list<string>  $extraKeys workspace-configured keys (normalized like the built-ins)
     *
     * @return array{data: array<mixed>, count: int}
     */
    public function redactArray(array $data, array $extraKeys = [], bool $emails = true): array
    {
        $count = 0;
        $extra = array_map(self::normalizeKey(...), $extraKeys);
        $result = $this->walk($data, $extra, $emails, $count, 0);

        return ['data' => $result, 'count' => $count];
    }

    /** @return array{value: string, count: int} */
    public function redactString(string $value, bool $emails = true): array
    {
        $count = 0;
        $out = $this->scrub($value, $emails, $count);

        return ['value' => $out, 'count' => $count];
    }

    /** @return list<string> the built-in sensitive key suffixes (shown in settings) */
    public static function builtInKeys(): array
    {
        return self::SENSITIVE_KEY_SUFFIXES;
    }

    /** @param list<string> $extraKeys normalized keys */
    public function isSensitiveKey(string $key, array $extraKeys = []): bool
    {
        $k = self::normalizeKey($key);
        if ('' === $k || \in_array($k, self::SAFE_KEYS, true)) {
            return false;
        }
        foreach ([...self::SENSITIVE_KEY_SUFFIXES, ...$extraKeys] as $suffix) {
            if ('' !== $suffix && ($k === $suffix || str_ends_with($k, $suffix))) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeKey(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($key));
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $extra
     *
     * @return array<mixed>
     */
    private function walk(array $data, array $extra, bool $emails, int &$count, int $depth): array
    {
        if ($depth > 12) {
            ++$count;

            return ['_truncated' => 'context nested deeper than 12 levels was dropped'];
        }

        foreach ($data as $key => $value) {
            if (\is_string($key) && $this->isSensitiveKey($key, $extra) && null !== $value && '' !== $value && [] !== $value) {
                $data[$key] = self::MASK;
                ++$count;
                continue;
            }
            if (\is_array($value)) {
                $data[$key] = $this->walk($value, $extra, $emails, $count, $depth + 1);
            } elseif (\is_string($value)) {
                $data[$key] = $this->scrub($value, $emails, $count);
            }
        }

        return $data;
    }

    private function scrub(string $value, bool $emails, int &$count): string
    {
        foreach (self::VALUE_PATTERNS as [$pattern, $replacement]) {
            $value = (string) preg_replace($pattern, $replacement, $value, -1, $n);
            $count += $n;
        }

        $value = (string) preg_replace_callback('/\b(?:\d[ -]?){12,18}\d\b/', function (array $m) use (&$count): string {
            $digits = preg_replace('/\D/', '', $m[0]) ?? '';
            if (\strlen($digits) >= 13 && \strlen($digits) <= 19 && self::luhn($digits)) {
                ++$count;

                return self::MASK;
            }

            return $m[0];
        }, $value);

        if ($emails) {
            $value = (string) preg_replace(self::EMAIL_PATTERN, self::EMAIL_MASK, $value, -1, $n);
            $count += $n;
        }

        return $value;
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = \strlen($digits) - 1; $i >= 0; --$i) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }

        return 0 === $sum % 10;
    }
}
