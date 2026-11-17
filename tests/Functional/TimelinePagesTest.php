<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Incident\IncidentGrouper;
use App\Domain\Event\EventType;
use App\Domain\Event\Severity;
use App\Domain\Workspace\Workspace;
use App\Tests\Support\EventFactory;
use Doctrine\ORM\EntityManagerInterface;

final class TimelinePagesTest extends WebTestCase
{
    public function testIncidentTimelineTabTracePageAndJson(): void
    {
        $client = $this->signedInClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $factory = new EventFactory($em);
        $app = $factory->application($em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo']));
        $factory->event($app, EventType::HttpRequest, 'POST /checkout', Severity::Error, new \DateTimeImmutable('-5 seconds'), 'trace-1');
        $ex = $factory->exception($app, 'App\StockException', 'stock went negative', new \DateTimeImmutable('-4 seconds'), traceId: 'trace-1');
        static::getContainer()->get(IncidentGrouper::class)->group([$ex->getId()->toRfc4122()]);

        $crawler = $client->request('GET', '/app/incidents/1');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="timeline"]');
        self::assertSelectorTextContains('main', 'Only identifiers prove a link');
        $data = json_decode((string) $crawler->filter('[data-controller="timeline"]')->attr('data-timeline-data-value'), true);
        self::assertCount(2, $data['items']);
        self::assertSame($ex->getId()->toRfc4122(), $data['first_failure']);

        $client->request('GET', '/app/traces/trace-1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Request timeline');

        $client->request('GET', '/app/incidents/1/timeline.json');
        self::assertResponseIsSuccessful();
        self::assertCount(2, json_decode((string) $client->getResponse()->getContent(), true)['items']);
    }

    public function testForeignTraceIs404(): void
    {
        $client = $this->signedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $factory = new EventFactory($em);
        $factory->event($factory->application(null, 'other'), EventType::HttpRequest, 'GET /secret', Severity::Info, null, 'foreign-trace');

        $client->request('GET', '/app/traces/foreign-trace');
        self::assertResponseStatusCodeSame(404);
    }
}
