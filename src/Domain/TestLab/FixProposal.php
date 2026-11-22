<?php

declare(strict_types=1);

namespace App\Domain\TestLab;

use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A code change proposed by the model to fix the bug a verified regression test reproduces.
 * It is only text until it passes the static checks, a human reviews it, and the isolated runner
 * shows the same test going green with it applied.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fix_proposal')]
#[ORM\Index(name: 'idx_fix_test', columns: ['test_id', 'created_at'])]
class FixProposal
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'test_id', nullable: false, onDelete: 'CASCADE')]
    private RegressionTest $test;

    #[ORM\Column(type: Types::TEXT)]
    private string $summary;

    /** Unified diff (patch -p1) against the tested project. */
    #[ORM\Column(type: Types::TEXT)]
    private string $diff;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $citations;

    /** @var list<string> files the diff changes */
    #[ORM\Column(type: Types::JSON)]
    private array $files;

    /** @var list<string> source files the model read */
    #[ORM\Column(type: Types::JSON)]
    private array $sharedFiles;

    /** e.g. "llm:openai/gpt-5.4-mini" */
    #[ORM\Column(length: 160)]
    private string $source;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $staticIssues;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    /** The runner batch of the latest "Prove the fix" run. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $batchId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $citations
     * @param list<string> $files
     * @param list<string> $sharedFiles
     * @param list<string> $staticIssues
     */
    public function __construct(RegressionTest $test, string $summary, string $diff, array $citations, array $files, array $sharedFiles, string $source, array $staticIssues)
    {
        $this->id = Uuid::v7();
        $this->workspace = $test->getWorkspace();
        $this->test = $test;
        $this->summary = $summary;
        $this->diff = $diff;
        $this->citations = $citations;
        $this->files = $files;
        $this->sharedFiles = $sharedFiles;
        $this->source = mb_substr($source, 0, 160);
        $this->staticIssues = $staticIssues;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getWorkspace(): Workspace { return $this->workspace; }
    public function getTest(): RegressionTest { return $this->test; }
    public function getSummary(): string { return $this->summary; }
    public function getDiff(): string { return $this->diff; }
    /** @return list<string> */
    public function getCitations(): array { return $this->citations; }
    /** @return list<string> */
    public function getFiles(): array { return $this->files; }
    /** @return list<string> */
    public function getSharedFiles(): array { return $this->sharedFiles; }
    public function getSource(): string { return $this->source; }
    /** @return list<string> */
    public function getStaticIssues(): array { return $this->staticIssues; }
    public function passedStaticChecks(): bool { return [] === $this->staticIssues; }
    public function getReviewedBy(): ?User { return $this->reviewedBy; }
    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function isReviewed(): bool { return null !== $this->reviewedAt; }
    public function getBatchId(): ?Uuid { return $this->batchId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function markReviewed(User $user): void
    {
        $this->reviewedBy = $user;
        $this->reviewedAt = new \DateTimeImmutable();
    }

    public function startBatch(Uuid $batchId): void
    {
        $this->batchId = $batchId;
    }
}
