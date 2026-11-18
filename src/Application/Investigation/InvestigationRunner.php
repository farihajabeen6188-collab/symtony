<?php

declare(strict_types=1);

namespace App\Application\Investigation;

use App\Application\Investigation\Analyzer\Analyzer;
use App\Application\Investigation\Analyzer\StaleCacheAnalyzer;
use App\Application\Knowledge\KnowledgeRetriever;
use App\Application\Knowledge\SimilarIncident;
use App\Application\Timeline\Timeline;
use App\Application\Timeline\TimelineBuilder;
use App\Domain\Event\Event;
use App\Domain\Investigation\EvidenceItem;
use App\Domain\Investigation\Finding;
use App\Domain\Investigation\Investigation;
use App\Domain\Investigation\InvestigationStep;
use App\Domain\Investigation\Stage;
use App\Infrastructure\AI\AiSettings;
use App\Infrastructure\AI\LlmInvestigator;
use App\Infrastructure\Persistence\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Runs an investigation stage by stage. Every stage is committed when it really starts and ends, so the
 * UI shows true progress. A failing model call fails only its stage; the heuristic report still completes.
 */
final class InvestigationRunner
{
    /** @param iterable<Analyzer> $analyzers */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TimelineBuilder $timelines,
        private readonly EvidencePackageBuilder $evidence,
        #[AutowireIterator('symtony.analyzer')] private readonly iterable $analyzers,
        private readonly KnowledgeRetriever $knowledge,
        private readonly AiSettings $ai,
        private readonly LlmInvestigator $llm,
        private readonly GroundingValidator $validator,
        private readonly ReportComposer $composer,
        private readonly AuditLogger $audit,
        #[Target('incident')] private readonly WorkflowInterface $workflow,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(Investigation $investigation): void
    {
        if ($investigation->getStatus()->isFinal()) {
            return; // idempotent on redelivery
        }
        $investigation->start();
        $this->em->flush();

        $incident = $investigation->getIncident();
        $timeline = null;
        $package = null;
        $drafts = [];
        $history = [];
        $llmResult = null;
        $limitations = [];

        try {
            // 1. Evidence
            $this->stage($investigation, Stage::CollectEvidence, function () use ($investigation, $incident, &$timeline): string {
                $timeline = $this->timelines->forIncident($incident);
                $focus = null !== $timeline ? $this->focusEvent($timeline) : null;
                $investigation->focus($focus?->getId(), $timeline?->traceId);

                return null === $timeline
                    ? \sprintf('Loaded %s (%d occurrences); no stored occurrence to examine.', $incident->getReference(), $incident->getOccurrences())
                    : \sprintf('Loaded %s (%d occurrences); examining the occurrence at %s UTC%s.', $incident->getReference(), $incident->getOccurrences(),
                        $focus?->getOccurredAt()->format('Y-m-d H:i:s.v') ?? '?', null !== $timeline->traceId ? ' in trace '.substr($timeline->traceId, 0, 16).'…' : '');
            });

            // 2. Timeline → numbered, redacted evidence
            $this->stage($investigation, Stage::ReconstructTimeline, function () use ($investigation, $incident, &$timeline, &$package, &$limitations): string {
                $package = $this->evidence->build($investigation, $incident, $timeline);
                foreach ($package->all() as $item) {
                    $this->em->persist($item);
                }
                $context = \count($package->ofKind('context'));
                if (null !== $timeline && $timeline->truncated) {
                    $limitations[] = \sprintf('The trace has %d events; only the first %d were examined.', $timeline->total, \count($timeline->items));
                }
                if ($context > 0) {
                    $limitations[] = \sprintf('%d evidence items come from other traces and are linked by timing only (inferred).', $context);
                }
                if (null === $timeline || null === $timeline->traceId) {
                    $limitations[] = 'Occurrences carry no trace id, so the surrounding request could not be reconstructed.';
                }

                return null === $timeline
                    ? 'No timeline: only the incident summary is available as evidence.'
                    : \sprintf('%d events reconstructed → %d evidence items (EV-1…EV-%d), %d inferred from timing.', \count($timeline->items), \count($package->all()), \count($package->all()), $context);
            });

            // 3. Deterministic analyzers
            $this->stage($investigation, Stage::AnalyzeHeuristics, function () use (&$package, &$drafts): string {
                $ran = [];
                foreach ($this->analyzers as $analyzer) {
                    $found = $analyzer->analyze($package);
                    $ran[] = $analyzer->name().' ('.\count($found).')';
                    array_push($drafts, ...$found);
                }

                return \sprintf('%d findings from %d analyzers: %s.', \count($drafts), \count($ran), implode(', ', $ran));
            });

            // 4. History
            $this->stage($investigation, Stage::ReviewHistory, function () use ($investigation, $incident, &$package, &$history, &$drafts): string {
                $history = $this->knowledge->similarTo($incident, $this->historyTerms($package));
                foreach ($history as $h) {
                    $ref = $package->nextRef();
                    $item = new EvidenceItem($investigation, $ref, 'history', null,
                        \sprintf('%s (resolved): %s', $h->incident->getReference(), $h->incident->getTitle()),
                        mb_substr(\sprintf('Resolved incident %s "%s". Matched by %s (score %s; shared terms: %s). Resolution: %s',
                            $h->incident->getReference(), $h->incident->getTitle(), $h->method, $h->score, implode(', ', $h->sharedTerms) ?: 'none', $h->resolution ?? 'none recorded'), 0, 900),
                        ['type' => 'history', 'incident' => $h->incident->getReference(), 'score' => $h->score, 'method' => $h->method, 'shared_terms' => $h->sharedTerms]);
                    $package->add($item);
                    $this->em->persist($item);
                }
                $stale = array_values(array_filter($drafts, static fn (FindingDraft $d): bool => StaleCacheAnalyzer::SOURCE === $d->source));
                if ([] !== $history && [] !== $stale && null !== $history[0]->resolution && str_contains(strtolower($history[0]->resolution), 'cache')) {
                    $h = $history[0];
                    $ref = $package->all()[array_key_last($package->all())]->getRef();
                    foreach ($package->all() as $r => $item) {
                        if ('history' === $item->getKind() && ($item->getData()['incident'] ?? null) === $h->incident->getReference()) {
                            $ref = $r;
                        }
                    }
                    $drafts[] = new FindingDraft('inference',
                        \sprintf('A previous incident, %s, also came from a stale cache and was fixed by invalidating the cache on write and recomputing inside the transaction.', $h->incident->getReference()),
                        'Keyword match between this incident\'s evidence and a resolved incident\'s resolution note. Similar history, not proof.',
                        'low', [$ref, ...$stale[0]->citations], 'heuristic:history', weight: 12);
                }

                return [] === $history
                    ? \sprintf('No similar resolved incidents found (%s search).', $this->knowledge->method())
                    : \sprintf('%d similar resolved incident(s) via %s search: %s.', \count($history), $this->knowledge->method(),
                        implode(', ', array_map(static fn (SimilarIncident $h): string => $h->incident->getReference().' (score '.$h->score.')', $history)));
            });

            // 5. Model
            $step = $this->step($investigation, Stage::GenerateHypotheses);
            if (!$this->ai->isConfigured()) {
                $step->skip('Skipped: '.$this->ai->whyNotConfigured().' The report is built from the deterministic analyzers only.');
                $limitations[] = 'No language model was used: '.$this->ai->whyNotConfigured();
                $this->em->flush();
            } else {
                $investigation->useEngine($this->ai->engineLabel(), LlmInvestigator::PROMPT_VERSION);
                $step->begin();
                $this->em->flush();
                try {
                    $llmResult = $this->withRetry(fn (): array => $this->llm->investigate($package, $drafts, $history));
                    array_push($drafts, ...$llmResult['drafts']);
                    $investigation->recordUsage($llmResult['usage']);
                    $step->done(\sprintf('%s proposed %d findings. Tools used: %s.', $this->ai->model(), \count($llmResult['drafts']), implode(', ', $llmResult['tools']) ?: 'none'));
                } catch (\Throwable $e) {
                    $step->failWith(\sprintf('%s: %s', (new \ReflectionClass($e))->getShortName(), $e->getMessage()));
                    $limitations[] = 'The model call failed, so this report contains the deterministic findings only.';
                    $this->logger->warning('Investigation model call failed: {error}', ['error' => $e->getMessage()]);
                }
                $this->em->flush();
            }

            // 6. Ground every finding
            $findings = [];
            $this->stage($investigation, Stage::ValidateFindings, function () use ($investigation, &$drafts, &$package, &$findings): string {
                usort($drafts, static fn (FindingDraft $a, FindingDraft $b): int => $b->weight <=> $a->weight);
                foreach ($drafts as $rank => $d) {
                    $finding = new Finding($investigation, $d->kind, $d->statement, $d->rationale, $d->confidence, $d->citations, $d->source, $rank, $d->contradictions);
                    $this->validator->validate($finding, $package);
                    $this->em->persist($finding);
                    $findings[] = $finding;
                }
                $verified = \count(array_filter($findings, static fn (Finding $f): bool => $f->isVerified()));

                return \sprintf('%d of %d claims verified against evidence; %d rejected.', $verified, \count($findings), \count($findings) - $verified);
            });

            // 7. Report
            $this->stage($investigation, Stage::ProduceReport, function () use ($investigation, $incident, &$package, &$findings, &$llmResult, &$limitations): string {
                $limitations[] = 'No source code was shared with this investigation; recommendations are based on runtime evidence only.';
                $composed = $this->composer->compose($package, $findings, $llmResult['summary'] ?? null, $llmResult['report'] ?? [], $limitations);
                $investigation->complete($composed['summary'], $composed['report']);

                $hasHypothesis = [] !== array_filter($findings, static fn (Finding $f): bool => $f->isVerified() && Finding::HYPOTHESIS === $f->getKind());
                if ($hasHypothesis && $this->workflow->can($incident, 'identify')) {
                    $this->workflow->apply($incident, 'identify');
                }
                $this->audit->write($investigation->getWorkspace(), 'system', 'investigator', 'investigation.completed', $investigation,
                    ['reference' => $incident->getReference(), 'investigation' => $investigation->getShortId(), 'engine' => $investigation->getEngine()]);

                return $hasHypothesis ? 'Report ready with a verified root-cause hypothesis.' : 'Report ready; no hypothesis could be verified against the evidence.';
            });
        } catch (StageFailed $e) {
            $investigation->fail($e->getMessage());
            $this->audit->write($investigation->getWorkspace(), 'system', 'investigator', 'investigation.failed', $investigation, ['reference' => $incident->getReference(), 'error' => $e->getMessage()]);
            $this->em->flush();
        }
    }

