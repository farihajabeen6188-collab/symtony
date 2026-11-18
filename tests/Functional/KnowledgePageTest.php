<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Incident\IncidentActions;
use App\Application\Incident\IncidentGrouper;
use App\Domain\Incident\Incident;
use App\Domain\Workspace\Workspace;
use App\Tests\Support\EventFactory;
use Doctrine\ORM\EntityManagerInterface;

final class KnowledgePageTest extends WebTestCase
{
    public function testEmptyThenSearchableResolvedHistory(): void
    {
        $client = $this->signedInClient();
        $client->disableReboot();
        $client->request('GET', '/app/knowledge');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Nothing resolved yet');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $factory = new EventFactory($em);
        $demo = $factory->application($em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo']));
        $foreign = $factory->application(null, 'foreign');
        $ids = [];
        foreach ([[$demo, 'App\Pricing\OrderTotalMismatch', 'Order total does not match price list'], [$demo, 'App\Mail\Timeout', 'Mailer timed out'], [$foreign, 'App\Pricing\SecretMismatch', 'Foreign pricing failure']] as [$app, $class, $msg]) {
            $ids[] = $factory->exception($app, $class, $msg)->getId()->toRfc4122();
        }
        static::getContainer()->get(IncidentGrouper::class)->group($ids);
        $user = $this->demoUser();
        foreach ($em->getRepository(Incident::class)->findAll() as $incident) {
            if (str_contains((string) $incident->getExceptionClass(), 'Pricing')) {
                static::getContainer()->get(IncidentActions::class)->transition($incident, 'resolve', $user, 'Root cause: PricingService cached list prices. Fix: invalidate the price cache on write.');
            }
        }

        $client->request('GET', '/app/knowledge');
        self::assertSelectorTextContains('main', 'OrderTotalMismatch');
        self::assertSelectorTextContains('main', 'invalidate the price cache on write');
        self::assertSelectorTextNotContains('main', 'Mailer timed out', 'Only resolved incidents');
        self::assertSelectorTextNotContains('main', 'SecretMismatch', 'Only this workspace');

        $client->request('GET', '/app/knowledge?q=pricing cache');
        self::assertSelectorTextContains('main', 'Keyword search (Postgres full-text)');
        self::assertSelectorTextContains('main', 'score');
        self::assertSelectorTextContains('main', 'Matched on');
        self::assertSelectorTextNotContains('main', 'SecretMismatch');

        $client->request('GET', '/app/knowledge?q=kubernetes');
        self::assertSelectorTextContains('main', 'No resolved incident matches');
    }
}
