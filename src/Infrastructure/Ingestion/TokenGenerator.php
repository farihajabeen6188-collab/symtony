<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingestion;

use App\Domain\Application\Environment;

/**
 * Ingestion tokens: "sym_<env4>_<32 base62 chars>" (~190 bits of entropy). Only the SHA-256 hash is stored.
 */
final class TokenGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    public const PREFIX_LENGTH = 13;

    /** @return array{token: string, prefix: string, hash: string} */
    public function generate(Environment $environment): array
    {
        $random = '';
        for ($i = 0; $i < 32; ++$i) {
            $random .= self::ALPHABET[random_int(0, 61)];
        }
        $token = \sprintf('sym_%s_%s', $environment->tokenTag(), $random);

        return ['token' => $token, 'prefix' => self::prefixOf($token), 'hash' => self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function prefixOf(string $token): string
    {
        return substr($token, 0, self::PREFIX_LENGTH);
    }

    public static function looksValid(string $token): bool
    {
        return 1 === preg_match('/^sym_[a-z]{4}_[0-9A-Za-z]{32}$/', $token);
    }
}
