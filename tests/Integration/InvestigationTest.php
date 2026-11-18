<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Incident\IncidentGrouper;
use App\Application\Investigation\EvidencePackageBuilder;
use App\Application\Investigation\GroundingValidator;
use App\Application\Investigation\InvestigationRunner;
use App\Application\Investigation\StartInvestigation;
use App\Application\Timeline\TimelineBuilder;
use App\Domain\Application\Application;
use App\Domain\Incident\Incident;
use App\Domain\Incident\IncidentStatus;
use App\Domain\Investigation\Finding;
use App\Domain\Investigation\Investigation;
use App\Domain\Investigation\InvestigationStatus;
use App\Domain\Investigation\InvestigationStep;
use App\Infrastructure\AI\AiSettings;
use App\Infrastructure\AI\EvidenceSession;
use App\Infrastructure\AI\InvalidModelOutput;
use App\Infrastructure\AI\LlmInvestigator;
use App\Infrastructure\AI\LlmReportParser;
use App\Tests\Support\EventFactory;
use App\Tests\Support\StaleCacheScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\MockAgent;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The demo failure, end to end through the investigation pipeline (heuristics, grounding, model via MockAgent).
 */
final class InvestigationTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $em;
    private Application $app;
    private Incident $incident;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->app = (new EventFactory($this->em))->application(null, 'shop');
        $this->incident = (new StaleCacheScenario($this->em, $this->app))->create(static::getContainer()->get(IncidentGrouper::class));
    }

    public function testHeuristicInvestigationWithoutAModel(): void
    {
        $inv = $this->runInvestigation();

        self::assertSame(InvestigationStatus::Completed, $inv->getStatus());
        self::assertSame('heuristic', $inv->getEngine());
        $steps = $this->steps($inv);
        self::assertSame('skipped', $steps['generate_hypotheses']->getStatus());
        self::assertStringContainsString('No AI provider configured', $steps['generate_hypotheses']->getSummary());
        foreach (['collect_evidence', 'reconstruct_timeline', 'analyze_heuristics', 'review_history', 'validate_findings', 'produce_report'] as $stage) {
            self::assertSame('done', $steps[$stage]->getStatus(), $stage);
            self::assertNotNull($steps[$stage]->getFinishedAt());
        }

        $findings = $this->em->getRepository(Finding::class)->findBy(['investigation' => $inv], ['rank' => 'ASC']);
        $top = $findings[0];
        self::assertSame('hypothesis', $top->getKind());
        self::assertSame('heuristic:stale_cache', $top->getSource());
        self::assertTrue($top->isVerified());
        self::assertStringContainsString('6.1 s old', $top->getStatement());
        self::assertStringContainsString('from 3 to 1', $top->getStatement());

        $sources = array_map(static fn (Finding $f): string => $f->getSource(), $findings);
        self::assertContains('heuristic:constraint_rollback', $sources);
        self::assertContains('heuristic:side_effect_before_failure', $sources);
        self::assertContains('heuristic:deploy', $sources);
        foreach ($findings as $f) {
            self::assertTrue($f->isVerified(), 'Heuristic findings must ground: '.$f->getStatement().' → '.$f->getGroundingReason());
        }

        self::assertStringContainsString('auth_9f3a1c', json_encode($inv->getReport()), 'Impact names the orphaned authorization');
        self::assertArrayHasKey('recommended_fix', $inv->getReport());
        self::assertSame('analyzer template (heuristic)', $inv->getReport()['fix_source']);
        $this->em->refresh($this->incident);
        self::assertSame(IncidentStatus::Identified, $this->incident->getStatus(), 'A verified hypothesis marks the incident identified');
    }

    public function testModelFindingsAreParsedGroundedAndNeverSeeSecrets(): void
    {
        $agent = new MockAgent([LlmInvestigator::INSTRUCTION => (string) json_encode([
            'summary' => 'Checkout used a stale cached stock value; the write then hit the stock_non_negative constraint after the card was authorized.',
            'hypotheses' => [
                ['statement' => 'A stale stock cache let checkout proceed for stock that no longer existed.', 'rationale' => 'Cache hit at EV-7 predates the warehouse write.', 'confidence' => 'high', 'supporting' => ['EV-7', 'EV-3', 'EV-10'], 'contradicting' => []],
                ['statement' => 'The payment API is slow and caused a timeout.', 'rationale' => 'Invented.', 'confidence' => 'medium', 'supporting' => ['EV-99']],
                ['statement' => 'The payment call took 900 ms.', 'rationale' => 'Wrong number.', 'confidence' => 'low', 'supporting' => ['EV-8']],
            ],
            'facts' => [['statement' => 'The transaction was rolled back.', 'evidence' => ['EV-11']]],
            'recommended_fix' => 'Reserve stock atomically before authorizing payment.',
            'limitations' => ['No source code was available.'],
        ])]);
        $this->useModel($agent);

        $inv = $this->runInvestigation();

        self::assertSame(InvestigationStatus::Completed, $inv->getStatus());
        self::assertStringStartsWith('llm:huggingface/', $inv->getEngine());
        self::assertSame('done', $this->steps($inv)['generate_hypotheses']->getStatus());

        $model = array_values(array_filter($this->em->getRepository(Finding::class)->findBy(['investigation' => $inv]), static fn (Finding $f): bool => $f->isFromModel()));
        $byStatement = [];
        foreach ($model as $f) {
            $byStatement[$f->getStatement()] = $f;
        }
        self::assertTrue($byStatement['A stale stock cache let checkout proceed for stock that no longer existed.']->isVerified());
        self::assertFalse($byStatement['The payment API is slow and caused a timeout.']->isVerified());
        self::assertStringContainsString('EV-99', $byStatement['The payment API is slow and caused a timeout.']->getGroundingReason());
        self::assertFalse($byStatement['The payment call took 900 ms.']->isVerified(), 'Invented durations are rejected');
        self::assertSame('Reserve stock atomically before authorizing payment.', $inv->getReport()['recommended_fix']);
        self::assertSame('model', $inv->getReport()['fix_source']);

        // What the model received: redacted evidence only.
        $call = $agent->getCalls()[0];
        $sent = '';
        foreach ($call['messages']->getMessages() as $message) {
            $content = $message->getContent();
            foreach (\is_array($content) ? $content : [$content] as $part) {
                $sent .= \is_string($part) ? $part : (method_exists($part, 'getText') ? $part->getText() : '');
            }
        }
        self::assertGreaterThan(500, \strlen($sent), 'The evidence brief was sent');
        self::assertStringNotContainsString('topsecret', $sent);
        self::assertStringContainsString('EV-1', $sent);
    }

    public function testMalformedModelOutputFailsOnlyItsStage(): void
    {
        $this->useModel(new MockAgent([LlmInvestigator::INSTRUCTION => 'I think it is the cache, probably.']));

        $inv = $this->runInvestigation();
        $steps = $this->steps($inv);

        self::assertSame(InvestigationStatus::Completed, $inv->getStatus(), 'Heuristic report still completes');
        self::assertSame('failed', $steps['generate_hypotheses']->getStatus());
        self::assertStringContainsString('not valid JSON', (string) $steps['generate_hypotheses']->getError());
        self::assertContains('The model call failed, so this report contains the deterministic findings only.', $inv->getReport()['limitations']);
    }

    public function testParserRejectsMissingFields(): void
    {
        $this->expectException(InvalidModelOutput::class);
        (new LlmReportParser())->parse('{"hypotheses": []}', 'llm:test');
    }

    public function testValidatorRejectsFactsBasedOnlyOnInferredContext(): void
    {
        $inv = new Investigation($this->incident, null);
        $this->em->persist($inv);
        $package = static::getContainer()->get(EvidencePackageBuilder::class)->build($inv, $this->incident, static::getContainer()->get(TimelineBuilder::class)->forIncident($this->incident));
        $contextRef = array_key_first(array_filter($package->all(), static fn ($i): bool => 'context' === $i->getKind()));
        self::assertNotNull($contextRef);

        $fact = new Finding($inv, 'fact', 'Stock was changed by the warehouse job.', '', 'high', [$contextRef], 'llm:test');
        static::getContainer()->get(GroundingValidator::class)->validate($fact, $package);
        self::assertFalse($fact->isVerified());

        $noCitation = new Finding($inv, 'hypothesis', 'Something broke.', '', 'low', [], 'llm:test');
        static::getContainer()->get(GroundingValidator::class)->validate($noCitation, $package);
        self::assertSame('Cites no evidence.', $noCitation->getGroundingReason());
    }

    private function runInvestigation(): Investigation
    {
        $inv = static::getContainer()->get(StartInvestigation::class)($this->incident, null, 'system');
        static::getContainer()->get(InvestigationRunner::class)->run($inv);
        $this->em->refresh($inv);

        return $inv;
    }

    private function useModel(MockAgent $agent): void
    {
        $settings = new AiSettings('hf_test_key', 'Qwen/Qwen3.5-35B-A3B', null, true);
        static::getContainer()->set(AiSettings::class, $settings);
        static::getContainer()->set(LlmInvestigator::class, new LlmInvestigator($agent, static::getContainer()->get(EvidenceSession::class), new LlmReportParser(), $settings));
    }

    /** @return array<string, InvestigationStep> */
    private function steps(Investigation $inv): array
    {
        $out = [];
        foreach ($this->em->getRepository(InvestigationStep::class)->findBy(['investigation' => $inv]) as $s) {
            $this->em->refresh($s);
            $out[$s->getStage()->value] = $s;
        }

        return $out;
    }
}
