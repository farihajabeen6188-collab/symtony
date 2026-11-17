<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Incident\IncidentGrouper;
use App\Domain\Incident\Incident;
use App\Domain\Incident\IncidentStatus;
use App\Domain\Workspace\Workspace;
use App\Tests\Support\EventFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class IncidentPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = $this->signedInClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function seedIncidents(int $count = 3): Workspace
    {
        $ws = $this->em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo']);
        $factory = new EventFactory($this->em);
        $app = $factory->application($ws, "shop".bin2hex(random_bytes(2)));
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $ids[] = $factory->exception($app, 'App\Error'.$i, "Failure kind $i", traceId: 'trace'.$i)->getId()->toRfc4122();
            $ids[] = $factory->exception($app, "App\Error".$i, "Failure kind $i", traceId: "trace".$i)->getId()->toRfc4122();
        }
        static::getContainer()->get(IncidentGrouper::class)->group($ids);

        return $ws;
    }

    public function testListFiltersAndSearch(): void
    {
        $this->seedIncidents(3);

        $crawler = $this->client->request('GET', '/app/incidents');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('tbody tr'));

        $crawler = $this->client->request('GET', '/app/incidents?q=Error1');
        self::assertCount(1, $crawler->filter('tbody tr'));

        $crawler = $this->client->request('GET', '/app/incidents?q=SYM-2');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'SYM-2');

        $crawler = $this->client->request('GET', '/app/incidents?status=resolved');
        self::assertSelectorTextContains('main', 'Nothing resolved yet');
    }

    public function testListUsesAFixedNumberOfQueries(): void
    {
        $measure = function (): int {
            // Count only this request's queries (reboot is disabled, so clear what seeding logged).
            static::getContainer()->get('doctrine.debug_data_holder')->reset();
            $this->client->enableProfiler();
            $this->client->request('GET', '/app/incidents?status=all');

            $collector = $this->client->getProfile()->getCollector('db');
            \assert($collector instanceof \Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector);

            return $collector->getQueryCount();
        };

        $this->seedIncidents(3);
        $few = $measure();
        $this->seedIncidents(12);
        $many = $measure();

        self::assertSame($few, $many, 'No N+1: query count must not grow with rows');
        self::assertLessThanOrEqual(12, $many);
    }

    public function testDetailStatusWorkflowNotesAndAssignment(): void
    {
        $this->seedIncidents(1);
        $crawler = $this->client->request('GET', '/app/incidents/1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Error0: Failure kind 0');
        self::assertSelectorTextContains('main', 'Stack trace');

        $this->client->submit($crawler->filter('button[name="transition"][value="start_investigation"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Investigating');

        $this->client->submit($crawler->filter('button[name="assignee"][value="me"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main aside', 'Demo Engineer');

        $this->client->submit($crawler->filter('form[action$="/notes"]')->form(['body' => 'Cache TTL looks suspicious.']));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Cache TTL looks suspicious.');

        $this->client->submit($crawler->filter('button[name="transition"][value="resolve"]')->form());
        $this->client->followRedirect();
        $this->em->clear();
        $incident = $this->em->getRepository(Incident::class)->findOneBy(['number' => 1]);
        self::assertSame(IncidentStatus::Resolved, $incident->getStatus());
        self::assertNotNull($incident->getResolvedAt());
    }

    public function testInvalidTransitionIsRejected(): void
    {
        $this->seedIncidents(1);
        $crawler = $this->client->request('GET', '/app/incidents/1');
        $token = $crawler->filter('form[action$="/transition"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/app/incidents/1/transition', ['transition' => 'reopen', '_token' => $token]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast-stack', 'Cannot');
    }

    public function testOccurrencesAndRelatedRender(): void
    {
        $this->seedIncidents(2);
        $this->client->request('GET', '/app/incidents/1/occurrences', [], [], ['HTTP_TURBO_FRAME' => 'occurrences-root']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#occurrences-root');
        self::assertSelectorTextContains('turbo-frame#occurrences-root', 'Failure kind 0');
    }

    public function testOtherWorkspaceIncidentIs404(): void
    {
        $factory = new EventFactory($this->em);
        $foreignApp = $factory->application(null, 'foreign');
        $e = $factory->exception($foreignApp, 'App\Secret', 'secret failure');
        static::getContainer()->get(IncidentGrouper::class)->group([$e->getId()->toRfc4122()]);

        $this->client->request('GET', '/app/incidents/1');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/app/incidents?status=all');
        self::assertStringNotContainsString('secret failure', (string) $this->client->getResponse()->getContent());
    }
}
