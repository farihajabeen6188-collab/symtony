<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Incident\Incident;
use App\Domain\Investigation\EvidenceItem;
use App\Domain\Investigation\Finding;
use App\Domain\Investigation\Investigation;
use App\Domain\Investigation\InvestigationStatus;
use App\Domain\Investigation\InvestigationStep;
use App\Domain\Shared\TimeRange;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class InvestigationQueries
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function get(Workspace $workspace, string $id): Investigation
    {
        $inv = Uuid::isValid($id) ? $this->em->getRepository(Investigation::class)->findOneBy(['id' => Uuid::fromString($id), 'workspace' => $workspace]) : null;
        if (!$inv instanceof Investigation) {
            throw new NotFoundHttpException('Investigation not found.');
        }

        return $inv;
    }

    /** @return list<InvestigationStep> in stage order */
    public function steps(Investigation $investigation): array
    {
        /** @var list<InvestigationStep> $steps */
        $steps = $this->em->getRepository(InvestigationStep::class)->findBy(['investigation' => $investigation]);
        usort($steps, static fn (InvestigationStep $a, InvestigationStep $b): int => $a->getStage()->position() <=> $b->getStage()->position());

        return $steps;
    }

    /** @return list<Finding> */
    public function findings(Investigation $investigation): array
    {
        /** @var list<Finding> */
        return $this->em->getRepository(Finding::class)->findBy(['investigation' => $investigation], ['rank' => 'ASC']);
    }

    /** @return array<string, EvidenceItem> ref => item, in ref order */
    public function evidence(Investigation $investigation): array
    {
        $items = $this->em->getRepository(EvidenceItem::class)->findBy(['investigation' => $investigation]);
        $out = [];
        foreach ($items as $item) {
            $out[$item->getRef()] = $item;
        }
        uksort($out, static fn (string $a, string $b): int => (int) substr($a, 3) <=> (int) substr($b, 3));

        return $out;
    }

    /** @return list<Investigation> */
    public function forIncident(Incident $incident): array
    {
        /** @var list<Investigation> */
        return $this->em->getRepository(Investigation::class)->findBy(['incident' => $incident], ['createdAt' => 'DESC'], 20);
    }

    /**
     * @return array{items: list<Investigation>, total: int}
     */
    public function list(Workspace $workspace, int $limit = 50): array
    {
        /** @var list<Investigation> $items */
        $items = $this->em->createQueryBuilder()->select('v', 'i')->from(Investigation::class, 'v')->join('v.incident', 'i')
            ->where('v.workspace = :ws')->setParameter('ws', $workspace)
            ->orderBy('v.createdAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
        $total = (int) $this->em->createQueryBuilder()->select('COUNT(v.id)')->from(Investigation::class, 'v')
            ->where('v.workspace = :ws')->setParameter('ws', $workspace)->getQuery()->getSingleScalarResult();

        return ['items' => $items, 'total' => $total];
    }

    /** @return list<Investigation> */
    public function active(Workspace $workspace): array
    {
        /** @var list<Investigation> */
        return $this->em->createQueryBuilder()->select('v', 'i')->from(Investigation::class, 'v')->join('v.incident', 'i')
            ->where('v.workspace = :ws')->andWhere('v.status IN (:s)')
            ->setParameter('ws', $workspace)->setParameter('s', [InvestigationStatus::Queued->value, InvestigationStatus::Running->value])
            ->orderBy('v.createdAt', 'DESC')->setMaxResults(10)->getQuery()->getResult();
    }

    /** @return array{completed: int, median_seconds: ?float} */
    public function metrics(Workspace $workspace, TimeRange $range): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT count(*) AS n, percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM finished_at - started_at)) AS median
             FROM investigation WHERE workspace_id = :ws AND status = 'completed' AND finished_at >= :since",
            ['ws' => $workspace->getId()->toRfc4122(), 'since' => $range->since()->format('Y-m-d H:i:sP')],
        );

        return ['completed' => (int) ($row['n'] ?? 0), 'median_seconds' => null !== ($row['median'] ?? null) ? round((float) $row['median'], 1) : null];
    }

    /** @return array{verified: int, total: int} */
    public function grounding(Investigation $investigation): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT count(*) FILTER (WHERE grounding = 'verified') AS v, count(*) AS t FROM finding WHERE investigation_id = :id",
            ['id' => $investigation->getId()->toRfc4122()],
        );

        return ['verified' => (int) ($row['v'] ?? 0), 'total' => (int) ($row['t'] ?? 0)];
    }
}
