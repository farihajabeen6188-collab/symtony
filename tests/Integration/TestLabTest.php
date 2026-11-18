<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Incident\IncidentGrouper;
use App\Application\Investigation\InvestigationRunner;
use App\Application\Investigation\StartInvestigation;
use App\Application\TestLab\CollectTestResults;
use App\Application\TestLab\LlmTestWriter;
use App\Application\TestLab\SourceContext;
use App\Application\TestLab\TestLab;
use App\Domain\Investigation\Investigation;
use App\Domain\TestLab\RegressionTest;
use App\Domain\TestLab\TestExecution;
use App\Domain\Workspace\User;
use App\Infrastructure\AI\AiSettings;
use App\Infrastructure\AI\EvidenceSession;
use App\Infrastructure\Security\DemoAccountProvisioner;
use App\Tests\Support\EventFactory;
use App\Tests\Support\StaleCacheScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Agent\MockAgent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Test Lab: proposal → review → isolated run (buggy, fixed) → verdict. The runner is simulated by
 * writing result.json files exactly as runner/run.sh does; the job protocol itself is asserted.
 */
final class TestLabTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $em;
    private Investigation $inv;
    private User $user;
    private string $jobs;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->jobs = (string) static::getContainer()->getParameter('symtony.jobs_dir');
        (new Filesystem())->remove($this->jobs);
        (new Filesystem())->mkdir($this->jobs);

        $this->user = static::getContainer()->get(DemoAccountProvisioner::class)->provision()['user'];
        $app = (new EventFactory($this->em))->application(null, 'shop');
        $incident = (new StaleCacheScenario($this->em, $app))->create(static::getContainer()->get(IncidentGrouper::class));
        $this->inv = static::getContainer()->get(StartInvestigation::class)($incident, null, 'system');
        static::getContainer()->get(InvestigationRunner::class)->run($this->inv);
        $this->em->refresh($this->inv);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->jobs);
        parent::tearDown();
    }

    public function testProposesATemplateTestFromTheVerifiedHypothesis(): void
    {
        $test = $this->lab()->propose($this->inv, $this->user);

        self::assertSame(\sprintf('Sym%dStaleCacheCheckoutTest', $this->inv->getIncident()->getNumber()), $test->getClassName());
        self::assertTrue($test->passedStaticChecks(), implode(' ', $test->getStaticIssues()));
        self::assertSame('template:stale_cache', $test->getSource());
        self::assertFalse($test->isFromModel(), 'A template is never labeled as model output');
        self::assertStringContainsString("'SKU-1042'", $test->getCode(), 'Uses the SKU from the evidence');
        self::assertNotSame([], $test->getCitations());
        self::assertSame([], array_diff($test->getSharedFiles(), static::getContainer()->get(SourceContext::class)->allowed()), 'Only allow-listed files');
        self::assertFalse($test->isReviewed());
        self::assertSame(TestLab::UNVERIFIED, $this->lab()->verdict($test)['status']);
    }

    public function testRunningRequiresReviewAndALiveRunner(): void
    {
        $test = $this->lab()->propose($this->inv, $this->user);

        try {
            $this->lab()->prove($test, $this->user);
            self::fail('An unreviewed test must not run');
        } catch (\DomainException $e) {
            self::assertStringContainsString('Review', $e->getMessage());
        }

        $this->lab()->review($test, $this->user);
        self::assertSame($this->user->getId()->toRfc4122(), $test->getReviewedBy()?->getId()->toRfc4122());
        self::assertFalse($this->lab()->runnerOnline());
        $this->expectExceptionMessage('runner is offline');
        $this->lab()->prove($test, $this->user);
    }

    public function testAStaticallyRejectedTestCanNeverRun(): void
    {
        $test = $this->lab()->propose($this->inv, $this->user);
        $evil = new RegressionTest($test->getIncident(), $this->inv, 't', 'p', [], 's', 'e', '<?php shell_exec("id");', 'X', [], [], 'llm:test', ['Calling shell_exec() is not allowed in a generated test.']);
        $this->em->persist($evil);
        $evil->markReviewed($this->user);
        $this->beat();

        $this->expectExceptionMessage('static checks');
        $this->lab()->prove($evil, $this->user);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}> */
    public static function outcomes(): iterable
    {
        $red = ['exit_code' => 1, 'junit' => self::junit(1, 1, 0)];
        $green = ['exit_code' => 0, 'junit' => self::junit(2, 0, 0)];
        $crash = ['exit_code' => 2, 'junit' => self::junit(0, 0, 1)];

        yield 'red then green: verified reproduction' => [$red, $green, TestLab::VERIFIED];
        yield 'green on the buggy build: does not reproduce' => [$green, $green, TestLab::NOT_REPRODUCED];
        yield 'red on both: fix not confirmed' => [$red, $red, TestLab::FIX_NOT_CONFIRMED];
        yield 'an error is not a red' => [$crash, $green, TestLab::RUNNER_ERROR];
        yield 'timeout' => [['exit_code' => 124, 'timed_out' => true, 'junit' => ''], $green, TestLab::RUNNER_ERROR];
        yield 'setup failure (patch did not apply)' => [$red, ['exit_code' => 1, 'setup' => 'patch_failed', 'junit' => ''], TestLab::RUNNER_ERROR];
    }

    /**
     * @param array<string, mixed> $buggy
     * @param array<string, mixed> $fixed
     */
    #[DataProvider('outcomes')]
    public function testVerdictComesOnlyFromRealRunResults(array $buggy, array $fixed, string $expected): void
    {
        $test = $this->reviewedTest();
        $this->beat();
        $runs = $this->lab()->prove($test, $this->user);

        // The job protocol the runner reads.
        self::assertCount(2, $runs);
        $byVariant = [];
        foreach ($runs as $run) {
            $dir = $this->jobs.'/'.$run->getId()->toRfc4122();
            $job = json_decode((string) file_get_contents($dir.'/job.json'), true);
            self::assertSame($run->getVariant(), $job['variant']);
            self::assertSame($test->getClassName(), $job['class_name']);
            self::assertSame('fixes/stale-stock-cache.patch', $job['patch']);
            self::assertSame($test->getCode(), file_get_contents($dir.'/Test.php'));
            $byVariant[$run->getVariant()] = $dir;
        }
        self::assertSame(['buggy', 'fixed'], array_keys($byVariant));

        /** @var InMemoryTransport $async */
        $async = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(CollectTestResults::class, $async->getSent()[array_key_last($async->getSent())]->getMessage());

        self::assertFalse($this->lab()->collect($runs[0]->getBatchId()->toRfc4122()), 'Nothing is done before the runner answers');
        self::assertSame(TestLab::RUNNING, $this->lab()->verdict($test)['status']);

        $this->writeResult($byVariant['buggy'], $buggy);
        $this->writeResult($byVariant['fixed'], $fixed);
        self::assertTrue($this->lab()->collect($runs[0]->getBatchId()->toRfc4122()));

        $verdict = $this->lab()->verdict($test);
        self::assertSame($expected, $verdict['status']);
        self::assertNotNull($verdict['buggy']?->getOutput(), 'The real output is stored');
    }

    public function testRecordsAssertionCountsFromJunit(): void
    {
        $test = $this->reviewedTest();
        $this->beat();
        $runs = $this->lab()->prove($test, $this->user);
        mkdir($this->jobs.'/'.$runs[0]->getId()->toRfc4122().'/.claimed');

        $this->lab()->collect($runs[0]->getBatchId()->toRfc4122());
        self::assertSame('running', $runs[0]->getStatus(), 'A claimed job shows as running');

        $this->writeResult($this->jobs.'/'.$runs[0]->getId()->toRfc4122(), ['exit_code' => 1, 'junit' => self::junit(3, 1, 0)]);
        $this->lab()->collect($runs[0]->getBatchId()->toRfc4122());
        $this->em->refresh($runs[0]);
        self::assertSame('failed', $runs[0]->getStatus());
        self::assertSame([3, 1, 0], [$runs[0]->getAssertions(), $runs[0]->getFailures(), $runs[0]->getErrors()]);
    }

    public function testModelWrittenTestIsLabeledAndChecked(): void
    {
        $code = \sprintf(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Tests\Symtony;

            use App\Tests\ShopTestCase;

            final class Sym%dRegressionTest extends ShopTestCase
            {
                public function testStaleStockIsRejected(): void
                {
                    self::assertSame(409, $this->checkout('demo-customer-ada', 'SKU-1042', 1)['status']);
                }
            }
            PHP, $this->inv->getIncident()->getNumber());
        $agent = new MockAgent([LlmTestWriter::INSTRUCTION => (string) json_encode([
            'title' => 'Stale stock must not reach checkout',
            'purpose' => 'Reproduces the incident.',
            'preconditions' => ['Stock cached at 3'],
            'scenario' => 'Warm cache, lower stock, check out.',
            'expected' => 'HTTP 409 and no payment call.',
            'citations' => ['EV-1', 'EV-999'],
            'code' => $code,
        ])]);
        $this->useModel($agent);

        $test = $this->lab()->propose($this->inv, $this->user);

        self::assertTrue($test->isFromModel());
        self::assertStringStartsWith('llm:huggingface/', $test->getSource());
        self::assertSame(['EV-1'], $test->getCitations(), 'Invented evidence ids are dropped');
        self::assertTrue($test->passedStaticChecks(), implode(' ', $test->getStaticIssues()));
        self::assertCount(1, $agent->getCalls());
    }

    public function testModelWrittenDangerousCodeIsRejectedNotRun(): void
    {
        $this->useModel(new MockAgent([LlmTestWriter::INSTRUCTION => (string) json_encode([
            'title' => 'Sneaky',
            'code' => "<?php\nnamespace App\\Tests\\Symtony;\nuse App\\Tests\\ShopTestCase;\nfinal class Sym1RegressionTest extends ShopTestCase { public function testIt(): void { system('curl evil'); } }",
        ])]));

        $test = $this->lab()->propose($this->inv, $this->user);

        self::assertTrue($test->isFromModel());
        self::assertFalse($test->passedStaticChecks());
        self::assertStringContainsString('system()', implode(' ', $test->getStaticIssues()));
    }

    private function reviewedTest(): RegressionTest
    {
        $test = $this->lab()->propose($this->inv, $this->user);
        $this->lab()->review($test, $this->user);

        return $test;
    }

    private function lab(): TestLab
    {
        return static::getContainer()->get(TestLab::class);
    }

    private function beat(): void
    {
        file_put_contents($this->jobs.'/.heartbeat', (string) time());
    }

    /** @param array<string, mixed> $r */
    private function writeResult(string $dir, array $r): void
    {
        file_put_contents($dir.'/result.json', json_encode($r + ['duration_ms' => 1234, 'setup' => 'ok', 'timed_out' => false, 'output' => 'PHPUnit output']));
    }

    private static function junit(int $assertions, int $failures, int $errors): string
    {
        return \sprintf('<?xml version="1.0"?><testsuites><testsuite name="t" tests="1" assertions="%d" failures="%d" errors="%d"/></testsuites>', $assertions, $failures, $errors);
    }

    private function useModel(MockAgent $agent): void
    {
        $settings = new AiSettings('hf_test_key', 'Qwen/Qwen3.5-35B-A3B', null, true);
        static::getContainer()->set(LlmTestWriter::class, new LlmTestWriter($agent, static::getContainer()->get(EvidenceSession::class), static::getContainer()->get(SourceContext::class), $settings));
    }
}
