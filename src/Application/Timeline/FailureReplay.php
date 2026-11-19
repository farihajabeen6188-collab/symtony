<?php

declare(strict_types=1);

namespace App\Application\Timeline;

/**
 * Turns a timeline (Timeline::toArray(), or the exported landing JSON) into a step-by-step replay of the
 * failing request: the proven steps in order, the first failure, the rollback, and what the rollback undid
 * or could not undo. Everything is read from trace fields; nothing is written in prose that the data does
 * not say. Context from other traces is kept apart and stays labelled as inferred.
 */
final class FailureReplay
{
    private const TYPES = [
        'http_request' => 'request', 'http_response' => 'response', 'controller' => 'controller',
        'service_call' => 'call', 'db_query' => 'query', 'transaction' => 'transaction', 'cache' => 'cache',
        'external_http' => 'outgoing HTTP', 'queue' => 'message', 'exception' => 'exception', 'log' => 'log',
        'deploy' => 'deploy',
    ];
    private const CONTEXT_LIMIT = 8;
    private const CONTEXT_WINDOW_MS = 15000;

    /**
     * @param array<string, mixed> $timeline
     *
     * @return array{trace_id: ?string, duration_ms: float, participants: list<array{id: string, label: string, kind: string}>, steps: list<array<string, mixed>>, context: list<array<string, mixed>>, failure: ?int, rollback: ?int, undone: list<int>, survived: list<int>, outcome: ?string}|null
     */
    public function build(array $timeline): ?array
    {
        /** @var list<array<string, mixed>> $items */
        $items = array_values(array_filter((array) ($timeline['items'] ?? []), static fn ($i): bool => \is_array($i)));
        $proven = array_values(array_filter($items, static fn (array $i): bool => true === ($i['proven'] ?? false)));
        if ([] === $proven) {
            return null;
        }
        usort($proven, static fn (array $a, array $b): int => (float) $a['offset_ms'] <=> (float) $b['offset_ms']);
        $start = (float) $proven[0]['offset_ms'];
        $last = $proven[\count($proven) - 1];
        $duration = max(1.0, (float) $last['offset_ms'] + (float) ($last['duration_ms'] ?? 0) - $start);
        $firstFailure = $timeline['first_failure'] ?? null;

        $steps = [];
        $failure = null;
        $rollback = null;
        foreach ($proven as $index => $item) {
            $steps[] = $this->step($item, $index, $start, $duration);
            if ($item['id'] === $firstFailure) {
                $failure = $index;
            }
            if ('transaction' === $item['type'] && 'ROLLBACK' === strtoupper((string) $item['name']) && null === $rollback) {
                $rollback = $index;
            }
        }

        [$undone, $survived] = null !== $rollback ? $this->effects($steps, $rollback) : [[], []];
        foreach ($undone as $i) {
            $steps[$i]['effect'] = 'rolled_back';
        }
        foreach ($survived as $i) {
            $steps[$i]['effect'] = 'survived';
        }
        if (null !== $failure) {
            $steps[$failure]['role'] = 'failure';
        }
        if (null !== $rollback) {
            $steps[$rollback]['role'] = 'rollback';
        }
        $participants = $this->assignParticipants($steps);
        $final = null;
        foreach (array_reverse($steps) as $s) {
            if ('http_response' === $s['type'] && null !== $s['status']) {
                $final = $s;
                break;
            }
        }

        return [
            'trace_id' => isset($timeline['trace_id']) ? (string) $timeline['trace_id'] : null,
            'duration_ms' => round($duration, 3),
            'participants' => $participants,
            'steps' => $steps,
            'context' => $this->context($items, $start),
            'failure' => $failure,
            'rollback' => $rollback,
            'undone' => $undone,
            'survived' => $survived,
            'outcome' => null !== $final ? \sprintf('%s %s', $final['service'], $final['name']) : null,
        ];
    }

    /**
     * Who talks to whom at each step, for the sequence view: the client, each service, its database and
     * cache, and called services. A request is attributed to the service whose outgoing call is still open
     * (proven by the shared trace); otherwise to the client. Sets `from`, `to` and `kind` on every step.
     *
     * @param list<array<string, mixed>> $steps
     *
     * @return list<array{id: string, label: string, kind: string}>
     */
    private function assignParticipants(array &$steps): array
    {
        $participants = [];
        $add = static function (string $id, string $label, string $kind) use (&$participants): string {
            $participants[$id] ??= ['id' => $id, 'label' => $label, 'kind' => $kind];

            return $id;
        };
        $client = $add('client', 'Client', 'client');
        $callers = []; // service => who sent it the request (stack per service)
        $openCalls = []; // outgoing calls waiting for their request: list of [service, index]

        foreach ($steps as $i => &$s) {
            $svc = $add('svc:'.$s['service'], (string) $s['service'], 'service');
            $from = $svc;
            $to = $svc;
            $kind = 'self';
            switch ($s['type']) {
                case 'http_request':
                    $caller = $client;
                    foreach (array_reverse($openCalls, true) as $k => [$callerSvc, $callIdx]) {
                        if ($callerSvc !== $s['service']) {
                            $caller = 'svc:'.$callerSvc;
                            $steps[$callIdx]['to'] = $svc; // the outgoing call lands on this service
                            unset($openCalls[$k]);
                            break;
                        }
                    }
                    $callers[$s['service']][] = $caller;
                    // From another service, the outgoing call already drew the arrow: this is its arrival.
                    [$from, $to, $kind] = [$caller, $svc, $caller === $client ? 'request' : 'arrive'];
                    break;
                case 'http_response':
                    $caller = array_pop($callers[$s['service']]) ?? $client;
                    [$from, $to, $kind] = [$svc, $caller, 'return'];
                    break;
                case 'external_http':
                    $host = preg_match('#^\S+\s+([^/\s]+)#', (string) $s['name'], $m) ? $m[1] : 'external';
                    $to = $add('ext:'.$host, $host, 'external');
                    $openCalls[] = [$s['service'], $i];
                    $kind = 'call';
                    break;
                case 'db_query':
                case 'transaction':
                    $to = $add('db:'.$s['service'], $s['service'].' database', 'database');
                    $kind = 'transaction' === $s['type'] ? 'transaction' : 'query';
                    break;
                case 'cache':
                    $to = $add('cache:'.$s['service'], 'cache', 'cache');
                    $kind = 'cache';
                    break;
                case 'exception':
                    $kind = 'exception';
                    break;
                case 'log':
                    $kind = 'note';
                    break;
            }
            $s['from'] = $from;
            $s['to'] = $s['to'] ?? $to;
            $s['kind'] = $kind;
        }
        unset($s);

        // A call whose target turned out to be a traced service no longer needs a separate external column.
        $used = [];
        foreach ($steps as $s) {
            $used[$s['from']] = true;
            $used[$s['to']] = true;
        }

        return array_values(array_filter($participants, static fn (array $p): bool => isset($used[$p['id']])));
    }

