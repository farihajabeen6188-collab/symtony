<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Incident\IncidentActions;
use App\Application\Incident\IncidentGrouper;
use App\Application\Investigation\InvestigationRunner;
use App\Application\Investigation\StartInvestigation;
use App\Application\Notification\CheckSilentApplications;
use App\Domain\Event\Severity;
use App\Domain\Incident\Incident;
use App\Domain\Notification\Notification;
use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use App\Tests\Support\EventFactory;
use Doctrine\ORM\EntityManagerInterface;

final class NotificationsTest extends WebTestCase
{
    public function testBackgroundWorkNotifiesAndTheUserReadsThem(): void
    {
        $client = $this->signedInClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->demoUser();
        $factory = new EventFactory($em);
        $app = $factory->application($em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo']), 'billing');

        // A critical failure → one notification, even when it happens again.
        $grouper = static::getContainer()->get(IncidentGrouper::class);
        $first = $factory->exception($app, 'App\Ledger\Corrupted', 'Ledger corrupted', severity: Severity::Critical, at: new \DateTimeImmutable('-10 minutes'));
        $grouper->group([$first->getId()->toRfc4122()]);
        $grouper->group([$factory->exception($app, 'App\Ledger\Corrupted', 'Ledger corrupted', severity: Severity::Critical, at: new \DateTimeImmutable('-9 minutes'))->getId()->toRfc4122()]);
        self::assertSame(1, $this->kinds($em, 'incident.critical'));

        // Resolved, then it happens again → reopened.
        $incident = $em->getRepository(Incident::class)->findOneBy([]);
        static::getContainer()->get(IncidentActions::class)->transition($incident, 'resolve', $user, 'Fixed the ledger lock.');
        $grouper->group([$factory->exception($app, 'App\Ledger\Corrupted', 'Ledger corrupted', severity: Severity::Critical)->getId()->toRfc4122()]);
        self::assertSame(1, $this->kinds($em, 'incident.reopened'));

        // An investigation finishes.
        $inv = static::getContainer()->get(StartInvestigation::class)($incident, null, 'system');
        static::getContainer()->get(InvestigationRunner::class)->run($inv);
        self::assertSame(1, $this->kinds($em, 'investigation.completed') + $this->kinds($em, 'investigation.failed'));

        // The application went silent long ago.
        $em->getConnection()->executeStatement("UPDATE application SET last_event_at = now() - interval '2 hours' WHERE slug = 'billing'");
        $em->clear();
        static::getContainer()->get(CheckSilentApplications::class)();
        static::getContainer()->get(CheckSilentApplications::class)();
        self::assertSame(1, $this->kinds($em, 'application.silent'), 'Once per period of silence');

        $crawler = $client->request('GET', '/app/incidents');
        self::assertSelectorTextContains('[data-testid="unread-count"]', '4');
        $client->request('GET', '/app/notifications/menu');
        self::assertSelectorTextContains('turbo-frame#notifications-menu', 'Billing stopped sending events');

        $crawler = $client->request('GET', '/app/notifications');
        self::assertSelectorTextContains('main', 'Critical incident SYM-1');
        $client->submit($crawler->filter('form[action$="/open"]')->first()->form());
        self::assertResponseRedirects();
        self::assertStringStartsWith('/app/', (string) $client->getResponse()->headers->get('Location'));
        self::assertSame(3, $this->unread($em));

        $crawler = $client->request('GET', '/app/notifications?show=unread');
        self::assertCount(3, $crawler->filter('main form[action$="/open"]'));
        $client->submit($crawler->filter('form[action$="/read-all"]')->form());
        $client->followRedirect();
        self::assertSame(0, $this->unread($em));
        self::assertSelectorNotExists('[data-testid="unread-count"]');
        $client->request('GET', '/app/notifications?show=unread');
        self::assertSelectorTextContains('main', 'All caught up');
    }

    public function testOtherUsersCannotOpenMyNotifications(): void
    {
        $client = $this->signedInClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $other = new User('someone@example.test', 'Someone');
        $other->setPassword('x');
        $ws = new Workspace('Elsewhere', 'elsewhere');
        $em->persist($other);
        $em->persist($ws);
        $note = new Notification($ws, $other, 'incident.critical', 'err', 'Secret', 'secret body', '/app/incidents/1');
        $em->persist($note);
        $em->flush();

        $client->request('GET', '/app/notifications');
        self::assertSelectorTextNotContains('main', 'Secret');
        $crawler = $client->request('GET', '/app/notifications');
        $token = $crawler->filter('form[action$="/read-all"] input[name="_token"]')->attr('value');
        $client->request('POST', '/app/notifications/'.$note->getId().'/open', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
    }

    private function kinds(EntityManagerInterface $em, string $kind): int
    {
        return (int) $em->getConnection()->fetchOne('SELECT count(*) FROM notification WHERE kind = :k', ['k' => $kind]);
    }

    private function unread(EntityManagerInterface $em): int
    {
        return (int) $em->getConnection()->fetchOne('SELECT count(*) FROM notification WHERE read_at IS NULL');
    }
}
