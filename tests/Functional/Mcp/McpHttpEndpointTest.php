<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Talks real MCP JSON-RPC to the Streamable HTTP endpoint: initialize, tools/list, tools/call.
 */
final class McpHttpEndpointTest extends WebTestCase
{
    private const PROTOCOL = '2025-06-18';

    public function testHandshakeListsAndCallsPingTool(): void
    {
        $client = self::createClient();

        $this->rpc($client, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => self::PROTOCOL,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'phpunit', 'version' => '1'],
        ]]);
        self::assertResponseIsSuccessful();
        $init = $this->json($client);
        self::assertSame('symtony', $init['result']['serverInfo']['name']);

        $sessionId = (string) $client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotSame('', $sessionId, 'Server must issue an MCP session id');

        $this->rpc($client, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sessionId);
        self::assertResponseStatusCodeSame(202);

        $this->rpc($client, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sessionId);
        $names = array_column($this->json($client)['result']['tools'], 'name');
        self::assertContains('ping', $names);

        $this->rpc($client, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'ping', 'arguments' => new \stdClass()]], $sessionId);
        $call = $this->json($client);
        self::assertFalse($call['result']['isError']);
        self::assertSame('ok', $call['result']['structuredContent']['status']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function rpc(KernelBrowser $client, array $payload, ?string $sessionId = null): void
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_HOST' => 'localhost',
        ];
        if (null !== $sessionId) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
            $server['HTTP_MCP_PROTOCOL_VERSION'] = self::PROTOCOL;
        }

        $client->request('POST', '/mcp', [], [], $server, json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
