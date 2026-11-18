<?php

declare(strict_types=1);

namespace App\Domain\Investigation;

/**
 * Investigation stages, in order. Each is persisted when it actually starts and ends.
 */
enum Stage: string
{
    case CollectEvidence = 'collect_evidence';
    case ReconstructTimeline = 'reconstruct_timeline';
    case AnalyzeHeuristics = 'analyze_heuristics';
    case ReviewHistory = 'review_history';
    case GenerateHypotheses = 'generate_hypotheses';
    case ValidateFindings = 'validate_findings';
    case ProduceReport = 'produce_report';

    public function label(): string
    {
        return match ($this) {
            self::CollectEvidence => 'Collecting evidence',
            self::ReconstructTimeline => 'Reconstructing timeline',
            self::AnalyzeHeuristics => 'Analyzing exceptions and correlations',
            self::ReviewHistory => 'Reviewing historical incidents',
            self::GenerateHypotheses => 'Generating hypotheses with the model',
            self::ValidateFindings => 'Validating findings against evidence',
            self::ProduceReport => 'Producing the report',
        };
    }

    public function position(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }
}
