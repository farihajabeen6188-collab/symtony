<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Audit\AuditLog;
use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Writes audit entries. Persists only; the caller's flush commits it with the change it describes.
 */
final class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    /** @param array<string, mixed> $metadata */
    public function userAction(Workspace $workspace, string $action, ?object $subject = null, array $metadata = []): void
    {
        $user = $this->security->getUser();
        $actor = $user instanceof User ? $user->getEmail() : 'anonymous';
        $this->write($workspace, 'user', $actor, $action, $subject, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public function write(Workspace $workspace, string $actorType, string $actor, string $action, ?object $subject = null, array $metadata = []): void
    {
        [$type, $id] = $this->describe($subject);
        $this->em->persist(new AuditLog($workspace, $actorType, $actor, $action, $type, $id, $metadata));
    }

    /** @return array{0: ?string, 1: ?string} */
    private function describe(?object $subject): array
    {
        if (null === $subject) {
            return [null, null];
        }
        $short = (new \ReflectionClass($subject))->getShortName();
        $id = method_exists($subject, 'getId') ? (string) $subject->getId() : null;

        return [$short, $id];
    }
}
