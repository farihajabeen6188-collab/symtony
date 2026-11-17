<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Event\Event;
use Symfony\Component\Uid\Uuid;

/**
 * Opaque keyset cursor: (occurred_at, id). Stable under concurrent inserts, no OFFSET scans.
 */
final class EventCursor
{
    public static function encode(Event $event): string
    {
        return rtrim(strtr(base64_encode($event->getOccurredAt()->format('Y-m-d\TH:i:s.uP').'|'.$event->getId()->toRfc4122()), '+/', '-_'), '=');
    }

    /** @return array{t: \DateTimeImmutable, id: Uuid}|null */
    public static function decode(string $cursor): ?array
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (false === $raw || !str_contains($raw, '|')) {
            return null;
        }
        [$t, $id] = explode('|', $raw, 2);
        $time = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $t);
        if (false === $time || !Uuid::isValid($id)) {
            return null;
        }

        return ['t' => $time, 'id' => Uuid::fromString($id)];
    }
}
