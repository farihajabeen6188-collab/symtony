<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class SecurityTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/app/incidents');

        self::assertResponseRedirects('/login');
    }

    public function testDemoUserCanSignIn(): void
    {
        $client = static::createClient();
        $this->demoUser();

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form([
            'email' => 'demo@symtony.local',
            'password' => 'symtony-demo',
        ]));

        self::assertResponseRedirects('/app');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Overview');
    }

    public function testWrongPasswordShowsAnError(): void
    {
        $client = static::createClient();
        $this->demoUser();

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form([
            'email' => 'demo@symtony.local',
            'password' => 'not-the-password',
        ]));
        $client->followRedirect();

        self::assertSelectorExists('[role="alert"]');
    }

    public function testLandingPageIsPublicAndAdaptsToAuthState(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Every error has a story.');
        self::assertSelectorExists('a[href="/login"]');
        self::assertSelectorExists('[data-controller="replay"][data-replay-autoplay-value="true"]', 'The hero replays the demo failure');
        self::assertSelectorTextContains('#mcp', 'start_investigation', 'Tool list comes from the MCP registry');

        $client->loginUser($this->demoUser());
        $client->request('GET', '/');
        self::assertSelectorExists('a[href="/app"]');
    }
}
