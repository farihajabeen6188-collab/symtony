<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use App\Domain\Workspace\WorkspaceMembership;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants access to anything that belongs to a workspace the user is a member of.
 * Subjects: a Workspace, or any object exposing getWorkspace(): Workspace.
 *
 * @extends Voter<string, object>
 */
final class WorkspaceVoter extends Voter
{
    public const VIEW = 'WORKSPACE_VIEW';
    public const MANAGE = 'WORKSPACE_MANAGE';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE], true)
            && ($subject instanceof Workspace || (\is_object($subject) && method_exists($subject, 'getWorkspace')));
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $workspace = $subject instanceof Workspace ? $subject : $subject->getWorkspace();
        if (!$workspace instanceof Workspace) {
            return false;
        }

        $membership = $this->em->getRepository(WorkspaceMembership::class)->findOneBy(['user' => $user, 'workspace' => $workspace]);
        if (!$membership instanceof WorkspaceMembership) {
            return false;
        }

        return self::VIEW === $attribute || $membership->getRole()->canManage();
    }
}
