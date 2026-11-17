<?php

declare(strict_types=1);

namespace App\Domain\Application;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An ingestion token. Only its SHA-256 hash is stored; the plain token is shown once at creation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ingestion_credential')]
#[ORM\UniqueConstraint(name: 'uniq_credential_hash', columns: ['token_hash'])]
class IngestionCredential
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Application $application;

    #[ORM\Column(length: 80)]
    private string $label;

    /** First characters of the token, safe to display (e.g. "sym_prod_7Hq2"). */
    #[ORM\Column(length: 20)]
    private string $prefix;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(Application $application, string $label, string $prefix, string $tokenHash)
    {
        $this->id = Uuid::v7();
        $this->application = $application;
        $this->label = $label;
        $this->prefix = $prefix;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getApplication(): Application
    {
        return $this->application;
    }

    public function getWorkspace(): \App\Domain\Workspace\Workspace
    {
        return $this->application->getWorkspace();
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(\DateTimeImmutable $at): void
    {
        // Avoid a write per request: only bump when stale by a minute.
        if (null === $this->lastUsedAt || $at->getTimestamp() - $this->lastUsedAt->getTimestamp() >= 60) {
            $this->lastUsedAt = $at;
        }
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }
}
