<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use App\Domain\Workspace\WorkspaceMembership;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves the workspace of the current request. Every workspace-scoped query starts here.
 * SYMTONY ships single-workspace per user for the MVP; the first membership wins.
 */
final class WorkspaceContext implements ResetInterface
{
    private ?WorkspaceMembership $membership = null;
    private ?string $membershipUser = null;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function user(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Not signed in.');
        }

        return $user;
    }

    public function membership(): WorkspaceMembership
    {
        $user = $this->user();
        // Cached per user and per request only (reset() runs between requests in long-running processes),
        // so one user's workspace can never carry over into another request.
        if (null !== $this->membership && $this->membershipUser === $user->getUserIdentifier() && $this->em->contains($this->membership)) {
            return $this->membership;
        }

        $membership = $this->em->getRepository(WorkspaceMembership::class)->findOneBy(
            ['user' => $user],
            ['createdAt' => 'ASC'],
        );
        if (!$membership instanceof WorkspaceMembership) {
            throw new AccessDeniedException('You are not a member of any workspace.');
        }
        $this->membershipUser = $user->getUserIdentifier();

        return $this->membership = $membership;
    }

    public function reset(): void
    {
        $this->membership = null;
        $this->membershipUser = null;
    }

    public function workspace(): Workspace
    {
        return $this->membership()->getWorkspace();
    }

    public function hasWorkspace(): bool
    {
        try {
            $this->membership();

            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }
}