    /**
     * @param callable(): string $work
     */
    private function stage(Investigation $investigation, Stage $stage, callable $work): void
    {
        $step = $this->step($investigation, $stage);
        $step->begin();
        $this->em->flush();
        try {
            $summary = $work();
            $step->done($summary);
            $this->em->flush();
        } catch (\Throwable $e) {
            $step->failWith(\sprintf('%s: %s', (new \ReflectionClass($e))->getShortName(), $e->getMessage()));
            $this->em->flush();
            throw new StageFailed(\sprintf('%s failed: %s', $stage->label(), $e->getMessage()), 0, $e);
        }
    }

    private function step(Investigation $investigation, Stage $stage): InvestigationStep
    {
        $step = $this->em->getRepository(InvestigationStep::class)->findOneBy(['investigation' => $investigation, 'stage' => $stage]);
        if (!$step instanceof InvestigationStep) {
            $step = new InvestigationStep($investigation, $stage);
            $this->em->persist($step);
        }

        return $step;
    }

    /**
     * Transient provider errors (timeouts, 5xx, rate limits) are retried twice with backoff.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function withRetry(callable $call): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $call();
            } catch (\App\Infrastructure\AI\InvalidModelOutput $e) {
                throw $e; // not transient
            } catch (\Throwable $e) {
                $transient = 1 === preg_match('/timeout|timed out|429|50[0234]|temporar|unavailable|rate/i', $e->getMessage());
                if (!$transient || ++$attempt > 2) {
                    throw $e;
                }
                usleep(400_000 * $attempt * $attempt);
            }
        }
    }

    private function focusEvent(Timeline $timeline): ?Event
    {
        foreach ($timeline->items as $item) {
            if ($item->isProven() && null !== $item->event->getExceptionClass()) {
                return $item->event;
            }
        }

        return $timeline->items[0]->event ?? null;
    }

    /** @return list<string> */
    private function historyTerms(?EvidencePackage $package): array
    {
        $terms = [];
        foreach ($package?->all() ?? [] as $item) {
            $d = $item->getData();
            foreach (['component', 'db_table', 'type'] as $k) {
                if (isset($d[$k]) && \is_string($d[$k])) {
                    $terms[] = $d[$k];
                }
            }
            if (true === ($d['cache_hit'] ?? null)) {
                array_push($terms, 'cache', 'stale', 'invalidate', 'cached');
            }
        }

        return array_values(array_unique($terms));
    }
}
