<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Application\Incident\IncidentGrouper;
use App\Application\Investigation\InvestigationRunner;
use App\Application\Investigation\StartInvestigation;
use App\Application\TestLab\TestLab;
use App\Domain\Audit\AuditLog;
use App\Domain\Event\Event;
use App\Domain\Incident\Incident;
use App\Domain\Mcp\McpToken;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Mcp\McpTokenManager;
use App\Tests\Functional\WebTestCase;
use App\Tests\Support\EventFactory;
use App\Tests\Support\StaleCacheScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Real MCP JSON-RPC over the Streamable HTTP endpoint, with bearer tokens, on the demo failure.
 */
final class McpServerTest extends WebTestCase
{
    private const PROTOCOL = '2025-06-18';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Workspace $demo;
    private Incident $incident;
    private ?string $session = null;
    private string $token = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->demoUser();
        $this->demo = $this->em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo']);

        $app = (new EventFactory($this->em))->application($this->demo, 'demo-shop');
        $this->incident = (new StaleCacheScenario($this->em, $app))->create(static::getContainer()->get(IncidentGrouper::class));
        $inv = static::getContainer()->get(StartInvestigation::class)($this->incident, null, 'system');
        static::getContainer()->get(InvestigationRunner::class)->run($inv);
        $this->em->refresh($inv);
        static::getContainer()->get(TestLab::class)->propose($inv, null);
    }

    public function testRequiresAValidBearerToken(): void
    {
        $this->client->request('POST', '/mcp', [], [], $this->headers(), $this->body('initialize'));
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer realm="symtony-mcp"');

        $this->client->request('POST', '/mcp', [], [], $this->headers() + ['HTTP_AUTHORIZATION' => 'Bearer smcp_'.str_repeat('x', 40)], $this->body('initialize'));
        self::assertResponseStatusCodeSame(401);

        $issued = $this->issue($this->demo);
        static::getContainer()->get(McpTokenManager::class)->revoke($issued['entity'], $this->demoUser());
        $this->client->request('POST', '/mcp', [], [], $this->headers() + ['HTTP_AUTHORIZATION' => 'Bearer '.$issued['token']], $this->body('initialize'));
        self::assertResponseStatusCodeSame(401, 'A revoked token is refused');
    }

    public function testDiscoveryAndEveryReadToolOnTheDemoFailure(): void
    {
        $this->connect($this->issue($this->demo)['token']);

        $tools = array_column($this->rpc('tools/list')['result']['tools'], 'name');
        foreach (['list_applications', 'get_application_health', 'list_incidents', 'get_incident', 'search_events', 'get_event_details',
            'get_incident_timeline', 'list_related_incidents', 'get_investigation_report', 'get_regression_test', 'start_investigation'] as $name) {
            self::assertContains($name, $tools);
        }

        self::assertSame('demo-shop', $this->tool('list_applications')['applications'][0]['slug'] ?? null);
        $health = $this->tool('get_application_health', ['application' => 'demo-shop']);
        self::assertGreaterThan(0, $health['totals']['errors']);

        $list = $this->tool('list_incidents', ['status' => 'all']);
        self::assertSame(1, $list['total']);
        $ref = $this->incident->getReference();
        self::assertSame($ref, $list['incidents'][0]['reference']);

        $incident = $this->tool('get_incident', ['incident' => $ref]);
        self::assertSame('trace-checkout', $incident['latest_occurrence']['trace_id']);
        self::assertSame('completed', $incident['latest_investigation']['status']);

        $timeline = $this->tool('get_incident_timeline', ['incident' => $ref]);
        $proven = array_column($timeline['items'], 'proven');
        self::assertContains(true, $proven);
        self::assertContains(false, $proven, 'Timing-only context is marked as not proven');

        $events = $this->tool('search_events', ['trace_id' => 'trace-checkout', 'limit' => 100]);
        self::assertGreaterThanOrEqual(9, $events['count']);
        self::assertStringNotContainsString('topsecret', (string) json_encode($events));

        $root = $this->em->getRepository(Event::class)->findOneBy(['spanId' => 'root']);
        $details = $this->tool('get_event_details', ['event_id' => $root->getId()->toRfc4122()]);
        self::assertSame('root', $details['span_id']);
        self::assertStringNotContainsString('topsecret', (string) json_encode($details), 'Secrets never leave through MCP');

        $report = $this->tool('get_investigation_report', ['investigation' => $ref]);
        self::assertSame('completed', $report['status']);
        self::assertNotEmpty($report['findings']);
        foreach ($report['findings'] as $f) {
            self::assertNotEmpty($f['evidence']);
            self::assertStringStartsWith('heuristic:', $f['source']);
        }

        $test = $this->tool('get_regression_test', ['test' => $ref]);
        self::assertSame('unverified_proposal', $test['status'], 'No run, no proof');
        self::assertArrayHasKey('related', $this->tool('list_related_incidents', ['incident' => $ref]));

        $resource = $this->rpc('resources/read', ['uri' => 'symtony://incidents/'.$ref])['result']['contents'][0];
        self::assertSame('application/json', $resource['mimeType']);
        self::assertStringContainsString($ref, $resource['text']);
        $md = $this->rpc('resources/read', ['uri' => 'symtony://investigations/'.$report['id'].'/report'])['result']['contents'][0];
        self::assertStringContainsString('# '.$ref.' investigation', $md['text']);
        $prompt = $this->rpc('prompts/get', ['name' => 'investigate_incident', 'arguments' => ['incident_id' => $ref]])['result'];
        self::assertStringContainsString('get_incident_timeline', $prompt['messages'][0]['content']['text']);

        $calls = $this->em->getRepository(AuditLog::class)->findBy(['action' => 'mcp.call']);
        self::assertCount(13, $calls, 'Every tool, resource and prompt call is audited');
        self::assertSame([true], array_values(array_unique(array_map(static fn (AuditLog $l): bool => $l->getMetadata()['ok'], $calls))));
    }

    public function testAnotherWorkspaceSeesNothing(): void
    {
        $foreign = (new EventFactory($this->em))->application(null, 'other')->getWorkspace();
        $this->connect($this->issue($foreign)['token']);

        self::assertSame(0, $this->tool('list_incidents', ['status' => 'all'])['total']);
        $miss = $this->call('get_incident', ['incident' => $this->incident->getId()->toRfc4122()]);
        self::assertTrue($miss['isError']);
        $event = $this->em->getRepository(Event::class)->findOneBy(['spanId' => 'root']);
        self::assertTrue($this->call('get_event_details', ['event_id' => $event->getId()->toRfc4122()])['isError']);
        self::assertSame(0, $this->tool('search_events', ['trace_id' => 'trace-checkout'])['count']);
        self::assertSame(['other'], array_column($this->tool('list_applications')['applications'], 'slug'));
    }

    public function testStartingAnInvestigationNeedsTheWriteScope(): void
    {
        $this->connect($this->issue($this->demo)['token']);
        $denied = $this->call('start_investigation', ['incident' => $this->incident->getReference()]);
        self::assertTrue($denied['isError']);
        self::assertStringContainsString('"write" scope', $denied['content'][0]['text']);

        $this->connect($this->issue($this->demo, true)['token']);
        $started = $this->tool('start_investigation', ['incident' => $this->incident->getReference()]);
        self::assertSame('queued', $started['status']);
        /** @var InMemoryTransport $queue */
        $queue = static::getContainer()->get('messenger.transport.investigations');
        self::assertCount(1, $queue->getSent(), 'Queued for the worker, not run in the request');
    }

    public function testInvalidArgumentsAreValidationErrors(): void
    {
        $this->connect($this->issue($this->demo)['token']);

        $response = $this->rpc('tools/call', ['name' => 'search_events', 'arguments' => ['limit' => 500]]);
        self::assertSame(-32602, $response['error']['code']);
        $missing = $this->rpc('tools/call', ['name' => 'get_incident', 'arguments' => new \stdClass()]);
        self::assertSame(-32602, $missing['error']['code']);
        $unknown = $this->call('get_incident', ['incident' => 'SYM-999']);
        self::assertTrue($unknown['isError']);
        self::assertStringNotContainsString('#0', $unknown['content'][0]['text'], 'No stack traces');
    }

    public function testCallsAreRateLimitedPerToken(): void
    {
        $this->connect($this->issue($this->demo)['token']);
        for ($i = 0; $i < 20; ++$i) {
            self::assertFalse($this->call('ping')['isError']);
        }
        /** @var array<string, mixed> $limited */
        $limited = $this->call('ping');
        self::assertTrue($limited['isError']);
        self::assertStringContainsString('Rate limit', $limited['content'][0]['text']);
    }

    /** @return array{token: string, entity: McpToken} */
    private function issue(Workspace $ws, bool $write = false): array
    {
        $ws = $this->em->find(Workspace::class, $ws->getId());
        \assert($ws instanceof Workspace);

        return static::getContainer()->get(McpTokenManager::class)->issue($ws, 'test', $write ? [McpToken::READ, McpToken::WRITE] : [McpToken::READ], null);
    }

    private function connect(string $token): void
    {
        $this->token = $token;
        $this->session = null;
        $init = $this->rpc('initialize', ['protocolVersion' => self::PROTOCOL, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'phpunit', 'version' => '1']]);
        self::assertSame('symtony', $init['result']['serverInfo']['name']);
        $this->session = (string) $this->client->getResponse()->headers->get('Mcp-Session-Id');
        $this->client->request('POST', '/mcp', [], [], $this->headers(), (string) json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        self::assertResponseStatusCodeSame(202);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed> the decoded structured result; fails on tool errors
     */
    private function tool(string $name, array $args = []): array
    {
        $result = $this->call($name, $args);
        self::assertFalse($result['isError'] ?? false, $name.': '.($result['content'][0]['text'] ?? ''));

        return json_decode($result['content'][0]['text'], true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function call(string $name, array $args = []): array
    {
        $response = $this->rpc('tools/call', ['name' => $name, 'arguments' => [] === $args ? new \stdClass() : $args]);
        self::assertArrayHasKey('result', $response, (string) json_encode($response));

        return $response['result'];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        $this->client->request('POST', '/mcp', [], [], $this->headers(), $this->body($method, $params));

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $params */
    private function body(string $method, array $params = []): string
    {
        return (string) json_encode(['jsonrpc' => '2.0', 'id' => random_int(1, 1_000_000), 'method' => $method] + ([] !== $params ? ['params' => $params] : []), \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $h = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_HOST' => 'localhost'];
        if ('' !== $this->token) {
            $h['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token;
        }
        if (null !== $this->session) {
            $h['HTTP_MCP_SESSION_ID'] = $this->session;
            $h['HTTP_MCP_PROTOCOL_VERSION'] = self::PROTOCOL;
        }

        return $h;
    }
}
