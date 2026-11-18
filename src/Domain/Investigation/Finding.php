<?php

declare(strict_types=1);

namespace App\Domain\Investigation;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A statement about the incident. Facts, inferences and hypotheses are different kinds and never mixed.
 * Every finding cites evidence refs and records who produced it and whether the citations checked out.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finding')]
#[ORM\Index(name: 'idx_finding_inv', columns: ['investigation_id', 'rank'])]
class Finding
{
    public const FACT = 'fact';
    public const INFERENCE = 'inference';
    public const HYPOTHESIS = 'hypothesis';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Investigation $investigation;

    #[ORM\Column(length: 12)]
    private string $kind;

    #[ORM\Column(type: Types::TEXT)]
    private string $statement;

    #[ORM\Column(type: Types::TEXT)]
    private string $rationale;

    /** low | medium | high */
    #[ORM\Column(length: 8)]
    private string $confidence;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $citations;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $contradictions;

    /** "heuristic:<analyzer>" or "llm:<provider>/<model>" */
    #[ORM\Column(length: 160)]
    private string $source;

    /** pending | verified | rejected */
    #[ORM\Column(length: 10)]
    private string $grounding = 'pending';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $groundingReason = null;

    #[ORM\Column]
    private int $rank;

    /**
     * @param list<string> $citations
     * @param list<string> $contradictions
     */
    public function __construct(Investigation $investigation, string $kind, string $statement, string $rationale, string $confidence, array $citations, string $source, int $rank = 0, array $contradictions = [])
    {
        $this->id = Uuid::v7();
        $this->investigation = $investigation;
        $this->kind = \in_array($kind, [self::FACT, self::INFERENCE, self::HYPOTHESIS], true) ? $kind : self::HYPOTHESIS;
        $this->statement = trim($statement);
        $this->rationale = trim($rationale);
        $this->confidence = \in_array($confidence, ['low', 'medium', 'high'], true) ? $confidence : 'low';
        $this->citations = array_values(array_unique($citations));
        $this->contradictions = array_values(array_unique($contradictions));
        $this->source = mb_substr($source, 0, 160);
        $this->rank = $rank;
    }

    public function getId(): Uuid { return $this->id; }
    public function getInvestigation(): Investigation { return $this->investigation; }
    public function getKind(): string { return $this->kind; }
    public function getStatement(): string { return $this->statement; }
    public function getRationale(): string { return $this->rationale; }
    public function getConfidence(): string { return $this->confidence; }
    /** @return list<string> */
    public function getCitations(): array { return $this->citations; }
    /** @return list<string> */
    public function getContradictions(): array { return $this->contradictions; }
    public function getSource(): string { return $this->source; }
    public function isFromModel(): bool { return str_starts_with($this->source, 'llm:'); }
    public function getGrounding(): string { return $this->grounding; }
    public function getGroundingReason(): ?string { return $this->groundingReason; }
    public function getRank(): int { return $this->rank; }
    public function isVerified(): bool { return 'verified' === $this->grounding; }

    public function verify(): void
    {
        $this->grounding = 'verified';
        $this->groundingReason = null;
    }

    public function reject(string $reason): void
    {
        $this->grounding = 'rejected';
        $this->groundingReason = $reason;
    }

    public function rerank(int $rank): void
    {
        $this->rank = $rank;
    }
}
