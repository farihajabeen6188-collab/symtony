<?php

declare(strict_types=1);

namespace App\Application\Investigation;

use App\Domain\Investigation\EvidenceItem;
use App\Domain\Investigation\Finding;
use App\Domain\Investigation\Investigation;
use App\Domain\Investigation\InvestigationStep;

/**
 * Exports an investigation with every section and citation, plus the cited evidence as an appendix.
 */
final class MarkdownReport
{
    /**
     * @param list<InvestigationStep>   $steps
     * @param list<Finding>             $findings
     * @param array<string, EvidenceItem> $evidence
     */
    public function render(Investigation $inv, array $steps, array $findings, array $evidence): string
    {
        $i = $inv->getIncident();
        $r = $inv->getReport();
        $verified = array_filter($findings, static fn (Finding $f): bool => $f->isVerified());
        $cite = static fn (array $refs): string => [] === $refs ? '' : ' ['.implode(', ', $refs).']';
        $out = [];

        $out[] = \sprintf('# %s investigation — %s', $i->getReference(), $i->getTitle());
        $out[] = '';
        $out[] = \sprintf('- Investigation: %s · status %s · engine `%s`', $inv->getShortId(), $inv->getStatus()->value, $inv->getEngine());
        $out[] = \sprintf('- Application: %s (%s) · %d occurrences · first seen %s · last seen %s UTC', $i->getApplication()->getName(), $i->getApplication()->getEnvironment()->value, $i->getOccurrences(), $i->getFirstSeenAt()->format('Y-m-d H:i'), $i->getLastSeenAt()->format('Y-m-d H:i'));
        $out[] = \sprintf('- Grounding: %d of %d claims verified against evidence', \count($verified), \count($findings));
        if (null !== $inv->getTraceId()) {
            $out[] = '- Trace: `'.$inv->getTraceId().'`';
        }
        $out[] = '';
        $out[] = '## Executive summary';
        $out[] = '';
        $out[] = $inv->getSummary() ?? '_The investigation did not complete._';

        if (!empty($r['what_happened'])) {
            $out[] = '';
            $out[] = '## What happened (facts)';
            $out[] = '';
            foreach ($r['what_happened'] as $f) {
                $out[] = '- '.$f['statement'].$cite($f['citations']);
            }
        }

        $out[] = '';
        $out[] = '## Root-cause hypotheses';
        $out[] = '';
        $n = 0;
        foreach ($findings as $f) {
            if (Finding::HYPOTHESIS !== $f->getKind()) {
                continue;
            }
            ++$n;
            $out[] = \sprintf('%d. **%s** — confidence %s, %s, source `%s`%s', $n, $f->getStatement(), $f->getConfidence(), $f->isVerified() ? 'verified' : 'REJECTED ('.$f->getGroundingReason().')', $f->getSource(), $cite($f->getCitations()));
            if ('' !== $f->getRationale()) {
                $out[] = '   - Reasoning: '.$f->getRationale();
            }
            if ([] !== $f->getContradictions()) {
                $out[] = '   - Contradicting evidence: '.implode(', ', $f->getContradictions());
            }
        }
        if (0 === $n) {
            $out[] = '_No hypothesis could be formed from the evidence._';
        }

        $inferences = array_filter($findings, static fn (Finding $f): bool => Finding::INFERENCE === $f->getKind());
        if ([] !== $inferences) {
            $out[] = '';
            $out[] = '## Inferences';
            $out[] = '';
            foreach ($inferences as $f) {
                $out[] = \sprintf('- %s (%s)%s', $f->getStatement(), $f->isVerified() ? 'verified' : 'rejected: '.$f->getGroundingReason(), $cite($f->getCitations()));
            }
        }

        foreach (['impact' => 'Impact'] as $k => $title) {
            if (!empty($r[$k])) {
                $out[] = '';
                $out[] = '## '.$title;
                $out[] = '';
                foreach ($r[$k] as $f) {
                    $out[] = '- '.$f['statement'].$cite($f['citations']);
                }
            }
        }
        if (!empty($r['recommended_fix'])) {
            $out[] = '';
            $out[] = '## Recommended fix';
            $out[] = '';
            $out[] = $r['recommended_fix'].(isset($r['fix_source']) ? "\n\n_Source: ".$r['fix_source'].'. Human review required._' : '');
        }
        if (!empty($r['reproduction'])) {
            $out[] = '';
            $out[] = '## Regression test strategy';
            $out[] = '';
            $out[] = $r['reproduction'];
        }
        if (!empty($r['next_steps'])) {
            $out[] = '';
            $out[] = '## Next steps';
            $out[] = '';
            foreach ($r['next_steps'] as $s) {
                $out[] = '- '.$s;
            }
        }
        if (!empty($r['limitations'])) {
            $out[] = '';
            $out[] = '## Limitations';
            $out[] = '';
            foreach ($r['limitations'] as $s) {
                $out[] = '- '.$s;
            }
        }

        $out[] = '';
        $out[] = '## Stages';
        $out[] = '';
        foreach ($steps as $s) {
            $out[] = \sprintf('- %s: %s%s', $s->getStage()->label(), $s->getStatus(), null !== ($s->getSummary() ?? $s->getError()) ? ' — '.($s->getError() ?? $s->getSummary()) : '');
        }

        $out[] = '';
        $out[] = '## Evidence';
        $out[] = '';
        foreach ($evidence as $ref => $e) {
            $out[] = \sprintf('- **%s** (%s) %s', $ref, $e->getKind(), $e->getExcerpt());
        }
        $out[] = '';
        $out[] = '_Generated by SYMTONY. Facts cite evidence from the application\'s own traces; hypotheses are assessments, not guarantees._';

        return implode("\n", $out)."\n";
    }
}
