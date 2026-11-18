<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Incident\IncidentActions;
use App\Application\Incident\IncidentGrouper;
use App\Domain\Event\Event;
use App\Domain\Incident\Incident;
use App\Domain\Incident\IncidentStatus;
use App\Domain\Workspace\User;
use App\Tests\Support\EventFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class IncidentGroupingTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $em;
    private EventFactory $events;
    private IncidentGrouper $grouper;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->events = new EventFactory($this->em);
        $this->grouper = static::getContainer()->get(IncidentGrouper::class);
    }

    public function testThousandIdenticalExceptionsMakeOneIncident(): void
    {
        $app = $this->events->application();
        $ids = [];
        $t = new \DateTimeImmutable('-1 hour');
        for ($i = 0; $i < 1000; ++$i) {
            $ids[] = $this->persistQuietly($app, "Stock for order $i went negative", $t->modify("+$i seconds"));
        }
        $this->em->flush();

        // Delivered in several messages, as the worker would receive them.
        foreach (array_chunk($ids, 250) as $chunk) {
            $this->grouper->group($chunk);
        }

        $incidents = $this->em->getRepository(Incident::class)->findAll();
        self::assertCount(1, $incidents);
        self::assertSame(1000, $incidents[0]->getOccurrences());
        self::assertEquals($t, $incidents[0]->getFirstSeenAt());
        self::assertSame('SYM-1', $incidents[0]->getReference());
    }

    public function testRedeliveryIsIdempotent(): void
    {
        $app = $this->events->application();
        $e = $this->events->exception($app, 'App\StockException', 'boom');

        $this->grouper->group([$e->getId()->toRfc4122()]);
        $this->grouper->group([$e->getId()->toRfc4122()]);

        self::assertSame(1, $this->em->getRepository(Incident::class)->findAll()[0]->getOccurrences());
    }

    public function testNonCandidatesAreIgnored(): void
    {
        $app = $this->events->application();
        $span = $this->events->event($app, \App\Domain\Event\EventType::DbQuery, 'UPDATE product', \App\Domain\Event\Severity::Error);

        $result = $this->grouper->group([$span->getId()->toRfc4122()]);

        self::assertSame(0, $result['grouped']);
        self::assertSame([], $this->em->getRepository(Incident::class)->findAll());
    }

    public function testNewOccurrenceAfterResolutionReopens(): void
    {
        $app = $this->events->application();
        $first = $this->events->exception($app, 'App\MailerTimeout', 'smtp timed out', new \DateTimeImmutable('-3 days'));
        $this->grouper->group([$first->getId()->toRfc4122()]);

        $incident = $this->em->getRepository(Incident::class)->findOneBy([]);
        $user = new User('qa@example.test', 'QA');
        $this->em->persist($user);
        static::getContainer()->get(IncidentActions::class)->transition($incident, 'resolve', $user, 'Provider recovered.');
        self::assertSame(IncidentStatus::Resolved, $incident->getStatus());

        // An old (pre-resolution) event arriving late must not reopen it…
        $late = $this->events->exception($app, 'App\MailerTimeout', 'smtp timed out', new \DateTimeImmutable('-4 days'));
        $this->grouper->group([$late->getId()->toRfc4122()]);
        $this->em->clear();
        self::assertSame(IncidentStatus::Resolved, $this->em->getRepository(Incident::class)->findOneBy([])->getStatus());
        $app = $this->em->find(\App\Domain\Application\Application::class, $app->getId());

        // …a new one does.
        $again = $this->events->exception($app, 'App\MailerTimeout', 'smtp timed out');
        $result = $this->grouper->group([$again->getId()->toRfc4122()]);
        $this->em->clear();
        $incident = $this->em->getRepository(Incident::class)->findOneBy([]);

        self::assertSame(1, $result['reopened']);
        self::assertSame(IncidentStatus::Reopened, $incident->getStatus());
        self::assertSame(1, $incident->getReopenCount());
        self::assertSame(3, $incident->getOccurrences());
        self::assertCount(1, $this->em->getRepository(Incident::class)->findAll(), 'Reopen, never duplicate');
    }

    public function testWorkflowGuardsInvalidTransitions(): void
    {
        $app = $this->events->application();
        $e = $this->events->exception($app, 'App\X', 'x');
        $this->grouper->group([$e->getId()->toRfc4122()]);
        $incident = $this->em->getRepository(Incident::class)->findOneBy([]);
        $actions = static::getContainer()->get(IncidentActions::class);
        $user = new User('qa2@example.test', 'QA');
        $this->em->persist($user);

        self::assertSame(['start_investigation', 'identify', 'resolve'], $actions->availableTransitions($incident));
        $this->expectException(\DomainException::class);
        $actions->transition($incident, 'reopen', $user);
    }

    public function testConcurrentWorkersCreateExactlyOneIncident(): void
    {
        $app = $this->events->application();
        for ($i = 0; $i < 40; ++$i) {
            $this->persistQuietly($app, "Concurrent failure $i", new \DateTimeImmutable("-$i seconds"));
        }
        $this->em->flush();

        // Two real processes race over the same backlog.
        $cmd = ['php', 'bin/console', 'symtony:incidents:group-pending', '--env=test']; // debug: the cache is checked for freshness
        $a = new Process($cmd, static::getContainer()->getParameter('kernel.project_dir'));
        $b = new Process($cmd, static::getContainer()->getParameter('kernel.project_dir'));
        $a->start();
        $b->start();
        $a->wait();
        $b->wait();
        self::assertTrue($a->isSuccessful(), $a->getErrorOutput());
        self::assertTrue($b->isSuccessful(), $b->getErrorOutput());

        $this->em->clear();
        $incidents = $this->em->getRepository(Incident::class)->findAll();
        self::assertCount(1, $incidents);
        self::assertSame(40, $incidents[0]->getOccurrences(), 'Each event counted exactly once across both workers');
    }

    private function persistQuietly(\App\Domain\Application\Application $app, string $message, \DateTimeImmutable $at): string
    {
        $event = new Event($app, $at, \App\Domain\Event\Severity::Error, \App\Domain\Event\EventType::Exception, 'shop', $message,
            (new \App\Infrastructure\Ingestion\MessageNormalizer())->normalize($message), 'test',
            exceptionClass: 'App\StockException', exceptionMessage: $message,
            stackTrace: [['class' => 'App\Inventory\InventoryService', 'function' => 'reserve', 'in_app' => true]]);
        $this->em->persist($event);

        return $event->getId()->toRfc4122();
    }
}
