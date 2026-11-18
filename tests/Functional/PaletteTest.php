<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Incident\IncidentGrouper;
use App\Domain\Event\Event;
use App\Domain\Workspace\Workspace;
use App\Tests\Support\EventFactory;
use Doctrine\ORM\EntityManagerInterface;

final class PaletteTest extends WebTestCase
{
    public function testServerSearchActionsAndIsolation(): void
    {
        $client = $this->signedInClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $factory = new EventFactory($em);
        $app = $factory->application($em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo']), 'checkout-api');
        $mine = $factory->exception($app, 'App\StockException', 'Stock went negative', traceId: 'abcdef0123456789abcdef0123456789');
        $foreign = $factory->exception($factory->application(null, 'foreign'), 'App\SecretStockException', 'Foreign stock secret');
        static::getContainer()->get(IncidentGrouper::class)->group([$mine->getId()->toRfc4122(), $foreign->getId()->toRfc4122()]);

        $client->request('GET', '/app/palette?q=stock');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Stock went negative');
        self::assertSelectorTextNotContains('body', 'Foreign stock secret');
        self::assertSelectorTextContains('body', 'Search events for “stock”');

        $client->request('GET', '/app/palette?q=SYM-1');
        self::assertSelectorExists('a[href="/app/incidents/1"]');

        $client->request('GET', '/app/palette?q=checkout');
        self::assertSelectorTextContains('body', 'checkout-api');

        $event = $em->getRepository(Event::class)->findOneBy(['exceptionClass' => 'App\StockException']);
        $client->request('GET', '/app/palette?q='.$event->getId());
        self::assertSelectorExists('a[href*="event='.$event->getId().'"]');
        $foreignEvent = $em->getRepository(Event::class)->findOneBy(['exceptionClass' => 'App\SecretStockException']);
        $client->request('GET', '/app/palette?q='.$foreignEvent->getId());
        self::assertSelectorNotExists('a[href*="event='.$foreignEvent->getId().'"]', 'Events of other workspaces are not found');

        $client->request('GET', '/app/palette?q=abcdef0123456789abcdef0123456789');
        self::assertSelectorExists('a[href*="trace_id=abcdef0123456789abcdef0123456789"]');

        // Contextual action on an incident page: a real POST form with a CSRF token.
        $client->request('GET', '/app/palette?q=&context=incident:1');
        self::assertSelectorExists('form[action="/app/incidents/1/investigate"] input[name="_token"]');
        $client->request('GET', '/app/palette?q=&context=incident:999');
        self::assertSelectorNotExists('form[action$="/investigate"]');

        $client->request('GET', '/app/incidents/1');
        self::assertSelectorExists('[data-palette-context-value="incident:1"]');
    }
}
