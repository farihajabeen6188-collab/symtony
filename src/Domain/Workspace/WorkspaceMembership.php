<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_membership', columns: ['user_id', 'workspace_id'])]
class WorkspaceMembership
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 16, enumType: WorkspaceRole::class)]
    private WorkspaceRole $role;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, Workspace $workspace, WorkspaceRole $role)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->workspace = $workspace;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function getRole(): WorkspaceRole
    {
        return $this->role;
    }
}
