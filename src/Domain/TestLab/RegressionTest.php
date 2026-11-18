<?php

declare(strict_types=1);

namespace App\Domain\TestLab;

use App\Domain\Incident\Incident;
use App\Domain\Investigation\Investigation;
use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A proposed PHPUnit regression test. It is only ever executed in the isolated runner, after a human
 * has reviewed it, and its status comes exclusively from real executions.
 */
#[ORM\Entity]
#[ORM\Table(name: 'regression_test')]
#[ORM\Index(name: 'idx_rtest_ws', columns: ['workspace_id', 'created_at'])]
class RegressionTest
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Incident $incident;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Investigation $investigation;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $purpose;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $preconditions;

    #[ORM\Column(type: Types::TEXT)]
    private string $scenario;

    #[ORM\Column(type: Types::TEXT)]
    private string $expected;

    #[ORM\Column(type: Types::TEXT)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $className;

    /** @var list<string> evidence refs (EV-n) of the source investigation */
    #[ORM\Column(type: Types::JSON)]
    private array $citations;

    /** @var list<string> source files that were shared to write it */
    #[ORM\Column(type: Types::JSON)]
    private array $sharedFiles;

    /** "template:<name>" or "llm:<provider>/<model>" */
    #[ORM\Column(length: 160)]
    private string $source;

    /** passed | rejected */
    #[ORM\Column(length: 10)]
    private string $staticCheck;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $staticIssues;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $preconditions
     * @param list<string> $citations
     * @param list<string> $sharedFiles
     * @param list<string> $staticIssues
     */
    public function __construct(Incident $incident, ?Investigation $investigation, string $title, string $purpose, array $preconditions, string $scenario, string $expected, string $code, string $className, array $citations, array $sharedFiles, string $source, array $staticIssues)
    {
        $this->id = Uuid::v7();
        $this->incident = $incident;
        $this->workspace = $incident->getWorkspace();
        $this->investigation = $investigation;
        $this->title = mb_substr($title, 0, 200);
        $this->purpose = $purpose;
        $this->preconditions = $preconditions;
        $this->scenario = $scenario;
        $this->expected = $expected;
        $this->code = $code;
        $this->className = $className;
        $this->citations = $citations;
        $this->sharedFiles = $sharedFiles;
        $this->source = $source;
        $this->staticIssues = $staticIssues;
        $this->staticCheck = [] === $staticIssues ? 'passed' : 'rejected';
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getWorkspace(): Workspace { return $this->workspace; }
    public function getIncident(): Incident { return $this->incident; }
    public function getInvestigation(): ?Investigation { return $this->investigation; }
    public function getTitle(): string { return $this->title; }
    public function getPurpose(): string { return $this->purpose; }
    /** @return list<string> */
    public function getPreconditions(): array { return $this->preconditions; }
    public function getScenario(): string { return $this->scenario; }
    public function getExpected(): string { return $this->expected; }
    public function getCode(): string { return $this->code; }
    public function getClassName(): string { return $this->className; }
    /** @return list<string> */
    public function getCitations(): array { return $this->citations; }
    /** @return list<string> */
    public function getSharedFiles(): array { return $this->sharedFiles; }
    public function getSource(): string { return $this->source; }
    public function isFromModel(): bool { return str_starts_with($this->source, 'llm:'); }
    public function passedStaticChecks(): bool { return 'passed' === $this->staticCheck; }
    /** @return list<string> */
    public function getStaticIssues(): array { return $this->staticIssues; }
    public function getReviewedBy(): ?User { return $this->reviewedBy; }
    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function isReviewed(): bool { return null !== $this->reviewedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function markReviewed(User $user): void
    {
        $this->reviewedBy = $user;
        $this->reviewedAt = new \DateTimeImmutable();
    }

    public function fileName(): string
    {
        return $this->className.'.php';
    }
}
