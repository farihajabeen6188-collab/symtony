<?php

declare(strict_types=1);

namespace App\Application\Knowledge;

use App\Domain\Incident\Incident;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The knowledge base is the workspace's resolved incidents and what fixed them.
 * Three queries for any page size: incidents, resolution notes, latest investigations.
 */
final class KnowledgeQueries
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KnowledgeRetriever $retriever,
    ) {
    }

    /**
     * @return array{method: string, query: string, entries: list<array{incident: Incident, score: ?float, shared: list<string>, resolution: ?string, investigation: ?string}>}
     */
    public function browse(Workspace $workspace, string $query, int $limit = 30): array
    {
        $query = trim(mb_substr($query, 0, 200));
        if ('' !== $query) {
            $hits = $this->retriever->search($workspace, $query, $limit);
            $entries = array_map(static fn (SimilarIncident $s): array => ['incident' => $s->incident, 'score' => $s->score, 'shared' => $s->sharedTerms, 'resolution' => $s->resolution, 'investigation' => null], $hits);
        } else {
            /** @var list<Incident> $incidents */
            $incidents = $this->em->getRepository(Incident::class)->findBy(['workspace' => $workspace, 'status' => 'resolved'], ['resolvedAt' => 'DESC'], $limit);
            $notes = $this->resolutionNotes($incidents);
            $entries = array_map(static fn (Incident $i): array => ['incident' => $i, 'score' => null, 'shared' => [], 'resolution' => $notes[$i->getId()->toRfc4122()] ?? null, 'investigation' => null], $incidents);
        }

        $latest = $this->latestInvestigations(array_map(static fn (array $e): Incident => $e['incident'], $entries));
        foreach ($entries as $k => $e) {
            $entries[$k]['investigation'] = $latest[$e['incident']->getId()->toRfc4122()] ?? null;
        }

        return ['method' => $this->retriever->method(), 'query' => $query, 'entries' => $entries];
    }

    public function resolvedCount(Workspace $workspace): int
    {
        return $this->em->getRepository(Incident::class)->count(['workspace' => $workspace, 'status' => 'resolved']);
    }

    /**
     * @param list<Incident> $incidents
     *
     * @return array<string, string> incident id → resolution notes
     */
    private function resolutionNotes(array $incidents): array
    {
        if ([] === $incidents) {
            return [];
        }

        return $this->em->getConnection()->fetchAllKeyValue(
            "SELECT incident_id, string_agg(body, E'\\n\\n' ORDER BY created_at) FROM incident_note WHERE kind = 'resolution' AND incident_id IN (?) GROUP BY incident_id",
            [array_map(static fn (Incident $i): string => $i->getId()->toRfc4122(), $incidents)],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    /**
     * @param list<Incident> $incidents
     *
     * @return array<string, string> incident id → latest investigation id
     */
    private function latestInvestigations(array $incidents): array
    {
        if ([] === $incidents) {
            return [];
        }

        return $this->em->getConnection()->fetchAllKeyValue(
            'SELECT DISTINCT ON (incident_id) incident_id, id FROM investigation WHERE incident_id IN (?) ORDER BY incident_id, created_at DESC',
            [array_map(static fn (Incident $i): string => $i->getId()->toRfc4122(), $incidents)],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }
}
