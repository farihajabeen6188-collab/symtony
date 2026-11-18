<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Application\TestLab\TestLab;
use App\Domain\TestLab\RegressionTest;
use App\Domain\TestLab\TestExecution;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Mcp\McpGate;
use App\Infrastructure\Mcp\McpPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Uid\Uuid;

/**
 * Regression tests proposed from investigations and their red/green proof.
 */
final class TestLabTools
{
    public function __construct(
        private readonly McpGate $gate,
        private readonly TestLab $lab,
        private readonly McpPresenter $present,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string $test Test id, or an incident reference such as "SYM-5" for its latest test
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_regression_test',
        title: 'Regression test',
        description: 'A proposed PHPUnit regression test: code, who wrote it (template or model), review state, and the proof status from real runs in the isolated runner. "verified_reproduction" means it failed on the buggy build and passed with the fix; anything else is not proof. Includes the output of the latest runs.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function get(string $test): array
    {
        return $this->gate->run('tool', 'get_regression_test', ['test' => $test], function (Workspace $ws) use ($test): array {
            $t = $this->resolve($ws, $test);
            $verdict = $this->lab->verdict($t);

            return [
                'id' => $t->getId()->toRfc4122(),
                'incident' => $t->getIncident()->getReference(),
                'title' => $t->getTitle(),
                'purpose' => $t->getPurpose(),
                'scenario' => $t->getScenario(),
                'expected' => $t->getExpected(),
                'class_name' => $t->getClassName(),
                'written_by' => $t->getSource(),
                'evidence' => $t->getCitations(),
                'static_checks' => $t->passedStaticChecks() ? 'passed' : 'failed',
                'static_issues' => $t->getStaticIssues(),
                'reviewed' => $t->isReviewed(),
                'status' => $verdict['status'],
                'buggy_run' => $this->run($verdict['buggy']),
                'fixed_run' => $this->run($verdict['fixed']),
                'code' => $t->getCode(),
                'url' => $this->present->url('app_test_show', ['id' => $t->getId()->toRfc4122()]),
            ];
        });
    }

    /** @return array<string, mixed>|null */
    private function run(?TestExecution $run): ?array
    {
        if (null === $run) {
            return null;
        }

        return [
            'status' => $run->getStatus(),
            'exit_code' => $run->getExitCode(),
            'duration_ms' => $run->getDurationMs(),
            'assertions' => $run->getAssertions(),
            'failures' => $run->getFailures(),
            'errors' => $run->getErrors(),
            'finished_at' => $run->getFinishedAt()?->format(\DATE_ATOM),
            'output_tail' => null !== $run->getOutput() ? mb_substr($run->getOutput(), -4000) : null,
        ];
    }

    private function resolve(Workspace $ws, string $ref): RegressionTest
    {
        if (Uuid::isValid($ref)) {
            return $this->present->findTest($ws, $ref);
        }
        $incident = $this->present->findIncident($ws, $ref);
        $test = $this->em->getRepository(RegressionTest::class)->findOneBy(['incident' => $incident], ['createdAt' => 'DESC']);
        if (!$test instanceof RegressionTest) {
            throw new \DomainException(\sprintf('No regression test exists for %s yet. Generate one from its investigation in SYMTONY.', $incident->getReference()));
        }

        return $test;
    }
}
