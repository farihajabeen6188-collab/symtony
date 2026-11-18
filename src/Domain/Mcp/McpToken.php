<?php

declare(strict_types=1);

namespace App\Domain\Mcp;

use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A bearer token for MCP clients. Bound to one workspace; only its SHA-256 hash is stored.
 * Scopes: "read" (every read tool) and "write" (start_investigation).
 */
#[ORM\Entity]
#[ORM\Table(name: 'mcp_token')]
#[ORM\UniqueConstraint(name: 'uniq_mcp_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_mcp_token_workspace', columns: ['workspace_id', 'created_at'])]
class McpToken
{
    public const READ = 'read';
    public const WRITE = 'write';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 80)]
    private string $label;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $scopes;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(length: 16)]
    private string $prefix;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** @param list<string> $scopes */
    public function __construct(Workspace $workspace, string $label, array $scopes, string $tokenHash, string $prefix, ?User $createdBy)
    {
        $this->id = Uuid::v7();
        $this->workspace = $workspace;
        $this->label = $label;
        $this->scopes = array_values(array_unique($scopes));
        $this->tokenHash = $tokenHash;
        $this->prefix = $prefix;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getWorkspace(): Workspace { return $this->workspace; }
    public function getLabel(): string { return $this->label; }
    /** @return list<string> */
    public function getScopes(): array { return $this->scopes; }
    public function getPrefix(): string { return $this->prefix; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastUsedAt(): ?\DateTimeImmutable { return $this->lastUsedAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function isRevoked(): bool { return null !== $this->revokedAt; }

    public function allows(string $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }

    /** Records use at most once a minute, so a busy client does not write on every call. */
    public function touch(\DateTimeImmutable $now): bool
    {
        if (null !== $this->lastUsedAt && $now->getTimestamp() - $this->lastUsedAt->getTimestamp() < 60) {
            return false;
        }
        $this->lastUsedAt = $now;

        return true;
    }
}
