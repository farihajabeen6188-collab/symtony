<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Event\EventType;
use App\Domain\Event\Severity;
use App\Domain\Workspace\Workspace;
use App\Tests\Support\EventFactory;
use Doctrine\ORM\EntityManagerInterface;

final class EventExplorerAndOverviewTest extends WebTestCase
{
    public function testOnboardingWhenNoApplications(): void
    {
        $client = $this->signedInClient();
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Connect your first application');
        self::assertSelectorNotExists('[data-controller="chart"]');
    }

    public function testOverviewShowsQueriedNumbers(): void
    {
        $client = $this->signedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $factory = new EventFactory($em);
        $app = $factory->application($em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo']));
        $factory->event($app, EventType::HttpRequest, 'GET /', Severity::Info);
        $factory->event($app, EventType::Log, 'disk almost full', Severity::Error);

        $crawler = $client->request('GET', '/app');
        self::assertResponseIsSuccessful();
        $events = $crawler->filter('.panel')->reduce(static fn ($n): bool => str_contains($n->text(), 'Events'))->first()->text();
        self::assertStringContainsString('2', $events);
        self::assertStringContainsString('1 errors', $events);
        self::assertSelectorTextContains('main', 'Service health');

        // The guided tour starts at the first step, with progress read from real data.
        self::assertSelectorTextContains('#getting-started', 'Start here');
        self::assertSelectorExists('#getting-started [data-step="scenario"][data-done="false"][aria-current="step"]');
        self::assertCount(6, $crawler->filter('#getting-started [data-step]'));
        self::assertSelectorTextContains('#getting-started', '0 of 6 done');
    }

    public function testSearchFilterCursorAndPanel(): void
    {
        $client = $this->signedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $factory = new EventFactory($em);
        $app = $factory->application($em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo']));
        $t = new \DateTimeImmutable('-30 minutes');
        for ($i = 0; $i < 60; ++$i) {
            $factory->event($app, EventType::DbQuery, "SELECT product $i", Severity::Info, $t->modify("+$i seconds"), 'tr-'.($i % 3));
        }
        $special = $factory->exception($app, 'App\Boom', 'kaboom here', $t->modify('+90 seconds'), traceId: 'tr-1');

        // First page: 50 rows + a lazy "more" frame.
        $crawler = $client->request('GET', '/app/events');
        self::assertResponseIsSuccessful();
        self::assertCount(50, $crawler->filter('a.event-row'));
        $more = $crawler->filter('turbo-frame[id^="more-"]');
        self::assertCount(1, $more);

        // Second page through the cursor, as Turbo would request it.
        $crawler = $client->request('GET', $more->attr('src'), [], [], ['HTTP_TURBO_FRAME' => $more->attr('id')]);
        self::assertSelectorExists('turbo-frame#'.$more->attr('id'));
        self::assertCount(11, $crawler->filter('a.event-row'));
        self::assertSelectorTextContains('body', 'End of results');

        // Filters.
        $crawler = $client->request('GET', '/app/events?severity=error');
        self::assertCount(1, $crawler->filter('a.event-row'));
        $crawler = $client->request('GET', '/app/events?trace_id=tr-1');
        self::assertCount(21, $crawler->filter('a.event-row'));
        $crawler = $client->request('GET', '/app/events?q=kaboom');
        self::assertCount(1, $crawler->filter('a.event-row'));

        // Detail panel with related events of the same trace.
        $crawler = $client->request('GET', '/app/events/'.$special->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#event-detail');
        self::assertSelectorTextContains('turbo-frame#event-detail', 'App\Boom');
        self::assertSelectorTextContains('turbo-frame#event-detail', 'Same trace');
    }

    public function testForeignEventIs404(): void
    {
        $client = $this->signedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $factory = new EventFactory($em);
        $foreign = $factory->exception($factory->application(null, 'other'), 'App\Secret', 'not yours');

        $client->request('GET', '/app/events/'.$foreign->getId());
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/app/events?q=not+yours');
        self::assertSelectorTextContains('main', 'No events match');
    }
}
