<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tool;

use App\Infrastructure\AI\EvidenceSession;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Read-only tools the investigator agent may call. They expose the current investigation's redacted
 * evidence and nothing else: no database, no shell, no files.
 */
#[AsTool(name: 'evidence_list', description: 'List all evidence items of this investigation (ref, kind, one-line excerpt). Cite items by their ref, e.g. EV-7.', method: 'list')]
#[AsTool(name: 'evidence_get', description: 'Get one evidence item in full (structured fields) by its ref, e.g. "EV-7".', method: 'get')]
#[AsTool(name: 'similar_incidents', description: 'Resolved incidents that look similar to this one, with their resolution notes and how they were matched.', method: 'similar')]
final class EvidenceTools
{
    public function __construct(private readonly EvidenceSession $session)
    {
    }

    public function list(): string
    {
        $this->session->log('evidence_list()');

        return $this->session->package()->index();
    }

    /**
     * @param string $ref Evidence reference such as "EV-12"
     */
    public function get(string $ref): string
    {
        $this->session->log(\sprintf('evidence_get(%s)', $ref));
        $item = $this->session->package()->get(strtoupper(trim($ref)));
        if (null === $item) {
            return \sprintf('No evidence item %s exists in this investigation. Do not cite it.', $ref);
        }

        return (string) json_encode([
            'ref' => $item->getRef(),
            'kind' => $item->getKind(),
            'label' => $item->getLabel(),
            'excerpt' => $item->getExcerpt(),
            'fields' => $item->getData(),
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    public function similar(): string
    {
        $this->session->log('similar_incidents()');
        $out = [];
        foreach ($this->session->history() as $h) {
            $out[] = [
                'incident' => $h->incident->getReference(),
                'title' => $h->incident->getTitle(),
                'match' => $h->method.' score '.$h->score,
                'shared_terms' => $h->sharedTerms,
                'resolution' => $h->resolution,
            ];
        }

        return [] === $out ? 'No similar resolved incidents were found.' : (string) json_encode($out, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
