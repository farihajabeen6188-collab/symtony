<?php

declare(strict_types=1);

namespace App\Domain\Application;

use App\Domain\Workspace\Workspace;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An application connected to SYMTONY. Events, incidents and credentials belong to it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'application')]
#[ORM\UniqueConstraint(name: 'uniq_application_slug', columns: ['workspace_id', 'slug'])]
class Application
{
    /** An app with no event for this long is shown as "silent". */
    public const SILENT_AFTER_SECONDS = 1800;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\Column(length: 500)]
    private string $description = '';

    #[ORM\Column(length: 16, enumType: Environment::class)]
    private Environment $environment;

    #[ORM\Column(length: 16, enumType: Framework::class)]
    private Framework $framework;

    #[ORM\Column(length: 24, enumType: IngestionMethod::class)]
    private IngestionMethod $ingestionMethod;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $firstEventAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastEventAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Workspace $workspace,
        string $name,
        string $slug,
        Environment $environment,
        Framework $framework,
        IngestionMethod $ingestionMethod,
        string $description = '',
    ) {
        $this->id = Uuid::v7();
        $this->workspace = $workspace;
        $this->name = $name;
        $this->slug = $slug;
        $this->environment = $environment;
        $this->framework = $framework;
        $this->ingestionMethod = $ingestionMethod;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function update(string $name, string $description, Environment $environment, Framework $framework): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->environment = $environment;
        $this->framework = $framework;
    }

    public function getEnvironment(): Environment
    {
        return $this->environment;
    }

    public function getFramework(): Framework
    {
        return $this->framework;
    }

    public function getIngestionMethod(): IngestionMethod
    {
        return $this->ingestionMethod;
    }

    public function getFirstEventAt(): ?\DateTimeImmutable
    {
        return $this->firstEventAt;
    }

    public function getLastEventAt(): ?\DateTimeImmutable
    {
        return $this->lastEventAt;
    }

    public function recordEventsReceived(\DateTimeImmutable $at): void
    {
        $this->firstEventAt ??= $at;
        if (null === $this->lastEventAt || $at > $this->lastEventAt) {
            $this->lastEventAt = $at;
        }
    }

    public function getConnectionStatus(?\DateTimeImmutable $now = null): ConnectionStatus
    {
        if (null === $this->lastEventAt) {
            return ConnectionStatus::Pending;
        }
        $now ??= new \DateTimeImmutable();

        return $now->getTimestamp() - $this->lastEventAt->getTimestamp() > self::SILENT_AFTER_SECONDS
            ? ConnectionStatus::Silent
            : ConnectionStatus::Connected;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
