<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Incident\IncidentGrouper;
use App\Domain\Assistant\AssistantMessage;
use App\Domain\Workspace\Workspace;
use App\Tests\Support\EventFactory;
use Doctrine\ORM\EntityManagerInterface;

final class AssistantPanelTest extends WebTestCase
{
    public function testPanelSaysWhyItCannotAnswerAndNeverFakesOne(): void
    {
        $client = $this->signedInClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $factory = new EventFactory($em);
        $e = $factory->exception($factory->application($em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo'])), 'App\X', 'boom');
        static::getContainer()->get(IncidentGrouper::class)->group([$e->getId()->toRfc4122()]);

        $client->request('GET', '/app/incidents/1');
        self::assertSelectorExists('turbo-frame#assistant-panel[src$="/app/incidents/1/assistant"]');

        $client->request('GET', '/app/incidents/1/assistant');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#assistant-panel', 'No AI provider configured');
        self::assertSelectorNotExists('textarea[name="question"]');

        $client->request('GET', '/app/incidents/1');
        $client->request('POST', '/app/incidents/1/assistant', ['question' => 'Why?', '_token' => 'invalid']);
        self::assertFalse($client->getResponse()->isSuccessful());
        self::assertSame(0, $em->getRepository(AssistantMessage::class)->count([]), 'Nothing stored, nothing invented');

        $client->request('GET', '/app/incidents/99/assistant');
        self::assertResponseStatusCodeSame(404);
    }
}
