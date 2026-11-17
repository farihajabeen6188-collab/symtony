<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_workspace_slug', columns: ['slug'])]
class Workspace
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 80)]
    private string $slug;

    /** Days to keep events before the retention purge deletes them. */
    #[ORM\Column]
    private int $retentionDays = 30;

    /** @var list<string> extra context keys redacted at ingestion, on top of the built-in list */
    #[ORM\Column(type: 'json')]
    private array $redactKeys = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $slug)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->slug = $slug;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    public function setRetentionDays(int $days): void
    {
        $this->retentionDays = max(1, min(365, $days));
    }

    /** @return list<string> */
    public function getRedactKeys(): array
    {
        return $this->redactKeys;
    }

    /** @param list<string> $keys */
    public function setRedactKeys(array $keys): void
    {
        $clean = array_map(static fn (string $k): string => mb_strtolower(trim($k)), $keys);
        $this->redactKeys = array_values(array_unique(array_filter($clean, static fn (string $k): bool => '' !== $k)));
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
