<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\Investigation\FindingDraft;

/**
 * Parses the investigator's JSON answer into typed drafts. Anything malformed is rejected loudly
 * (the stage fails and says why) rather than being guessed at.
 *
 * Expected shape:
 * {"summary": str, "hypotheses": [{"statement","rationale","confidence","supporting":[refs],"contradicting":[refs]}],
 *  "facts": [{"statement","evidence":[refs]}], "impact": {"statement","evidence":[refs]},
 *  "recommended_fix": str, "next_steps": [str], "reproduction": str, "limitations": [str]}
 */
final class LlmReportParser
{
    /**
     * @return array{summary: string, drafts: list<FindingDraft>, report: array<string, mixed>}
     */
    public function parse(mixed $raw, string $source): array
    {
        $data = match (true) {
            \is_array($raw) => $raw,
            \is_object($raw) => json_decode((string) json_encode($raw), true),
            \is_string($raw) => $this->decode($raw),
            default => null,
        };
        if (!\is_array($data)) {
            throw new InvalidModelOutput('The model did not return a JSON object.');
        }
        if (!isset($data['summary']) || !\is_string($data['summary']) || '' === trim($data['summary'])) {
            throw new InvalidModelOutput('The model answer has no "summary".');
        }
        if (!isset($data['hypotheses']) || !\is_array($data['hypotheses'])) {
            throw new InvalidModelOutput('The model answer has no "hypotheses" list.');
        }

        $drafts = [];
        foreach (array_values($data['hypotheses']) as $i => $h) {
            if (!\is_array($h) || !\is_string($h['statement'] ?? null)) {
                throw new InvalidModelOutput(\sprintf('Hypothesis %d has no statement.', $i + 1));
            }
            $drafts[] = new FindingDraft('hypothesis', $h['statement'], (string) ($h['rationale'] ?? ''),
                $this->confidence($h['confidence'] ?? 'low'), $this->refs($h['supporting'] ?? []), $source, $this->refs($h['contradicting'] ?? []), 90 - $i * 10);
        }
        foreach (\is_array($data['facts'] ?? null) ? $data['facts'] : [] as $f) {
            if (\is_array($f) && \is_string($f['statement'] ?? null)) {
                $drafts[] = new FindingDraft('fact', $f['statement'], 'Stated by the model from the cited evidence.', 'high', $this->refs($f['evidence'] ?? []), $source, weight: 5);
            }
        }
        $impact = \is_array($data['impact'] ?? null) && \is_string($data['impact']['statement'] ?? null) ? $data['impact'] : null;
        if (null !== $impact) {
            $drafts[] = new FindingDraft('inference', $impact['statement'], 'Impact assessed by the model.', 'medium', $this->refs($impact['evidence'] ?? []), $source.'#impact', weight: 4);
        }

        return [
            'summary' => trim($data['summary']),
            'drafts' => $drafts,
            'report' => array_filter([
                'recommended_fix' => \is_string($data['recommended_fix'] ?? null) ? $data['recommended_fix'] : null,
                'next_steps' => $this->strings($data['next_steps'] ?? []),
                'reproduction' => \is_string($data['reproduction'] ?? null) ? $data['reproduction'] : null,
                'limitations' => $this->strings($data['limitations'] ?? []),
            ], static fn ($v): bool => null !== $v && [] !== $v),
        ];
    }

    /** @return array<mixed>|null */
    private function decode(string $text): ?array
    {
        $text = trim($text);
        // Tolerate a fenced code block, but nothing else around the JSON.
        if (1 === preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $text, $m)) {
            $text = $m[1];
        }
        try {
            $data = json_decode($text, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidModelOutput('The model answer is not valid JSON: '.$e->getMessage());
        }

        return \is_array($data) ? $data : null;
    }

    /** @return list<string> */
    private function refs(mixed $refs): array
    {
        if (!\is_array($refs)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static fn ($r): string => strtoupper(trim((string) $r)), $refs), static fn (string $r): bool => '' !== $r)));
    }

    /** @return 'low'|'medium'|'high' */
    private function confidence(mixed $c): string
    {
        $c = strtolower((string) $c);

        return \in_array($c, ['low', 'medium', 'high'], true) ? $c : 'low';
    }

    /** @return list<string> */
    private function strings(mixed $list): array
    {
        return \is_array($list) ? array_values(array_filter(array_map(static fn ($s): string => \is_string($s) ? trim($s) : '', $list))) : [];
    }
}
