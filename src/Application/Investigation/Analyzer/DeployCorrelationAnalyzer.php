<?php

declare(strict_types=1);

namespace App\Application\Investigation\Analyzer;

use App\Application\Investigation\EvidencePackage;
use App\Application\Investigation\FindingDraft;

/**
 * "What changed?" A deploy shortly before the failure is worth knowing — as a correlation, never as proof.
 */
final class DeployCorrelationAnalyzer implements Analyzer
{
    public function name(): string
    {
        return 'deploy';
    }

    public function analyze(EvidencePackage $p): array
    {
        $deploys = $p->where(static fn (array $d): bool => 'deploy' === ($d['type'] ?? null));
        $failure = EvidenceQuery::firstFailure($p) ?? EvidenceQuery::exceptionEvidence($p);
        if ([] === $deploys || null === $failure) {
            return [];
        }
        $deploy = $deploys[\count($deploys) - 1];
        $gap = EvidenceQuery::offset($failure) - EvidenceQuery::offset($deploy);
        if ($gap < 0) {
            return [];
        }
        $release = (string) ($deploy->getData()['release'] ?? 'a new release');

        return [new FindingDraft('inference',
            \sprintf('This occurrence came %s after release %s was deployed.', EvidenceQuery::seconds($gap), $release),
            'Correlation from timing only: the deploy is not in the failing trace. Check whether the failure also happened before this release.',
            $gap <= 3_600_000 ? 'medium' : 'low', [$deploy->getRef(), $failure->getRef()], 'heuristic:'.$this->name(), weight: 20)];
    }
}
