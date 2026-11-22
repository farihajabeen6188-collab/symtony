<?php

declare(strict_types=1);

namespace App\Domain\TestLab;

use App\Domain\Workspace\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One real run of a regression test in the isolated runner, against the buggy or the fixed build.
 * Everything here comes from the runner's result file; nothing is inferred or assumed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_execution')]
#[ORM\Index(name: 'idx_texec_test', columns: ['test_id', 'created_at'])]
class TestExecution
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'test_id', nullable: false, onDelete: 'CASCADE')]
    private RegressionTest $test;

    /** buggy | fixed | proposed (an AI fix applied to the buggy build) */
    #[ORM\Column(length: 8)]
    private string $variant;

    /** Groups the buggy + fixed pair started together by "Prove it". */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $batchId;

    /** queued | running | passed | failed | error | timeout */
    #[ORM\Column(length: 10)]
    private string $status = 'queued';

    #[ORM\Column(nullable: true)]
    private ?int $exitCode = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(nullable: true)]
    private ?int $assertions = null;

    #[ORM\Column(nullable: true)]
    private ?int $failures = null;

    #[ORM\Column(nullable: true)]
    private ?int $errors = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $output = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $startedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(RegressionTest $test, string $variant, Uuid $batchId, ?User $startedBy)
    {
        $this->id = Uuid::v7();
        $this->test = $test;
        $this->variant = \in_array($variant, ['fixed', 'proposed'], true) ? $variant : 'buggy';
        $this->batchId = $batchId;
        $this->startedBy = $startedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getTest(): RegressionTest { return $this->test; }
    public function getVariant(): string { return $this->variant; }
    public function getBatchId(): Uuid { return $this->batchId; }
    public function getStatus(): string { return $this->status; }
    public function getExitCode(): ?int { return $this->exitCode; }
    public function getDurationMs(): ?int { return $this->durationMs; }
    public function getAssertions(): ?int { return $this->assertions; }
    public function getFailures(): ?int { return $this->failures; }
    public function getErrors(): ?int { return $this->errors; }
    public function getOutput(): ?string { return $this->output; }
    public function getStartedBy(): ?User { return $this->startedBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function isFinished(): bool { return null !== $this->finishedAt; }

    public function running(): void
    {
        $this->status = 'running';
    }

    /**
     * passed  = exit 0
     * failed  = assertion failures only (a genuine red)
     * error   = errors/exceptions in the test itself, setup problems, or an unparseable run
     * timeout = killed by the runner's time limit
     */
    public function record(int $exitCode, int $durationMs, ?int $assertions, ?int $failures, ?int $errors, string $output, bool $timedOut, string $setup): void
    {
        $this->exitCode = $exitCode;
        $this->durationMs = $durationMs;
        $this->assertions = $assertions;
        $this->failures = $failures;
        $this->errors = $errors;
        $this->output = mb_substr($output, -60000);
        $this->finishedAt = new \DateTimeImmutable();
        $this->status = match (true) {
            $timedOut => 'timeout',
            'ok' !== $setup => 'error',
            0 === $exitCode => 'passed',
            null !== $failures && $failures > 0 && 0 === (int) $errors => 'failed',
            default => 'error',
        };
    }

    public function abandon(string $reason): void
    {
        $this->status = 'error';
        $this->output = $reason;
        $this->finishedAt = new \DateTimeImmutable();
    }
}
