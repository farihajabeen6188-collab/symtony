<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Incident\IncidentGrouper;
use App\Application\Retention\RetentionPurge;
use App\Domain\Event\Event;
use App\Tests\Support\EventFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class RetentionPurgeTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testDeletesOnlyExpiredEventsWithoutIncidentOrEvidence(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $factory = new EventFactory($em);
        $app = $factory->application(null, 'shop');
        $app->getWorkspace()->setRetentionDays(7);
        $old = new \DateTimeImmutable('-30 days');

        $inIncident = $factory->exception($app, 'App\Old', 'old failure', at: $old);
        $plainOld = $factory->exception($app, 'App\Noise', 'old noise', at: $old->modify('+1 minute'));
        $cited = $factory->exception($app, 'App\Cited', 'cited evidence', at: $old->modify('+2 minutes'));
        $recent = $factory->exception($app, 'App\Recent', 'recent', at: new \DateTimeImmutable('-1 day'));
        $em->flush();
        static::getContainer()->get(IncidentGrouper::class)->group([$inIncident->getId()->toRfc4122()]);
        // Pretend an investigation cited $cited (evidence_item.event_id is a soft reference).
        $em->getConnection()->executeStatement('ALTER TABLE evidence_item DISABLE TRIGGER ALL');
        $em->getConnection()->insert('evidence_item', ['id' => '00000000-0000-7000-8000-000000000001', 'event_id' => $cited->getId()->toRfc4122(), 'data' => '{}',
            'investigation_id' => '00000000-0000-7000-8000-000000000002', 'label' => 'x', 'ref' => 'EV-1', 'kind' => 'event', 'excerpt' => 'x']);
        $em->getConnection()->executeStatement('ALTER TABLE evidence_item ENABLE TRIGGER ALL');

        $purge = static::getContainer()->get(RetentionPurge::class);
        self::assertSame(1, $purge(null, true)[$app->getWorkspace()->getSlug()], 'Dry run counts without deleting');
        self::assertSame(1, $purge()[$app->getWorkspace()->getSlug()]);

        $em->clear();
        $left = array_map(static fn (Event $e): string => (string) $e->getExceptionClass(), $em->getRepository(Event::class)->findAll());
        sort($left);
        self::assertSame(['App\Cited', 'App\Old', 'App\Recent'], $left);
        self::assertNull($em->find(Event::class, $plainOld->getId()));
    }
}
