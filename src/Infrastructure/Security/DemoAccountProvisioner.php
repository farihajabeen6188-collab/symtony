<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use App\Domain\Workspace\WorkspaceMembership;
use App\Domain\Workspace\WorkspaceRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates (idempotently) the local demo workspace and its sign-in account.
 * Credentials come from env (SYMTONY_DEMO_EMAIL / SYMTONY_DEMO_PASSWORD) and are for local use only.
 */
final class DemoAccountProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire(env: 'SYMTONY_DEMO_EMAIL')]
        private readonly string $email,
        #[Autowire(env: 'SYMTONY_DEMO_PASSWORD')]
        private readonly string $password,
    ) {
    }

    /**
     * @return array{user: User, workspace: Workspace, created: bool}
     */
    public function provision(): array
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => mb_strtolower($this->email)]);
        $created = false;

        if (!$user instanceof User) {
            $user = new User($this->email, 'Demo Engineer');
            $user->setPassword($this->hasher->hashPassword($user, $this->password));
            $this->em->persist($user);
            $created = true;
        }

        $membership = $this->em->getRepository(WorkspaceMembership::class)->findOneBy(['user' => $user]);
        if ($membership instanceof WorkspaceMembership) {
            $workspace = $membership->getWorkspace();
        } else {
            $workspace = $this->em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo'])
                ?? new Workspace('Acme Commerce', 'demo');
            $this->em->persist($workspace);
            $this->em->persist(new WorkspaceMembership($user, $workspace, WorkspaceRole::Owner));
            $created = true;
        }

        $this->em->flush();

        return ['user' => $user, 'workspace' => $workspace, 'created' => $created];
    }
}
