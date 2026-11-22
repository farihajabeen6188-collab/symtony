<?php

declare(strict_types=1);

namespace App\Application\TestLab;

use App\Application\Query\InvestigationQueries;
use App\Domain\TestLab\FixProposal;
use App\Domain\TestLab\RegressionTest;
use App\Domain\TestLab\TestExecution;
use App\Domain\Workspace\User;
use App\Infrastructure\Persistence\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * AI fix proposals: the model writes a diff, static checks and a human review gate it, and the isolated
 * runner applies it to the buggy build and runs the verified regression test. Only a green run counts.
 */
final class FixLab
{
    public const VERIFIED = 'fix_verified';
    public const REJECTED = 'fix_rejected';
    public const NOT_APPLIED = 'fix_not_applied';
    public const RUNNER_ERROR = 'runner_error';
    public const RUNNING = 'running';
    public const UNVERIFIED = 'unverified_proposal';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvestigationQueries $investigations,
        private readonly LlmFixWriter $writer,
        private readonly FixPatchChecker $checker,
        private readonly SourceContext $source,
        private readonly TestLab $testLab,
        private readonly MessageBusInterface $bus,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Why a fix cannot be proposed for this test right now, or null when it can. */
    public function cannotPropose(RegressionTest $test): ?string
    {
        if (TestLab::VERIFIED !== $this->testLab->verdict($test)['status']) {
            return 'Prove the test first: a fix can only be judged by a test that fails on the bug.';
        }
        if (!$this->writer->isAvailable()) {
            return 'Connect an AI provider (SYMTONY_AI_PROVIDER and its key in .env.local) to propose fixes.';
        }
        if (null === $test->getInvestigation()) {
            return 'This test is not linked to an investigation, so there is no evidence to fix from.';
        }

        return null;
    }

    /** @throws \DomainException */
    public function propose(RegressionTest $test, ?User $user): FixProposal
    {
        if (null !== ($why = $this->cannotPropose($test))) {
            throw new \DomainException($why);
        }
        $inv = $test->getInvestigation();
        \assert(null !== $inv);
        $this->source->reset();
        try {
            $draft = $this->writer->write($test, $this->investigations->findings($inv), $this->investigations->evidence($inv));
        } catch (\Throwable $e) {
            throw new \DomainException('The model could not propose a fix: '.$e->getMessage(), 0, $e);
        }

        $check = $this->checker->check($draft['diff'], $this->source->allowed());
        $fix = new FixProposal($test, $draft['summary'], $draft['diff'], $draft['citations'], $check['files'], $this->source->filesRead(), $this->writer->engine(), $check['issues']);
        $this->em->persist($fix);
        $this->audit->write($test->getWorkspace(), null !== $user ? 'user' : 'system', $user?->getEmail() ?? 'test-lab', 'fix.proposed', $test,
            ['reference' => $test->getIncident()->getReference(), 'source' => $fix->getSource(), 'static_check' => $fix->passedStaticChecks() ? 'passed' : 'rejected']);
        $this->em->flush();

        return $fix;
    }

    public function review(FixProposal $fix, User $user): void
    {
        if (!$fix->isReviewed()) {
            $fix->markReviewed($user);
            $this->audit->userAction($fix->getWorkspace(), 'fix.reviewed', $fix->getTest(), ['reference' => $fix->getTest()->getIncident()->getReference()]);
            $this->em->flush();
        }
    }

    /** Applies the fix to the buggy build in the isolated runner and runs the verified test. */
    public function prove(FixProposal $fix, User $user): TestExecution
    {
        if (!$fix->passedStaticChecks()) {
            throw new \DomainException('This fix failed the static checks and cannot be run.');
        }
        if (!$fix->isReviewed()) {
            throw new \DomainException('Review the change before running it.');
        }
        if (TestLab::VERIFIED !== $this->testLab->verdict($fix->getTest())['status']) {
            throw new \DomainException('The test is no longer a verified reproduction, so it cannot judge a fix.');
        }
        if (!$this->testLab->runnerOnline()) {
            throw new \DomainException('The isolated runner is offline. Start it with: docker compose up -d runner');
        }

        $test = $fix->getTest();
        $batch = Uuid::v7();
        $exec = new TestExecution($test, 'proposed', $batch, $user);
        $this->em->persist($exec);
        $jobs = (string) $this->source->config('jobs_dir');
        @chmod($jobs, 0777);
        $dir = $jobs.'/'.$exec->getId()->toRfc4122();
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the runner job directory.');
        }
        @chmod($dir, 0777);
        file_put_contents($dir.'/Test.php', $test->getCode());
        file_put_contents($dir.'/fix.patch', $fix->getDiff());
        file_put_contents($dir.'/job.json', json_encode([
            'variant' => 'proposed',
            'class_name' => $test->getClassName(),
            'timeout' => $this->source->config('timeout_seconds'),
        ], \JSON_THROW_ON_ERROR));
        $fix->startBatch($batch);
        $this->audit->userAction($fix->getWorkspace(), 'fix.run_started', $test, ['reference' => $test->getIncident()->getReference(), 'batch' => $batch->toRfc4122()]);
        $this->em->flush();
        $this->bus->dispatch(new CollectTestResults($batch->toRfc4122(), 0), [new DelayStamp(2000)]);

        return $exec;
    }

    /**
     * Derived only from the real run of the latest batch.
     *
     * @return array{status: string, run: ?TestExecution}
     */
    public function verdict(FixProposal $fix): array
    {
        if (null === $fix->getBatchId()) {
            return ['status' => self::UNVERIFIED, 'run' => null];
        }
        $run = $this->em->getRepository(TestExecution::class)->findOneBy(['batchId' => $fix->getBatchId()]);
        if (!$run instanceof TestExecution) {
            return ['status' => self::RUNNER_ERROR, 'run' => null];
        }
        if (!$run->isFinished()) {
            $this->testLab->collect($fix->getBatchId()->toRfc4122());
        }

        $status = match (true) {
            !$run->isFinished() => self::RUNNING,
            'passed' === $run->getStatus() => self::VERIFIED,
            'failed' === $run->getStatus() => self::REJECTED,
            str_contains((string) $run->getOutput(), 'could not be applied') => self::NOT_APPLIED,
            default => self::RUNNER_ERROR,
        };

        return ['status' => $status, 'run' => $run];
    }

    public function latest(RegressionTest $test): ?FixProposal
    {
        return $this->em->getRepository(FixProposal::class)->findOneBy(['test' => $test], ['createdAt' => 'DESC']);
    }
}
