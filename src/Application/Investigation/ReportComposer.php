<?php

declare(strict_types=1);

namespace App\Application\Investigation;

use App\Application\Investigation\Analyzer\SideEffectBeforeFailureAnalyzer;
use App\Application\Investigation\Analyzer\StaleCacheAnalyzer;
use App\Domain\Investigation\Finding;

/**
 * Builds the human report from VERIFIED findings only. Rejected findings stay visible in the UI with
 * their reason, but never shape the summary, fix or test strategy.
 */
final class ReportComposer
{
    /**
     * @param list<Finding>        $findings
     * @param array<string, mixed> $llm      report fields proposed by the model (already parsed)
     * @param list<string>         $limitations
     *
     * @return array{summary: string, report: array<string, mixed>}
     */
    public function compose(EvidencePackage $package, array $findings, ?string $llmSummary, array $llm, array $limitations): array
    {
        $verified = array_values(array_filter($findings, static fn (Finding $f): bool => $f->isVerified()));
        $hypotheses = array_values(array_filter($verified, static fn (Finding $f): bool => Finding::HYPOTHESIS === $f->getKind()));
        $facts = array_values(array_filter($verified, static fn (Finding $f): bool => Finding::FACT === $f->getKind()));
        $impact = array_values(array_filter($verified, static fn (Finding $f): bool => SideEffectBeforeFailureAnalyzer::IMPACT === $f->getSource() || str_ends_with($f->getSource(), '#impact')));
        $staleCache = array_values(array_filter($hypotheses, static fn (Finding $f): bool => StaleCacheAnalyzer::SOURCE === $f->getSource()));

        $incident = $package->incident;
        $top = $hypotheses[0] ?? null;
        $summary = null !== $llmSummary && null !== $top
            ? $llmSummary
            : trim(\sprintf('%s failed %d times. %s %s',
                $incident->getReference(), $incident->getOccurrences(),
                null !== $top ? 'Most likely cause: '.lcfirst($top->getStatement()) : 'The evidence does not support a single root cause yet.',
                null !== ($impact[0] ?? null) ? 'Impact: '.lcfirst($impact[0]->getStatement()) : ''));

        $fix = $llm['recommended_fix'] ?? null;
        $reproduction = $llm['reproduction'] ?? null;
        $steps = $llm['next_steps'] ?? [];
        if ([] !== $staleCache) {
            $fix ??= 'Do not decide on stock (or any value you are about to write) from a cache: read the authoritative value inside the transaction, or invalidate the cache on every write. Reserve stock with a conditional update (stock >= quantity) before calling the payment provider, so a failed reservation never leaves a payment behind.';
            $reproduction ??= 'Prime the cached value, change the underlying data directly in the database (as the warehouse sync did), then run the request: it must be rejected cleanly (e.g. HTTP 409) without calling the payment provider.';
            $steps = [] !== $steps ? $steps : [
                'Check payment holds created for failed checkouts and release them.',
                'Add a regression test for the stale-cache path (Test Lab can propose one).',
                'Review other code paths that decide from cached values before writing.',
            ];
        } elseif (null !== $top) {
            $fix ??= 'Validate against the current database state before the write and handle the constraint violation as a business error instead of a 500.';
        }

        return [
            'summary' => $summary,
            'report' => array_filter([
                'what_happened' => array_map(static fn (Finding $f): array => ['statement' => $f->getStatement(), 'citations' => $f->getCitations()], $facts),
                'impact' => array_map(static fn (Finding $f): array => ['statement' => $f->getStatement(), 'citations' => $f->getCitations()], $impact),
                'recommended_fix' => $fix,
                'fix_source' => null === $fix ? null : (isset($llm['recommended_fix']) ? 'model' : 'analyzer template (heuristic)'),
                'next_steps' => $steps,
                'reproduction' => $reproduction,
                'limitations' => array_values(array_unique([...$limitations, ...($llm['limitations'] ?? [])])),
            ], static fn ($v): bool => null !== $v && [] !== $v),
        ];
    }
}
