<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Application\Application;
use App\Domain\Application\Environment;
use App\Domain\Application\Framework;
use App\Domain\Application\IngestionMethod;
use App\Domain\Event\Event;
use App\Domain\Event\EventType;
use App\Domain\Event\Severity;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Ingestion\MessageNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds persisted applications and events for tests.
 */
final class EventFactory
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function application(?Workspace $workspace = null, string $slug = 'shop'): Application
    {
        if (null === $workspace) {
            $workspace = new Workspace('WS '.$slug, 'ws-'.$slug.'-'.bin2hex(random_bytes(3)));
            $this->em->persist($workspace);
        }
        $app = new Application($workspace, ucfirst($slug), $slug, Environment::Production, Framework::Symfony, IngestionMethod::HttpJson);
        $this->em->persist($app);
        $this->em->flush();

        return $app;
    }

    /**
     * @param array<string, mixed>                     $context
     * @param list<array<string, mixed>>|null          $frames
     */
    public function exception(Application $app, string $class, string $message, ?\DateTimeImmutable $at = null, ?array $frames = null, array $context = [], string $service = 'shop', Severity $severity = Severity::Error, ?string $traceId = null): Event
    {
        $event = new Event(
            application: $app,
            occurredAt: $at ?? new \DateTimeImmutable(),
            severity: $severity,
            type: EventType::Exception,
            service: $service,
            message: $message,
            normalizedMessage: (new MessageNormalizer())->normalize($message),
            source: 'test',
            traceId: $traceId,
            exceptionClass: $class,
            exceptionMessage: $message,
            stackTrace: $frames ?? [
                ['class' => 'App\Inventory\InventoryService', 'function' => 'reserve', 'file' => 'src/Inventory/InventoryService.php', 'line' => 58, 'in_app' => true],
                ['class' => 'App\Checkout\CheckoutService', 'function' => 'checkout', 'file' => 'src/Checkout/CheckoutService.php', 'line' => 61, 'in_app' => true],
            ],
            context: $context,
        );
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    /** @param array<string, mixed> $context */
    public function event(Application $app, EventType $type, string $name, Severity $severity = Severity::Info, ?\DateTimeImmutable $at = null, ?string $traceId = null, array $context = [], string $service = 'shop'): Event
    {
        $event = new Event(
            application: $app,
            occurredAt: $at ?? new \DateTimeImmutable(),
            severity: $severity,
            type: $type,
            service: $service,
            message: $name,
            normalizedMessage: (new MessageNormalizer())->normalize($name),
            source: 'test',
            name: $name,
            traceId: $traceId,
            context: $context,
        );
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }
}
