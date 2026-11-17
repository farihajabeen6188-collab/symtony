<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Member = 'member';

    public function canManage(): bool
    {
        return self::Owner === $this;
    }
}
