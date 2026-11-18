<?php

declare(strict_types=1);

namespace App\Application\Investigation\Analyzer;

use App\Application\Investigation\EvidencePackage;
use App\Application\Investigation\FindingDraft;

/**
 * Database constraint violation → rolled-back transaction → failed request. Facts only, straight from the trace.
 */
final class ConstraintViolationAnalyzer implements Analyzer
{
    public function name(): string
    {
        return 'constraint_rollback';
    }

    public function analyze(EvidencePackage $p): array
    {
        $failure = EvidenceQuery::firstFailure($p);
        $exception = EvidenceQuery::exceptionEvidence($p);
        $text = (string) ($failure?->getData()['db_error'] ?? '').' '.($exception?->getData()['exception_message'] ?? '');
        if (1 !== preg_match('/(?:CHECK constraint failed: (\w+)|violates check constraint "(\w+)"|(?:unique|foreign key|not-null) constraint (?:failed: )?"?([\w.]+)"?)/i', $text, $m)) {
            return [];
        }
        $constraint = $m[1] ?: ($m[2] ?? '') ?: ($m[3] ?? 'unknown');
        $out = [];
        $source = 'heuristic:'.$this->name();

        $cites = array_values(array_filter([$failure?->getRef(), $exception?->getRef()]));
        $operation = $failure?->getData()['db_statement'] ?? $failure?->getData()['name'] ?? 'A database write';
        $out[] = new FindingDraft('fact',
            \sprintf('The write "%s" violated the database constraint %s.', $operation, $constraint),
            'The first failing span in the trace is this statement, and the exception reports the constraint by name.',
            'high', $cites, $source, weight: 50);

        $rollback = $p->where(static fn (array $d): bool => 'transaction' === ($d['type'] ?? null) && 'ROLLBACK' === ($d['name'] ?? null) && ($d['trace_member'] ?? false))[0] ?? null;
        if (null !== $rollback) {
            $out[] = new FindingDraft('fact', 'The database transaction was rolled back, so none of its writes were kept.', 'A ROLLBACK follows the failed statement in the same trace.', 'high', array_values(array_filter([$rollback->getRef(), $failure?->getRef()])), $source, weight: 40);
        }

        $response = $p->where(static fn (array $d): bool => 'http_response' === ($d['type'] ?? null) && (int) ($d['http_status'] ?? 0) >= 500 && ($d['trace_member'] ?? false))[0] ?? null;
        if (null !== $response) {
            $out[] = new FindingDraft('fact', \sprintf('The request ended with HTTP %d.', (int) $response->getData()['http_status']), 'The trace\'s response event reports the status.', 'high', [$response->getRef()], $source, weight: 30);
        }

        return $out;
    }
}
