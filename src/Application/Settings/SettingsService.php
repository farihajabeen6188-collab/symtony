<?php

declare(strict_types=1);

namespace App\Application\Settings;

use App\Domain\Audit\AuditLog;
use App\Domain\Workspace\ThemePreference;
use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use App\Domain\Workspace\WorkspaceMembership;
use App\Domain\Workspace\WorkspaceRole;
use App\Infrastructure\Persistence\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Profile and workspace settings, and the audit log viewer. Every change is audited.
 */
final class SettingsService
{
    private const AUDIT_PAGE = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function updateProfile(User $user, Workspace $ws, string $name, string $theme): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 120) {
            throw new \DomainException('Your name must be 1 to 120 characters.');
        }
        $pref = ThemePreference::tryFrom($theme) ?? throw new \DomainException('Choose dark, light or system.');
        $user->rename($name);
        $user->setTheme($pref);
        $this->audit->userAction($ws, 'settings.profile_updated', $user, ['theme' => $pref->value]);
        $this->em->flush();
    }

    /** @param string $redactKeys one key per line (or comma separated) */
    public function updateWorkspace(WorkspaceMembership $membership, string $name, int $retentionDays, string $redactKeys): void
    {
        if (WorkspaceRole::Owner !== $membership->getRole()) {
            throw new \DomainException('Only workspace owners can change workspace settings.');
        }
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 120) {
            throw new \DomainException('The workspace name must be 1 to 120 characters.');
        }
        if ($retentionDays < 1 || $retentionDays > 365) {
            throw new \DomainException('Retention must be between 1 and 365 days.');
        }
        $keys = preg_split('/[\s,]+/', $redactKeys) ?: [];
        foreach ($keys as $k) {
            if ('' !== $k && 1 !== preg_match('/^[A-Za-z0-9_.-]{2,64}$/', $k)) {
                throw new \DomainException(\sprintf('"%s" is not a valid key: use 2–64 letters, digits, dots, dashes or underscores.', mb_substr($k, 0, 40)));
            }
        }
        $ws = $membership->getWorkspace();
        $before = ['retention_days' => $ws->getRetentionDays(), 'redact_keys' => $ws->getRedactKeys()];
        $ws->rename($name);
        $ws->setRetentionDays($retentionDays);
        $ws->setRedactKeys(array_slice(array_values(array_filter($keys)), 0, 100));
        $this->audit->userAction($ws, 'settings.workspace_updated', $ws, ['before' => $before, 'after' => ['retention_days' => $ws->getRetentionDays(), 'redact_keys' => $ws->getRedactKeys()]]);
        $this->em->flush();
    }

    /** @return array{items: list<AuditLog>, next: ?string} */
    public function auditLog(Workspace $ws, ?string $before): array
    {
        $qb = $this->em->createQueryBuilder()->select('l')->from(AuditLog::class, 'l')
            ->where('l.workspace = :ws')->setParameter('ws', $ws)
            ->orderBy('l.id', 'DESC')->setMaxResults(self::AUDIT_PAGE + 1);
        if (null !== $before && Uuid::isValid($before)) {
            $qb->andWhere('l.id < :before')->setParameter('before', Uuid::fromString($before), 'uuid');
        }
        /** @var list<AuditLog> $rows */
        $rows = $qb->getQuery()->getResult();
        $next = null;
        if (\count($rows) > self::AUDIT_PAGE) {
            array_pop($rows);
            $next = $rows[\count($rows) - 1]->getId()->toRfc4122();
        }

        return ['items' => $rows, 'next' => $next];
    }
}
