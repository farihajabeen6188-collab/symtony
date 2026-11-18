<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One notification for one user. Created by background handlers when something happened
 * (critical incident, reopen, investigation finished, test verified, application went silent).
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'idx_notification_user_time', columns: ['user_id', 'created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_notification_dedupe', columns: ['user_id', 'dedupe_key'])]
class Notification
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 40)]
    private string $kind;

    #[ORM\Column(length: 12)]
    private string $tone;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(length: 300)]
    private string $url;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $dedupeKey;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(Workspace $workspace, User $user, string $kind, string $tone, string $title, string $body, string $url, ?string $dedupeKey = null)
    {
        $this->id = Uuid::v7();
        $this->workspace = $workspace;
        $this->user = $user;
        $this->kind = $kind;
        $this->tone = $tone;
        $this->title = mb_substr($title, 0, 200);
        $this->body = $body;
        $this->url = $url;
        $this->dedupeKey = $dedupeKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getWorkspace(): Workspace { return $this->workspace; }
    public function getUser(): User { return $this->user; }
    public function getKind(): string { return $this->kind; }
    public function getTone(): string { return $this->tone; }
    public function getTitle(): string { return $this->title; }
    public function getBody(): string { return $this->body; }
    public function getUrl(): string { return $this->url; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function isRead(): bool { return null !== $this->readAt; }

    public function markRead(): void
    {
        $this->readAt ??= new \DateTimeImmutable();
    }
}
