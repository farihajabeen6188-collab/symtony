<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Incident\IncidentGrouper;
use App\Application\Investigation\InvestigationRunner;
use App\Application\Investigation\StartInvestigation;
use App\Application\TestLab\FixLab;
use App\Application\TestLab\LlmFixWriter;
use App\Application\TestLab\SourceContext;
use App\Application\TestLab\TestLab;
use App\Domain\Investigation\Investigation;
use App\Domain\TestLab\RegressionTest;
use App\Domain\Workspace\User;
use App\Domain\Workspace\WorkspaceMembership;
use App\Domain\Workspace\WorkspaceRole;
use App\Infrastructure\AI\AiSettings;
use App\Infrastructure\AI\EvidenceSession;
use App\Infrastructure\Security\DemoAccountProvisioner;
use App\Tests\Support\EventFactory;
use App\Tests\Support\StaleCacheScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\MockAgent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AI fix: the model proposes a diff for a verified regression test; static checks and review gate it;
 * the isolated runner (simulated here exactly as runner/run.sh answers) decides whether it is verified.
 */
final class FixLabTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $em;
    private User $user;
    private Investigation $inv;
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
        $this->em->persist(new WorkspaceMembership($this->user, $app->getWorkspace(), WorkspaceRole::Member));
        $this->em->flush();
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

    public function testAFixNeedsAVerifiedTestAndAModel(): void
    {
        $lab = static::getContainer()->get(TestLab::class);
        $test = $lab->propose($this->inv, $this->user);
        self::assertStringContainsString('Prove the test first', (string) $this->fixes()->cannotPropose($test));

        $this->verify($test);
        self::assertStringContainsString('Connect an AI provider', (string) $this->fixes()->cannotPropose($test), 'No key in the test environment: no fake fix');
        $this->expectException(\DomainException::class);
        $this->fixes()->propose($test, $this->user);
    }

    public function testModelFixIsCheckedReviewedAndProvenInTheIsolatedRunner(): void
    {
        $test = $this->verifiedTest();
        $this->useModel($this->referenceDiff());

        $fix = $this->fixes()->propose($test, $this->user);
        self::assertTrue($fix->passedStaticChecks(), implode(' ', $fix->getStaticIssues()));
        self::assertSame('llm:huggingface/Qwen/Qwen3.5-35B-A3B', $fix->getSource(), 'Model output is labeled as model output');
        self::assertContains('src/Checkout/CheckoutService.php', $fix->getFiles());
        self::assertSame(FixLab::UNVERIFIED, $this->fixes()->verdict($fix)['status']);

        try {
            $this->fixes()->prove($fix, $this->user);
            self::fail('An unreviewed fix must not run');
        } catch (\DomainException $e) {
            self::assertStringContainsString('Review', $e->getMessage());
        }
        $this->fixes()->review($fix, $this->user);
        $this->beat();
        $run = $this->fixes()->prove($fix, $this->user);

        $dir = $this->jobs.'/'.$run->getId()->toRfc4122();
        $job = json_decode((string) file_get_contents($dir.'/job.json'), true);
        self::assertSame('proposed', $job['variant']);
        self::assertArrayNotHasKey('patch', $job, 'The reference patch is not used for an AI fix');
        self::assertSame($fix->getDiff(), file_get_contents($dir.'/fix.patch'));
        self::assertSame($test->getCode(), file_get_contents($dir.'/Test.php'), 'The same, unchanged test judges the fix');
        self::assertSame(FixLab::RUNNING, $this->fixes()->verdict($fix)['status']);

        $this->writeResult($dir, ['exit_code' => 0, 'junit' => self::junit(2, 0, 0)]);
        self::assertSame(FixLab::VERIFIED, $this->fixes()->verdict($fix)['status']);
        self::assertSame(TestLab::VERIFIED, static::getContainer()->get(TestLab::class)->verdict($test)['status'], 'A fix run does not replace the test\'s own proof');
    }

    public function testAFixThatLeavesTheTestRedIsRejectedAndOneThatDoesNotApplyIsSaidSo(): void
    {
        $test = $this->verifiedTest();
        $this->useModel($this->referenceDiff());

        foreach ([
            [['exit_code' => 1, 'junit' => self::junit(1, 1, 0)], FixLab::REJECTED],
            [['exit_code' => 99, 'junit' => '', 'setup' => 'patch', 'output' => 'The proposed fix could not be applied.'], FixLab::NOT_APPLIED],
        ] as [$result, $expected]) {
            $fix = $this->fixes()->propose($test, $this->user);
            $this->fixes()->review($fix, $this->user);
            $this->beat();
            $run = $this->fixes()->prove($fix, $this->user);
            $this->writeResult($this->jobs.'/'.$run->getId()->toRfc4122(), $result);
            self::assertSame($expected, $this->fixes()->verdict($fix)['status']);
        }
    }

    public function testAnOutOfScopeOrDangerousDiffIsRejectedAndCanNeverRun(): void
    {
        $test = $this->verifiedTest();
        $this->useModel("--- a/tests/ShopTestCase.php\n+++ b/tests/ShopTestCase.php\n@@ -1,1 +1,1 @@\n-<?php\n+<?php shell_exec('id');\n");

        $fix = $this->fixes()->propose($test, $this->user);
        self::assertFalse($fix->passedStaticChecks());
        self::assertStringContainsString('may only change', implode(' ', $fix->getStaticIssues()));
        $this->fixes()->review($fix, $this->user);
        $this->beat();
        $this->expectExceptionMessage('static checks');
        $this->fixes()->prove($fix, $this->user);
    }

    private function verifiedTest(): RegressionTest
    {
        $test = static::getContainer()->get(TestLab::class)->propose($this->inv, $this->user);
        $this->verify($test);

        return $test;
    }

    private function verify(RegressionTest $test): void
    {
        $lab = static::getContainer()->get(TestLab::class);
        $lab->review($test, $this->user);
        $this->beat();
        $runs = $lab->prove($test, $this->user);
        foreach ($runs as $run) {
            $this->writeResult($this->jobs.'/'.$run->getId()->toRfc4122(), 'buggy' === $run->getVariant()
                ? ['exit_code' => 1, 'junit' => self::junit(1, 1, 0)]
                : ['exit_code' => 0, 'junit' => self::junit(2, 0, 0)]);
        }
        $lab->collect($runs[0]->getBatchId()->toRfc4122());
        self::assertSame(TestLab::VERIFIED, $lab->verdict($test)['status']);
    }

    /** The human-written reference patch, minus its prose header: a realistic, valid diff. */
    private function referenceDiff(): string
    {
        $source = static::getContainer()->get(SourceContext::class);
        $patch = (string) file_get_contents($source->config('project_dir').'/'.$source->config('reference_patch'));

        return substr($patch, (int) strpos($patch, 'diff -ruN'));
    }

    private function useModel(string $diff): void
    {
        $agent = new MockAgent([LlmFixWriter::INSTRUCTION => (string) json_encode([
            'summary' => 'Read stock from the database and reserve it with a conditional update before authorizing the payment.',
            'citations' => ['EV-1', 'EV-999'],
            'diff' => $diff,
        ])]);
        $settings = new AiSettings('hf_test_key', 'Qwen/Qwen3.5-35B-A3B', null, true);
        $c = static::getContainer();
        $c->set(LlmFixWriter::class, new LlmFixWriter($agent, $c->get(EvidenceSession::class), $c->get(SourceContext::class), $settings));
    }

    private function fixes(): FixLab
    {
        return static::getContainer()->get(FixLab::class);
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
}
