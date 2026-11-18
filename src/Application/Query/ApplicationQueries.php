<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Application\Application;
use App\Domain\Application\IngestionCredential;
use App\Domain\Event\Event;
use App\Domain\Event\IngestionFailure;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Read models for application pages. Every query is scoped to a workspace.
 */
final class ApplicationQueries
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<Application> */
    public function list(Workspace $workspace): array
    {
        /** @var list<Application> */
        return $this->em->getRepository(Application::class)->findBy(['workspace' => $workspace], ['createdAt' => 'ASC']);
    }

    /** By id or slug, inside the workspace. */
    public function find(Workspace $workspace, string $idOrSlug): Application
    {
        if (Uuid::isValid($idOrSlug)) {
            return $this->get($workspace, $idOrSlug);
        }
        $app = $this->em->getRepository(Application::class)->findOneBy(['slug' => $idOrSlug, 'workspace' => $workspace]);
        if (!$app instanceof Application) {
            throw new NotFoundHttpException(\sprintf('No application "%s" in this workspace. Use list_applications to see the available ones.', $idOrSlug));
        }

        return $app;
    }

    /**
     * Events, errors and error rate per service of one application since a point in time.
     *
     * @return list<array{service: string, events: int, errors: int, error_rate_percent: float, last_event: string}>
     */
    public function serviceHealth(Application $app, \DateTimeImmutable $since): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT service, count(*) AS events, count(*) FILTER (WHERE severity IN ('error','critical')) AS errors, max(occurred_at) AS last_event
             FROM event WHERE application_id = :app AND occurred_at >= :since GROUP BY service ORDER BY errors DESC, events DESC LIMIT 50",
            ['app' => $app->getId()->toRfc4122(), 'since' => $since->format('Y-m-d H:i:sP')],
        );

        return array_map(static fn (array $r): array => [
            'service' => (string) $r['service'],
            'events' => (int) $r['events'],
            'errors' => (int) $r['errors'],
            'error_rate_percent' => (int) $r['events'] > 0 ? round((int) $r['errors'] / (int) $r['events'] * 100, 2) : 0.0,
            'last_event' => (new \DateTimeImmutable((string) $r['last_event']))->format(\DATE_ATOM),
        ], $rows);
    }

    public function get(Workspace $workspace, string $id): Application
    {
        $app = Uuid::isValid($id) ? $this->em->getRepository(Application::class)->findOneBy(['id' => Uuid::fromString($id), 'workspace' => $workspace]) : null;
        if (!$app instanceof Application) {
            throw new NotFoundHttpException('Application not found.');
        }

        return $app;
    }

    public function credential(Application $app, string $id): IngestionCredential
    {
        $c = Uuid::isValid($id) ? $this->em->getRepository(IngestionCredential::class)->findOneBy(['id' => Uuid::fromString($id), 'application' => $app]) : null;
        if (!$c instanceof IngestionCredential) {
            throw new NotFoundHttpException('Credential not found.');
        }

        return $c;
    }

    /** @return list<IngestionCredential> */
    public function credentials(Application $app): array
    {
        /** @var list<IngestionCredential> */
        return $this->em->getRepository(IngestionCredential::class)->findBy(['application' => $app], ['createdAt' => 'DESC']);
    }

    /**
     * Event and error counts per application since a date, in one query.
     *
     * @return array<string, array{events: int, errors: int}>
     */
    public function eventCounts(Workspace $workspace, \DateTimeImmutable $since): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT application_id, COUNT(*) AS events, COUNT(*) FILTER (WHERE severity IN ('error','critical')) AS errors
             FROM event WHERE workspace_id = :ws AND occurred_at >= :since GROUP BY application_id",
            ['ws' => $workspace->getId()->toRfc4122(), 'since' => $since->format('Y-m-d H:i:sP')],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['application_id']] = ['events' => (int) $row['events'], 'errors' => (int) $row['errors']];
        }

        return $out;
    }

    public function latestEvent(Application $app): ?Event
    {
        $e = $this->em->getRepository(Event::class)->findOneBy(['application' => $app], ['receivedAt' => 'DESC', 'id' => 'DESC']);

        return $e instanceof Event ? $e : null;
    }

    /** @return list<IngestionFailure> */
    public function recentFailures(Application $app, int $limit = 10): array
    {
        /** @var list<IngestionFailure> */
        return $this->em->getRepository(IngestionFailure::class)->findBy(['application' => $app], ['receivedAt' => 'DESC'], $limit);
    }

    /** @return list<string> distinct environments in use, for the top-bar filter */
    public function environments(Workspace $workspace): array
    {
        $rows = $this->em->createQuery('SELECT DISTINCT a.environment FROM '.Application::class.' a WHERE a.workspace = :ws')
            ->setParameter('ws', $workspace)
            ->getSingleColumnResult();

        $values = array_map(static fn (mixed $e): string => $e instanceof \BackedEnum ? (string) $e->value : (string) $e, $rows);
        sort($values);

        return $values;
    }

    public function slugAvailable(Workspace $workspace, string $slug): bool
    {
        return null === $this->em->getRepository(Application::class)->findOneBy(['workspace' => $workspace, 'slug' => $slug]);
    }
}
