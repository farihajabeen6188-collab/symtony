<?php

declare(strict_types=1);

namespace App\Application\TestLab;

use App\Application\Notification\Notifier;
use App\Application\Query\InvestigationQueries;
use App\Domain\Investigation\Investigation;
use App\Domain\TestLab\RegressionTest;
use App\Domain\TestLab\TestExecution;
use App\Domain\Workspace\User;
use App\Infrastructure\Persistence\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Proposes tests, records human review, and runs reviewed tests in the isolated runner (buggy, then fixed).
 */
final class TestLab
{
    public const VERIFIED = 'verified_reproduction';
    public const NOT_REPRODUCED = 'does_not_reproduce';
    public const FIX_NOT_CONFIRMED = 'fix_not_confirmed';
    public const RUNNER_ERROR = 'runner_error';
    public const RUNNING = 'running';
    public const UNVERIFIED = 'unverified_proposal';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvestigationQueries $investigations,
        private readonly TemplateTestWriter $templates,
        private readonly LlmTestWriter $llmWriter,
        private readonly SourceContext $source,
        private readonly StaticChecker $checker,
        private readonly MessageBusInterface $bus,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {
    }

    /**
     * @throws \DomainException when nothing can be generated honestly
     */
    public function propose(Investigation $inv, ?User $user): RegressionTest
    {
        $findings = $this->investigations->findings($inv);
        $evidence = $this->investigations->evidence($inv);
        $this->source->reset();

        $proposal = null;
        $why = 'No verified finding has a test template.';
        if ($this->llmWriter->isAvailable()) {
            try {
                $proposal = $this->llmWriter->write($inv, $findings, $evidence);
            } catch (\Throwable $e) {
                $why = 'The model could not write a test: '.$e->getMessage();
            }
        }
        $proposal ??= $this->templates->write($inv, $findings, $evidence);
        if (null === $proposal) {
            throw new \DomainException($why.($this->llmWriter->isAvailable() ? '' : ' Configure an AI provider to generate tests for other kinds of failures.'));
        }

        $issues = $this->checker->check($proposal->code, $proposal->className, (string) $this->source->config('test_namespace'), (string) $this->source->config('base_class'));
        $test = new RegressionTest($inv->getIncident(), $inv, $proposal->title, $proposal->purpose, $proposal->preconditions, $proposal->scenario, $proposal->expected,
            $proposal->code, $proposal->className, $proposal->citations, $this->source->filesRead(), $proposal->source, $issues);
        $this->em->persist($test);
        $this->audit->write($inv->getWorkspace(), null !== $user ? 'user' : 'system', $user?->getEmail() ?? 'test-lab', 'test.proposed', $test, ['reference' => $inv->getIncident()->getReference(), 'source' => $proposal->source, 'static_check' => [] === $issues ? 'passed' : 'rejected']);
        $this->em->flush();

        return $test;
    }

    public function review(RegressionTest $test, User $user): void
    {
        if (!$test->isReviewed()) {
            $test->markReviewed($user);
            $this->audit->userAction($test->getWorkspace(), 'test.reviewed', $test, ['reference' => $test->getIncident()->getReference()]);
            $this->em->flush();
        }
    }

    /**
     * Queues the buggy and fixed runs. Refuses unless reviewed, statically clean and the runner is up.
     *
     * @return list<TestExecution>
     */
    public function prove(RegressionTest $test, User $user): array
    {
        if (!$test->passedStaticChecks()) {
            throw new \DomainException('This test failed the static checks and cannot be run.');
        }
        if (!$test->isReviewed()) {
            throw new \DomainException('Review the code before running it.');
        }
        if (!$this->runnerOnline()) {
            throw new \DomainException('The isolated runner is offline. Start it with: docker compose up -d runner');
        }
        $jobs = (string) $this->source->config('jobs_dir');
        @chmod($jobs, 0777);
        $batch = Uuid::v7();
        $runs = [];
        foreach (['buggy', 'fixed'] as $variant) {
            $exec = new TestExecution($test, $variant, $batch, $user);
            $this->em->persist($exec);
            $dir = $jobs.'/'.$exec->getId()->toRfc4122();
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create the runner job directory.');
            }
            @chmod($dir, 0777);
            file_put_contents($dir.'/Test.php', $test->getCode());
            file_put_contents($dir.'/job.json', json_encode([
                'variant' => $variant,
                'class_name' => $test->getClassName(),
                'patch' => $this->source->config('reference_patch'),
                'timeout' => $this->source->config('timeout_seconds'),
            ], \JSON_THROW_ON_ERROR));
            $runs[] = $exec;
        }
        $this->audit->userAction($test->getWorkspace(), 'test.run_started', $test, ['reference' => $test->getIncident()->getReference(), 'batch' => $batch->toRfc4122()]);
        $this->em->flush();
        $this->bus->dispatch(new CollectTestResults($batch->toRfc4122(), 0), [new DelayStamp(2000)]);