    /**
     * Writes of the same service between the last BEGIN and the ROLLBACK were undone; successful calls to
     * other systems before the rollback were not (a database rollback cannot reach them).
     *
     * @param list<array<string, mixed>> $steps
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private function effects(array $steps, int $rollback): array
    {
        $service = $steps[$rollback]['service'];
        $begin = null;
        for ($i = $rollback - 1; $i >= 0; --$i) {
            if ('transaction' === $steps[$i]['type'] && 'BEGIN' === strtoupper((string) $steps[$i]['name']) && $steps[$i]['service'] === $service) {
                $begin = $i;
                break;
            }
        }
        $undone = [];
        $survived = [];
        for ($i = 0; $i < $rollback; ++$i) {
            $s = $steps[$i];
            if (null !== $begin && $i > $begin && 'db_query' === $s['type'] && $s['service'] === $service && !$s['error']
                && 1 === preg_match('/^(INSERT|UPDATE|DELETE)\b/i', (string) $s['name'])) {
                $undone[] = $i;
            }
            if ('external_http' === $s['type'] && !$s['error'] && (null === $s['status'] || $s['status'] < 400)) {
                $survived[] = $i;
            }
        }

        return [$undone, $survived];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function step(array $item, int $index, float $start, float $duration): array
    {
        $ctx = (array) ($item['context'] ?? []);
        $details = [];
        foreach (['statement' => 'SQL', 'db error' => 'Error', 'status' => 'Status', 'rows' => 'Rows'] as $key => $label) {
            if (isset($ctx[$key]) && '' !== (string) $ctx[$key]) {
                $details[] = ['label' => $label, 'value' => (string) $ctx[$key]];
            }
        }
        if (isset($ctx['cache'])) {
            $details[] = ['label' => 'Cache', 'value' => (string) $ctx['cache'].(isset($ctx['cache age']) ? ', value '.$ctx['cache age'].' old' : '')];
        }
        $message = (string) ($item['message'] ?? '');
        if ('' !== $message && $message !== $item['name'] && !\in_array($message, array_column($details, 'value'), true)) {
            $details[] = ['label' => 'Message', 'value' => $message];
        }
        $t = (float) $item['offset_ms'] - $start;

        return [
            'index' => $index,
            'id' => (string) $item['id'],
            'name' => (string) $item['name'],
            'type' => (string) $item['type'],
            'type_label' => self::TYPES[(string) $item['type']] ?? (string) $item['type'],
            'service' => (string) $item['service'],
            'lane' => (string) ($item['lane'] ?? ''),
            'error' => (bool) ($item['error'] ?? false),
            'status' => isset($ctx['status']) ? (int) $ctx['status'] : null,
            't_ms' => round($t, 1),
            'position' => round(min(100.0, max(0.0, $t / $duration * 100)), 2),
            'duration_ms' => isset($item['duration_ms']) ? (float) $item['duration_ms'] : null,
            'details' => $details,
            'role' => null,
            'effect' => null,
        ];
    }

    /**
     * Timing-only context shortly before the request, most recent first: writes, logs and deploys.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function context(array $items, float $start): array
    {
        // Services that are part of the failing request itself; their earlier traffic is just other requests.
        $own = [];
        foreach ($items as $item) {
            if (true === ($item['proven'] ?? false)) {
                $own[(string) $item['service']] = true;
            }
        }
        $out = [];
        foreach ($items as $item) {
            if (true === ($item['proven'] ?? false)) {
                continue;
            }
            $t = (float) $item['offset_ms'] - $start;
            $isDeploy = 'deploy' === $item['type'];
            $isWrite = 'db_query' === $item['type'] && 1 === preg_match('/^(INSERT|UPDATE|DELETE)\b/i', (string) $item['name']);
            $interesting = $isDeploy || (!isset($own[(string) $item['service']]) && ($isWrite || \in_array($item['type'], ['log', 'queue'], true)));
            if ($t > 0 || !$interesting || (!$isDeploy && $t < -self::CONTEXT_WINDOW_MS) || $t < -30 * 60 * 1000) {
                continue;
            }
            $out[] = [
                'name' => (string) $item['name'],
                'type_label' => self::TYPES[(string) $item['type']] ?? (string) $item['type'],
                'service' => (string) $item['service'],
                't_ms' => round($t, 1),
                'reason' => (string) ($item['reason'] ?? 'Related by timing only'),
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['t_ms'] <=> $a['t_ms']);
        $out = \array_slice($out, 0, self::CONTEXT_LIMIT);

        return array_reverse($out); // chronological
    }
}
