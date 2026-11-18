<?php

declare(strict_types=1);

namespace App\Twig;

use App\Application\Notification\NotificationQueries;
use App\Domain\Workspace\User;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

final class NotificationExtension
{
    public function __construct(
        private readonly NotificationQueries $notifications,
        private readonly Security $security,
    ) {
    }

    /** Unread count for the bell; refreshed on every navigation (not real-time). */
    #[AsTwigFunction('unread_notifications')]
    public function unread(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->notifications->unreadCount($user) : 0;
    }
}
