<?php

declare(strict_types=1);

namespace App\Application\Investigation\Analyzer;

use App\Application\Investigation\EvidencePackage;
use App\Application\Investigation\FindingDraft;
use App\Domain\Investigation\EvidenceItem;

/**
 * A successful call to another system that happened before the failure is not undone by the rollback:
 * the classic orphaned side effect (here: a payment authorization without an order).
 */
final class SideEffectBeforeFailureAnalyzer implements Analyzer
{
    public const IMPACT = 'heuristic:side_effect_before_failure';

    public function name(): string
    {
        return 'side_effect_before_failure';
    }

    public function analyze(EvidencePackage $p): array
    {
        $failure = EvidenceQuery::firstFailure($p);
        if (null === $failure) {
            return [];
        }
        $failedAt = EvidenceQuery::offset($failure);
        $out = [];

        foreach (EvidenceQuery::inTrace($p, 'external_http') as $call) {
            $status = (int) ($call->getData()['external_status'] ?? 0);
            if ($status < 200 || $status >= 300 || EvidenceQuery::end($call) > $failedAt) {
                continue;
            }
            $host = (string) ($call->getData()['external_host'] ?? 'an external service');
            $rollback = $p->where(static fn (array $d): bool => 'transaction' === ($d['type'] ?? null) && 'ROLLBACK' === ($d['name'] ?? null) && ($d['trace_member'] ?? false))[0] ?? null;
            $cites = array_values(array_filter([$call->getRef(), $failure->getRef(), $rollback?->getRef()]));
            $out[] = new FindingDraft('inference',
                \sprintf('The call to %s succeeded (HTTP %d) before the failure, so its effect was not undone by the rollback.', $host, $status),
                \sprintf('The call finished %s before the failing statement; a database rollback cannot reverse a remote side effect.', EvidenceQuery::seconds($failedAt - EvidenceQuery::end($call))),
                'high', $cites, self::IMPACT, weight: 60);

            $auth = $this->authorization($p);
            if (null !== $auth) {
                $response = $p->where(static fn (array $d): bool => 'http_response' === ($d['type'] ?? null) && (int) ($d['http_status'] ?? 0) >= 500 && ($d['trace_member'] ?? false))[0] ?? null;
                $out[] = new FindingDraft('inference',
                    \sprintf('A payment authorization (%s) was created for a request that produced no order; the customer may see a hold on their card.', (string) $auth->getData()['payment_authorization']),
                    'The payment service logged an authorization in this trace, and the transaction that would have recorded the order was rolled back.',
                    'high', array_values(array_filter([$auth->getRef(), $rollback?->getRef(), $response?->getRef()])), self::IMPACT, weight: 70);
            }
            break;
        }

        return $out;
    }

    private function authorization(EvidencePackage $p): ?EvidenceItem
    {
        return $p->where(static fn (array $d, EvidenceItem $i): bool => 'event' === $i->getKind() && isset($d['payment_authorization']))[0] ?? null;
    }
}
