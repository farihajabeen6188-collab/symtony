<?php

declare(strict_types=1);

namespace App\Domain\Investigation;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'investigation_step')]
#[ORM\UniqueConstraint(name: 'uniq_step', columns: ['investigation_id', 'stage'])]
class InvestigationStep
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Investigation $investigation;

    #[ORM\Column(length: 32, enumType: Stage::class)]
    private Stage $stage;

    /** pending | running | done | skipped | failed */
    #[ORM\Column(length: 12)]
    private string $status = 'pending';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(Investigation $investigation, Stage $stage)
    {
        $this->id = Uuid::v7();
        $this->investigation = $investigation;
        $this->stage = $stage;
    }

    public function getId(): Uuid { return $this->id; }
    public function getInvestigation(): Investigation { return $this->investigation; }
    public function getStage(): Stage { return $this->stage; }
    public function getStatus(): string { return $this->status; }
    public function getSummary(): ?string { return $this->summary; }
    public function getError(): ?string { return $this->error; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }

    public function durationMs(): ?int
    {
        return null !== $this->startedAt && null !== $this->finishedAt
            ? (int) round(((float) $this->finishedAt->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000)
            : null;
    }

    public function begin(): void
    {
        $this->status = 'running';
        $this->startedAt = new \DateTimeImmutable();
    }

    public function done(string $summary): void
    {
        $this->status = 'done';
        $this->summary = mb_substr($summary, 0, 2000);
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function skip(string $reason): void
    {
        $this->status = 'skipped';
        $this->summary = $reason;
        $this->startedAt ??= new \DateTimeImmutable();
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function failWith(string $error): void
    {
        $this->status = 'failed';
        $this->error = mb_substr($error, 0, 4000);
        $this->finishedAt = new \DateTimeImmutable();
    }
}
