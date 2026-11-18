<?php

declare(strict_types=1);

namespace App\Application\Investigation;

use App\Domain\Investigation\Finding;

/**
 * Checks every finding against the evidence package. Nothing is trusted because of who wrote it:
 * heuristic and model findings go through the same rules.
 *
 *  - every cited ref must exist in this investigation's evidence
 *  - facts and hypotheses must cite at least one item
 *  - a "fact" may not rest only on inferred context (other traces); that is an inference at best
 *  - durations quoted in the statement (e.g. "240 ms") must match a cited item within 10 %
 */
final class GroundingValidator
{
    public function validate(Finding $finding, EvidencePackage $package): void
    {
        $citations = $finding->getCitations();
        if ([] === $citations) {
            $finding->reject('Cites no evidence.');

            return;
        }

        $missing = array_values(array_filter([...$citations, ...$finding->getContradictions()], static fn (string $ref): bool => !$package->has($ref)));
        if ([] !== $missing) {
            $finding->reject('Cites evidence that does not exist: '.implode(', ', $missing).'.');

            return;
        }

        if (Finding::FACT === $finding->getKind()) {
            $proven = array_filter($citations, static fn (string $ref): bool => 'context' !== $package->get($ref)?->getKind());
            if ([] === $proven) {
                $finding->reject('Stated as a fact but supported only by inferred context from other traces.');

                return;
            }
        }

        if (null !== ($bad = $this->checkDurations($finding->getStatement(), $citations, $package))) {
            $finding->reject($bad);

            return;
        }

        $finding->verify();
    }

    /** @param list<string> $citations */
    private function checkDurations(string $statement, array $citations, EvidencePackage $package): ?string
    {
        if (0 === preg_match_all('/(\d+(?:\.\d+)?)\s?(ms|s)\b/', $statement, $m, \PREG_SET_ORDER)) {
            return null;
        }
        $values = [];
        foreach ($citations as $ref) {
            $data = $package->get($ref)?->getData() ?? [];
            foreach (['duration_ms', 'offset_ms'] as $k) {
                if (isset($data[$k]) && is_numeric($data[$k])) {
                    $values[] = (float) $data[$k];
                }
            }
            if (isset($data['cache_age_seconds']) && is_numeric($data['cache_age_seconds'])) {
                $values[] = (float) $data['cache_age_seconds'] * 1000;
            }
        }
        // Differences between two cited timestamps are legitimate quantities too.
        $count = \count($values);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = 0; $j < $count; ++$j) {
                if ($i !== $j) {
                    $values[] = abs($values[$i] - $values[$j]);
                }
            }
        }
        foreach ($m as [, $number, $unit]) {
            $ms = (float) $number * ('s' === $unit ? 1000 : 1);
            $ok = false;
            foreach ($values as $v) {
                if (abs($v - $ms) <= max(1.0, $ms * 0.1)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return \sprintf('Quotes %s %s, which no cited evidence supports.', $number, $unit);
            }
        }

        return null;
    }
}
