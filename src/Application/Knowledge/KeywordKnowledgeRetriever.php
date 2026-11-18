<?php

declare(strict_types=1);

namespace App\Application\Knowledge;

use App\Domain\Incident\Incident;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Postgres full-text search over resolved incidents: title, exception class, service and resolution notes.
 * The score is ts_rank (relative, not a probability) and is labelled as such in the UI.
 */
final class KeywordKnowledgeRetriever implements KnowledgeRetriever
{
    private const STOP = ['the', 'and', 'for', 'with', 'from', 'that', 'this', 'was', 'not', 'exception', 'error', 'app', 'php', 'an', 'occurred', 'while', 'executing', 'query'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function method(): string
    {
        return 'keyword';
    }

    public function similarTo(Incident $incident, array $terms = [], int $limit = 3): array
    {
        $words = $this->terms($incident->getTitle().' '.($incident->getExceptionClass() ?? '').' '.$incident->getService().' '.implode(' ', $terms));

        return $this->run($incident->getWorkspace(), $words, $limit, $incident);
    }

    public function search(Workspace $workspace, string $query, int $limit = 10): array
    {
        return $this->run($workspace, $this->terms($query), $limit, null);
    }

    /**
     * @param list<string> $words
     *
     * @return list<SimilarIncident>
     */
    private function run(Workspace $workspace, array $words, int $limit, ?Incident $exclude): array
    {
        if ([] === $words) {
            return [];
        }
        $tsquery = implode(' | ', array_map(static fn (string $w): string => $w.':*', $words));
        $rows = $this->em->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT i.id, ts_rank(d.doc, to_tsquery('simple', :q)) AS score, d.notes, d.body
            FROM incident i
            JOIN LATERAL (
                SELECT string_agg(n.body, E'\n' ORDER BY n.created_at) FILTER (WHERE n.kind = 'resolution') AS notes,
                       lower(i.title || ' ' || coalesce(i.exception_class, '') || ' ' || i.service || ' ' || coalesce(string_agg(n.body, ' '), '')) AS body,
                       to_tsvector('simple', regexp_replace(lower(i.title || ' ' || coalesce(i.exception_class, '') || ' ' || i.service || ' ' || coalesce(string_agg(n.body, ' '), '')), '[^a-z0-9_]+', ' ', 'g')) AS doc
                FROM incident_note n WHERE n.incident_id = i.id
            ) d ON true
            WHERE i.workspace_id = :ws AND i.status = 'resolved' AND (:exclude::uuid IS NULL OR i.id <> :exclude::uuid)
              AND d.doc @@ to_tsquery('simple', :q)
            ORDER BY score DESC LIMIT :limit
            SQL, [
            'q' => $tsquery,
            'ws' => $workspace->getId()->toRfc4122(),
            'exclude' => $exclude?->getId()->toRfc4122(),
            'limit' => $limit,
        ], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER]);

        $out = [];
        foreach ($rows as $row) {
            $incident = $this->em->find(Incident::class, $row['id']);
            if (!$incident instanceof Incident) {
                continue;
            }
            $body = (string) $row['body'];
            $shared = array_values(array_filter($words, static fn (string $w): bool => 1 === preg_match('/\b'.preg_quote($w, '/').'/', $body)));
            $out[] = new SimilarIncident($incident, round((float) $row['score'], 4), $this->method(), null !== $row['notes'] ? (string) $row['notes'] : null, $shared);
        }

        return $out;
    }

    /** @return list<string> */
    private function terms(string $text): array
    {
        $text = mb_strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $text));
        $words = preg_split('/[^a-z0-9]+/', $text) ?: [];
        $words = array_filter($words, static fn (string $w): bool => mb_strlen($w) >= 4 && !ctype_digit($w) && !\in_array($w, self::STOP, true));

        return \array_slice(array_values(array_unique($words)), 0, 24);
    }
}
