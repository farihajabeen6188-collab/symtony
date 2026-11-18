<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Incident\IncidentGrouper;
use App\Application\Investigation\InvestigationRunner;
use App\Domain\Investigation\Investigation;
use App\Domain\Workspace\Workspace;
use App\Tests\Support\EventFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class InvestigationPagesTest extends WebTestCase
{
    public function testStartRunViewExport(): void
    {
        $client = $this->signedInClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $factory = new EventFactory($em);
        $app = $factory->application($em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo']));
        $e = $factory->exception($app, 'App\StockException', 'stock went negative', traceId: 'tr-x');
        static::getContainer()->get(IncidentGrouper::class)->group([$e->getId()->toRfc4122()]);

        $crawler = $client->request('GET', '/app/incidents/1');
        $client->submit($crawler->filter('form[action$="/investigate"]')->form());
        self::assertResponseRedirects();
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.investigations');
        self::assertCount(1, $transport->getSent(), 'Queued for the worker, not run in the request');

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main', 'Investigating SYM-1');
        self::assertSelectorExists('turbo-frame#inv-progress');

        $inv = $em->getRepository(Investigation::class)->findOneBy([]);
        static::getContainer()->get(InvestigationRunner::class)->run($inv);

        $client->request('GET', '/app/investigations/'.$inv->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Summary');
        self::assertSelectorTextContains('main', 'claims verified against evidence');
        self::assertSelectorTextContains('main', 'Heuristic engine');

        $client->request('GET', '/app/investigations/'.$inv->getId().'/progress', [], [], ['HTTP_TURBO_FRAME' => 'inv-progress']);
        self::assertSelectorExists('turbo-frame#inv-progress[data-poll-final-value="true"]');

        $client->request('GET', '/app/investigations/'.$inv->getId().'.md');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/markdown', (string) $client->getResponse()->headers->get('Content-Type'));
        $md = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('# SYM-1 investigation', $md);
        self::assertStringContainsString('## Evidence', $md);
        self::assertStringContainsString('EV-1', $md);

        $client->request('GET', '/app/investigations');
        self::assertSelectorTextContains('main', 'SYM-1');
        $client->request('GET', '/app');
        self::assertSelectorTextContains('main', 'Investigations');
    }

    public function testForeignInvestigationIs404(): void
    {
        $client = $this->signedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $factory = new EventFactory($em);
        $e = $factory->exception($factory->application(null, 'foreign'), 'App\Secret', 'secret');
        static::getContainer()->get(IncidentGrouper::class)->group([$e->getId()->toRfc4122()]);
        $incident = $em->getRepository(\App\Domain\Incident\Incident::class)->findOneBy([]);
        $inv = new Investigation($incident, null, 'system');
        $em->persist($inv);
        $em->flush();

        $client->request('GET', '/app/investigations/'.$inv->getId());
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/app/investigations/'.$inv->getId().'.md');
        self::assertResponseStatusCodeSame(404);
    }
}
