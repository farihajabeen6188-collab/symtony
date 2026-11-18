<?php

declare(strict_types=1);

namespace App\Application\Knowledge;

use App\Domain\Incident\Incident;

/**
 * Finds resolved incidents similar to a new one. Scores are whatever the method genuinely computed.
 */
interface KnowledgeRetriever
{
    /**
     * @param list<string> $terms extra search terms derived from the evidence (components, tables, keys)
     *
     * @return list<SimilarIncident>
     */
    public function similarTo(Incident $incident, array $terms = [], int $limit = 3): array;

    /** @return list<SimilarIncident> */
    public function search(\App\Domain\Workspace\Workspace $workspace, string $query, int $limit = 10): array;

    /** "keyword" (Postgres full-text) or "vector:<model>" */
    public function method(): string;
}
