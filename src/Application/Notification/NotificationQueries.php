<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Domain\Notification\Notification;
use App\Domain\Workspace\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class NotificationQueries
{
    private const PAGE = 30;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Newest first, cursor-paginated by id (UUIDv7 ids are time-ordered).
     *
     * @return array{items: list<Notification>, next: ?string}
     */
    public function page(User $user, bool $unreadOnly, ?string $before, int $limit = self::PAGE): array
    {
        $qb = $this->em->createQueryBuilder()->select('n')->from(Notification::class, 'n')
            ->where('n.user = :u')->setParameter('u', $user)
            ->orderBy('n.id', 'DESC')->setMaxResults($limit + 1);
        if ($unreadOnly) {
            $qb->andWhere('n.readAt IS NULL');
        }
        if (null !== $before && Uuid::isValid($before)) {
            $qb->andWhere('n.id < :before')->setParameter('before', Uuid::fromString($before), 'uuid');
        }
        /** @var list<Notification> $rows */
        $rows = $qb->getQuery()->getResult();
        $next = null;
        if (\count($rows) > $limit) {
            array_pop($rows);
            $next = $rows[\count($rows) - 1]->getId()->toRfc4122();
        }

        return ['items' => $rows, 'next' => $next];
    }

    public function unreadCount(User $user): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM notification WHERE user_id = :u AND read_at IS NULL',
            ['u' => $user->getId()->toRfc4122()],
        );
    }

    public function get(User $user, string $id): ?Notification
    {
        return Uuid::isValid($id) ? $this->em->getRepository(Notification::class)->findOneBy(['id' => Uuid::fromString($id), 'user' => $user]) : null;
    }

    public function markRead(Notification $n): void
    {
        $n->markRead();
        $this->em->flush();
    }

    public function markAllRead(User $user): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            'UPDATE notification SET read_at = now() WHERE user_id = :u AND read_at IS NULL',
            ['u' => $user->getId()->toRfc4122()],
        );
    }
}
