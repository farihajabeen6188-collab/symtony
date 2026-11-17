<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Application\Application;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A rejected ingestion request or event, kept so users can see why data did not arrive.
 * The payload excerpt is redacted and truncated before storage.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ingestion_failure')]
#[ORM\Index(name: 'idx_failure_app_time', columns: ['application_id', 'received_at'])]
class IngestionFailure
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Application $application;

    #[ORM\Column(length: 32)]
    private string $reason;

    #[ORM\Column(type: Types::TEXT)]
    private string $detail;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $credentialPrefix;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $excerpt;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    public function __construct(?Application $application, string $reason, string $detail, ?string $credentialPrefix = null, ?string $excerpt = null)
    {
        $this->id = Uuid::v7();
        $this->application = $application;
        $this->reason = $reason;
        $this->detail = mb_substr($detail, 0, 4000);
        $this->credentialPrefix = $credentialPrefix;
        $this->excerpt = null !== $excerpt ? mb_substr($excerpt, 0, 2000) : null;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getApplication(): ?Application { return $this->application; }
    public function getReason(): string { return $this->reason; }
    public function getDetail(): string { return $this->detail; }
    public function getCredentialPrefix(): ?string { return $this->credentialPrefix; }
    public function getExcerpt(): ?string { return $this->excerpt; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
}
