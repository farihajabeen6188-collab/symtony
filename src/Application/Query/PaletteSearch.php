<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Application\Application;
use App\Domain\Application\IngestionMethod;
use App\Domain\Event\Event;
use App\Domain\Incident\Incident;
use App\Domain\Investigation\Investigation;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Server-side search for the command palette: incidents, applications, investigations, events by id or
 * trace id, plus contextual actions. Every query is workspace-scoped and limited.
 */
final class PaletteSearch
{
    private const LIMIT = 5;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{incidents: list<Incident>, applications: list<Application>, investigations: list<Investigation>, event: ?Event, trace: ?string, demo_apps: list<Application>, context_incident: ?Incident}
     */
    public function search(Workspace $ws, string $q, string $context): array
    {
        $q = trim(mb_substr($q, 0, 120));
        $like = '%'.mb_strtolower($q).'%';
        $incidents = [];
        $applications = [];
        $investigations = [];
        $event = null;
        $trace = null;

        if ('' !== $q) {
            $qb = $this->em->createQueryBuilder()->select('i')->from(Incident::class, 'i')->where('i.workspace = :ws')->setParameter('ws', $ws)
                ->orderBy('i.lastSeenAt', 'DESC')->setMaxResults(self::LIMIT);
            if (1 === preg_match('/^(?:SYM-?)?(\d{1,9})$/i', $q, $m)) {
                $qb->andWhere('i.number = :n')->setParameter('n', (int) $m[1]);
            } else {
                $qb->andWhere('LOWER(i.title) LIKE :q OR LOWER(i.exceptionClass) LIKE :q OR LOWER(i.service) LIKE :q')->setParameter('q', $like);
            }
            /** @var list<Incident> $incidents */
            $incidents = $qb->getQuery()->getResult();

            /** @var list<Application> $applications */
            $applications = $this->em->createQueryBuilder()->select('a')->from(Application::class, 'a')
                ->where('a.workspace = :ws')->andWhere('LOWER(a.name) LIKE :q OR LOWER(a.slug) LIKE :q')
                ->setParameter('ws', $ws)->setParameter('q', $like)->orderBy('a.name')->setMaxResults(self::LIMIT)
                ->getQuery()->getResult();

            if ([] !== $incidents) {
                /** @var list<Investigation> $investigations */
                $investigations = $this->em->createQueryBuilder()->select('v', 'i')->from(Investigation::class, 'v')->join('v.incident', 'i')
                    ->where('v.incident IN (:incidents)')->setParameter('incidents', $incidents)
                    ->orderBy('v.createdAt', 'DESC')->setMaxResults(3)->getQuery()->getResult();
            }

            if (Uuid::isValid($q)) {
                $found = $this->em->getRepository(Event::class)->findOneBy(['id' => Uuid::fromString($q), 'workspace' => $ws]);
                $event = $found instanceof Event ? $found : null;
            } elseif (1 === preg_match('/^[0-9a-f]{16,32}$/i', $q)) {
                $trace = strtolower($q);
            }
        }

        $contextIncident = null;
        if (1 === preg_match('/^incident:(\d+)$/', $context, $m)) {
            $found = $this->em->getRepository(Incident::class)->findOneBy(['workspace' => $ws, 'number' => (int) $m[1]]);
            $contextIncident = $found instanceof Incident ? $found : null;
        }

        /** @var list<Application> $demo */
        $demo = $this->em->getRepository(Application::class)->findBy(['workspace' => $ws, 'ingestionMethod' => IngestionMethod::Demo], ['name' => 'ASC'], 2);

        return [
            'incidents' => $incidents,
            'applications' => $applications,
            'investigations' => $investigations,
            'event' => $event,
            'trace' => $trace,
            'demo_apps' => $demo,
            'context_incident' => $contextIncident,
        ];
    }
}
