<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Domain\Mcp\McpToken;
use App\Tests\Functional\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class IntegrationsPageTest extends WebTestCase
{
    public function testCreateShowOnceUseAndRevokeAToken(): void
    {
        $client = $this->signedInClient();
        $client->disableReboot();

        $crawler = $client->request('GET', '/app/mcp');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'start_investigation');
        self::assertSelectorTextContains('main', 'symtony://health');
        self::assertSelectorTextContains('main', 'investigate_incident');
        self::assertSelectorTextContains('main', 'No MCP calls yet');
        self::assertSelectorNotExists('[data-testid="new-token"]');

        $client->submit($crawler->filter('form[action$="/mcp/tokens"]')->form(['label' => 'Laptop', 'write' => '1']));
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        $token = trim($crawler->filter('[data-testid="new-token"]')->text());
        self::assertMatchesRegularExpression('/^smcp_[0-9A-Za-z]{40}$/', $token);
        self::assertStringContainsString('claude mcp add --transport http symtony http://localhost/mcp --header "Authorization: Bearer '.$token.'"', $crawler->filter('main')->text());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entity = $em->getRepository(McpToken::class)->findOneBy(['label' => 'Laptop']);
        self::assertNotNull($entity);
        self::assertSame(['read', 'write'], $entity->getScopes());
        self::assertStringNotContainsString($token, (string) json_encode($em->getConnection()->fetchAllAssociative('SELECT * FROM mcp_token')), 'Only the hash is stored');

        $crawler = $client->request('GET', '/app/mcp');
        self::assertSelectorNotExists('[data-testid="new-token"]', 'Shown only once');
        self::assertSelectorTextContains('main', 'Never used');

        // Use it once, then the call appears in Recent calls.
        $client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]]));
        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', '/app/mcp');
        $client->submit($crawler->filter('form[action$="/revoke"]')->form());
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Revoked');
        $client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], '{}');
        self::assertResponseStatusCodeSame(401);
    }

    public function testValidationAndCsrf(): void
    {
        $client = $this->signedInClient();
        $client->disableReboot();
        $crawler = $client->request('GET', '/app/mcp');
        $client->submit($crawler->filter('form[action$="/mcp/tokens"]')->form(['label' => '   ']));
        $client->followRedirect();
        self::assertSelectorTextContains('.toast-stack', '1 to 80 characters');

        $client->request('POST', '/app/mcp/tokens', ['label' => 'x']);
        self::assertFalse($client->getResponse()->isSuccessful());
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(McpToken::class)->count([]));
    }
}
