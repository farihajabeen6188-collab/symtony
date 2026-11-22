<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Application\TestLab\TestLab;
use App\Domain\Application\Application;
use App\Domain\Incident\Incident;
use App\Domain\TestLab\RegressionTest;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\AI\AiSettings;
use App\Infrastructure\Demo\DemoInstaller;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The guided tour on the Overview: the story from a failing checkout to a proven regression test,
 * then the two optional connections. Every "done" comes from real data in this workspace.
 */
final class GettingStarted
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TestLab $testLab,
        private readonly AiSettings $ai,
    ) {
    }

    /**
     * @return array{steps: list<array{key: string, title: string, text: string, done: bool, optional: bool, action: ?array{label: string, route: string, params: array<string, string|int>}, note: ?string}>, done: int, total: int, current: ?string}
     */
    public function steps(Workspace $workspace): array
    {
        $conn = $this->em->getConnection();
        $wsId = $workspace->getId()->toRfc4122();

        $demoApp = $this->em->getRepository(Application::class)->findOneBy(['workspace' => $workspace, 'slug' => DemoInstaller::APP_SLUG])
            ?? $this->em->getRepository(Application::class)->findOneBy(['workspace' => $workspace], ['createdAt' => 'ASC']);
        $incident = $this->storyIncident($workspace);
        $investigation = $conn->fetchAssociative(
            "SELECT id, status FROM investigation WHERE workspace_id = :ws ORDER BY (status = 'completed') DESC, created_at DESC LIMIT 1",
            ['ws' => $wsId],
        ) ?: null;
        $tokens = (int) $conn->fetchOne('SELECT count(*) FROM mcp_token WHERE workspace_id = :ws AND revoked_at IS NULL', ['ws' => $wsId]);

        $verifiedTest = null;
        $anyTest = null;
        foreach ($this->em->getRepository(RegressionTest::class)->findBy(['workspace' => $workspace], ['createdAt' => 'DESC'], 20) as $test) {
            $anyTest ??= $test;
            if (TestLab::VERIFIED === $this->testLab->verdict($test)['status']) {
                $verifiedTest = $test;
                break;
            }
        }

        $investigated = null !== $investigation && 'completed' === $investigation['status'];
        $steps = [
            [
                'key' => 'scenario',
                'title' => 'Break the demo shop',
                'text' => 'Acme Shop deploys release 1.4.0 and sends real checkouts. A stale cached stock level lets checkout authorize a payment, then the database rejects the order. Open the app and press Run failure scenario.',
                'done' => null !== $incident,
                'optional' => false,
                'action' => null !== $demoApp ? ['label' => 'Open Acme Shop', 'route' => 'app_applications_show', 'params' => ['id' => $demoApp->getId()->toRfc4122()]] : ['label' => 'Applications', 'route' => 'app_applications', 'params' => []],
                'note' => null,
            ],
            [
                'key' => 'replay',
                'title' => 'Replay what happened',
                'text' => 'Open the incident and step through the failing request. Links proven by trace ids are solid; links inferred from timing are dashed, because timing is not proof.',
                'done' => null !== $investigation,
                'optional' => false,
                'action' => null !== $incident ? ['label' => 'Open '.$incident->getReference(), 'route' => 'app_incident_show', 'params' => ['number' => $incident->getNumber()]] : null,
                'note' => null,
            ],
            [
                'key' => 'investigate',
                'title' => 'Investigate',
                'text' => 'On the incident, press Investigate. Seven stages run in the background; every finding cites its evidence (EV-n) and a validator rejects claims it cannot back.',
                'done' => $investigated,
                'optional' => false,
                'action' => null !== $investigation
                    ? ['label' => 'Open the investigation', 'route' => 'app_investigation_show', 'params' => ['id' => (string) $investigation['id']]]
                    : (null !== $incident ? ['label' => 'Go to '.$incident->getReference(), 'route' => 'app_incident_show', 'params' => ['number' => $incident->getNumber()]] : null),
                'note' => null,
            ],
            [
                'key' => 'prove',
                'title' => 'Prove it with a regression test',
                'text' => 'From the investigation, generate a PHPUnit test, review it, then press Prove it. It only counts as a verified reproduction when it fails on the buggy build and passes with the fix.',
                'done' => null !== $verifiedTest,
                'optional' => false,
                'action' => null !== ($verifiedTest ?? $anyTest)
                    ? ['label' => null !== $verifiedTest ? 'See the verified test' : 'Open the test', 'route' => 'app_test_show', 'params' => ['id' => ($verifiedTest ?? $anyTest)->getId()->toRfc4122()]]
                    : ($investigated ? ['label' => 'Open the investigation', 'route' => 'app_investigation_show', 'params' => ['id' => (string) $investigation['id']]] : null),
                'note' => null,
            ],
            [
                'key' => 'ai',
                'title' => 'Connect an AI model',
                'text' => 'Everything above works without a model, on deterministic analyzers, and the app says so. To add one, set SYMTONY_AI_PROVIDER (OpenAI, Anthropic, Gemini, OpenRouter, Mistral, DeepSeek, Hugging Face or any OpenAI-compatible endpoint) and its key in .env.local, then restart.',
                'done' => $this->ai->isConfigured(),
                'optional' => true,
                'action' => ['label' => 'AI provider settings', 'route' => 'app_settings', 'params' => []],
                'note' => $this->ai->isConfigured() ? \sprintf('Using %s · %s.', $this->ai->platformLabel(), $this->ai->model()) : $this->ai->whyNotConfigured(),
            ],
            [
                'key' => 'mcp',
                'title' => 'Ask Claude Code over MCP',
                'text' => 'Create a token, add the server to Claude Code with the command shown, and ask why checkout failed. It reads the same evidence through 12 scoped, rate-limited, audited tools.',
                'done' => $tokens > 0,
                'optional' => true,
                'action' => ['label' => 'MCP integrations', 'route' => 'app_mcp', 'params' => []],
                'note' => null,
            ],
        ];

        $current = null;
        foreach ($steps as $step) {
            if (!$step['done']) {
                $current = $step['key'];
                break;
            }
        }
        $done = \count(array_filter($steps, static fn (array $s): bool => $s['done']));

        return ['steps' => $steps, 'done' => $done, 'total' => \count($steps), 'current' => $current];
    }

    /**
     * The incident the tour is about: the demo checkout failure (a stock constraint violation) when it
     * exists, otherwise the most recently seen open incident, otherwise the most recent one.
     */
    private function storyIncident(Workspace $workspace): ?Incident
    {
        $byRecent = fn (string $where): ?Incident => $this->em->createQuery(
            'SELECT i FROM '.Incident::class.' i WHERE i.workspace = :ws'.$where.' ORDER BY i.lastSeenAt DESC'
        )->setParameter('ws', $workspace)->setMaxResults(1)->getOneOrNullResult();

        return $byRecent(" AND i.title LIKE '%stock_non_negative%'")
            ?? $byRecent(" AND i.status <> 'resolved'")
            ?? $byRecent('');
    }
}
