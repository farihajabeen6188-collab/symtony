<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Twig\Navigation;

/**
 * Every link in the sidebar and palette must lead to a page that renders.
 */
final class NavigationTest extends WebTestCase
{
    public function testEveryNavigationItemRenders(): void
    {
        $client = $this->signedInClient();
        $items = (new Navigation())->items();
        $router = static::getContainer()->get('router');

        foreach ($items as $item) {
            $url = $router->generate($item['route']);
            $client->request('GET', $url);
            self::assertResponseIsSuccessful(\sprintf('%s (%s) must render', $item['label'], $url));
            self::assertSelectorTextContains('h1', $item['label']);
        }
    }

    public function testStyleguideRenders(): void
    {
        $client = $this->signedInClient();
        $client->request('GET', '/styleguide');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Design system');
    }

    public function testTimeRangePreferenceIsStored(): void
    {
        $client = $this->signedInClient();
        $crawler = $client->request('GET', '/app');
        $form = $crawler->filter('button[name="range"][value="7d"]')->form();
        $client->submit($form);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.topbar', 'Last 7 days');
    }

    public function testThemePreferenceIsSavedToProfile(): void
    {
        $client = $this->signedInClient();
        $crawler = $client->request('GET', '/app');
        $token = $crawler->filter('body')->attr('data-theme-token-value');

        $client->request('POST', '/app/preferences/theme', ['theme' => 'light', '_token' => $token], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/app');
        self::assertSelectorExists('html[data-theme="light"]');
    }

    public function testPreferencesRejectMissingCsrfToken(): void
    {
        $client = $this->signedInClient();
        $client->request('POST', '/app/preferences/range', ['range' => '7d']);

        // An invalid CSRF token is an authentication failure: the change is rejected, not applied.
        self::assertResponseRedirects();
        $client->request('GET', '/app');
        self::assertSelectorTextContains('.topbar', 'Last 24 hours');
    }
}