        return $runs;
    }

    /** @return bool true when every run of the batch has a result */
    public function collect(string $batchId): bool
    {
        $jobs = (string) $this->source->config('jobs_dir');
        $runs = $this->em->getRepository(TestExecution::class)->findBy(['batchId' => Uuid::fromString($batchId)]);
        $done = true;
        foreach ($runs as $run) {
            if ($run->isFinished()) {
                continue;
            }
            $dir = $jobs.'/'.$run->getId()->toRfc4122();
            $file = $dir.'/result.json';
            if (is_file($file)) {
                $r = json_decode((string) file_get_contents($file), true);
                if (\is_array($r)) {
                    [$assertions, $failures, $errors] = $this->junitCounts((string) ($r['junit'] ?? ''));
                    $run->record((int) $r['exit_code'], (int) $r['duration_ms'], $assertions, $failures, $errors, (string) ($r['output'] ?? ''), (bool) ($r['timed_out'] ?? false), (string) ($r['setup'] ?? 'ok'));
                    continue;
                }
            }
            if (is_dir($dir.'/.claimed')) {
                $run->running();
            }
            $age = time() - $run->getCreatedAt()->getTimestamp();
            if ($age > (int) $this->source->config('timeout_seconds') + 120) {
                $run->abandon('The runner did not return a result in time. Is it running? docker compose logs runner');
                continue;
            }
            $done = false;
        }
        $this->em->flush();

        if ($done && [] !== $runs && 'proposed' !== $runs[0]->getVariant() && self::VERIFIED === $this->verdict($runs[0]->getTest())['status']) {
            $this->notifier->testVerified($runs[0]->getTest(), $batchId);
            $this->em->flush();
        }

        return $done;
    }

    public function runnerOnline(): bool
    {
        $beat = @file_get_contents((string) $this->source->config('jobs_dir').'/.heartbeat');

        return false !== $beat && time() - (int) trim($beat) <= 15;
    }

    /**
     * The test's status, derived only from real executions of its latest batch.
     *
     * @return array{status: string, buggy: ?TestExecution, fixed: ?TestExecution}
     */
    public function verdict(RegressionTest $test): array
    {
        // AI fix runs ("proposed") have their own verdict (FixLab); the test's proof is its buggy + reference-fix pair.
        $latest = $this->em->getRepository(TestExecution::class)->findOneBy(['test' => $test, 'variant' => ['buggy', 'fixed']], ['createdAt' => 'DESC']);
        if (!$latest instanceof TestExecution) {
            return ['status' => self::UNVERIFIED, 'buggy' => null, 'fixed' => null];
        }
        $runs = $this->em->getRepository(TestExecution::class)->findBy(['batchId' => $latest->getBatchId()]);
        $by = [];
        foreach ($runs as $r) {
            $by[$r->getVariant()] = $r;
        }
        $buggy = $by['buggy'] ?? null;
        $fixed = $by['fixed'] ?? null;
        $status = match (true) {
            null === $buggy || null === $fixed => self::RUNNER_ERROR,
            !$buggy->isFinished() || !$fixed->isFinished() => self::RUNNING,
            \in_array($buggy->getStatus(), ['error', 'timeout'], true) || \in_array($fixed->getStatus(), ['error', 'timeout'], true) => self::RUNNER_ERROR,
            'passed' === $buggy->getStatus() => self::NOT_REPRODUCED,
            'failed' === $buggy->getStatus() && 'passed' === $fixed->getStatus() => self::VERIFIED,
            default => self::FIX_NOT_CONFIRMED,
        };

        return ['status' => $status, 'buggy' => $buggy, 'fixed' => $fixed];
    }

    /** @return array{0: ?int, 1: ?int, 2: ?int} assertions, failures, errors */
    private function junitCounts(string $junit): array
    {
        if ('' === trim($junit)) {
            return [null, null, null];
        }
        $xml = @simplexml_load_string($junit);
        if (false === $xml) {
            return [null, null, null];
        }
        $suite = $xml->testsuite[0] ?? $xml;

        return [(int) $suite['assertions'], (int) $suite['failures'], (int) $suite['errors']];
    }
}
