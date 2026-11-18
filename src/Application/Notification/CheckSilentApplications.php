<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Domain\Application\Application;
use App\Domain\Application\ConnectionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Notifies when an application that was sending events has been silent for 30 minutes.
 * Runs every 5 minutes from the scheduler; notifications are deduplicated per period of silence.
 */
#[AsMessageHandler(handles: CheckSilentApplicationsMessage::class)]
final class CheckSilentApplications
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Notifier $notifier,
    ) {
    }

    /** @return int applications found silent */
    public function __invoke(?CheckSilentApplicationsMessage $message = null): int
    {
        $now = new \DateTimeImmutable();
        $silent = 0;
        /** @var list<Application> $apps */
        $apps = $this->em->getRepository(Application::class)->createQueryBuilder('a')
            ->where('a.lastEventAt IS NOT NULL')->andWhere('a.lastEventAt < :before')
            ->setParameter('before', $now->modify(\sprintf('-%d seconds', Application::SILENT_AFTER_SECONDS)))
            ->getQuery()->getResult();
        foreach ($apps as $app) {
            if (ConnectionStatus::Silent === $app->getConnectionStatus($now)) {
                $this->notifier->applicationSilent($app);
                ++$silent;
            }
        }
        $this->em->flush();

        return $silent;
    }
}
