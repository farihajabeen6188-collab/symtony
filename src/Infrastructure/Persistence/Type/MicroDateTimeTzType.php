<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;

/**
 * TIMESTAMP(6) WITH TIME ZONE ↔ DateTimeImmutable, keeping microseconds.
 * Doctrine's built-in datetimetz types truncate to whole seconds, which would scramble timelines.
 */
final class MicroDateTimeTzType extends Type
{
    public const NAME = 'datetimetz_micro';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform instanceof PostgreSQLPlatform ? 'TIMESTAMP(6) WITH TIME ZONE' : $platform->getDateTimeTzTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.uP');
        }

        throw InvalidType::new($value, self::NAME, ['null', \DateTimeInterface::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }
        $string = (string) $value;
        // Postgres returns "2026-11-17 21:27:31.482913+00" (fraction optional, offset may be short).
        $normalized = (string) preg_replace('/([+-]\d{2})$/', '$1:00', $string);
        foreach (['Y-m-d H:i:s.uP', 'Y-m-d H:i:sP'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $normalized);
            if (false !== $dt) {
                return $dt->setTimezone(new \DateTimeZone('UTC'));
            }
        }

        throw InvalidFormat::new($string, self::NAME, "Y-m-d H:i:s.uP");
    }
}
