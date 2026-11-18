<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Application\Investigation\StartInvestigation;
use App\Application\Query\InvestigationQueries;
use App\Domain\Investigation\Finding;
use App\Domain\Investigation\Investigation;
use App\Domain\Investigation\InvestigationStep;
use App\Domain\Mcp\McpToken;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Mcp\McpGate;
use App\Infrastructure\Mcp\McpPresenter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Uid\Uuid;

/**
 * Evidence-grounded investigations: read reports, start new ones (write scope).
 */
final class InvestigationTools
{
    public function __construct(
        private readonly McpGate $gate,
        private readonly InvestigationQueries $investigations,
        private readonly StartInvestigation $start,
        private readonly McpPresenter $present,
    ) {
    }

    /**
     * @param string $investigation Investigation id, or an incident reference such as "SYM-5" for its latest investigation
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_investigation_report',
        title: 'Investigation report',
        description: 'The report of an investigation: summary, findings (fact / inference / hypothesis) each with the evidence ids it cites, its source (heuristic analyzer or model) and its grounding status. Only "verified" findings passed the evidence check; "rejected" ones are listed for transparency and must not be relied on. Also returns stage statuses, so a running or failed investigation is visible.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function report(string $investigation): array
    {
        return $this->gate->run('tool', 'get_investigation_report', ['investigation' => $investigation], function (Workspace $ws) use ($investigation): array {
            $inv = $this->resolve($ws, $investigation);
            $findings = $this->investigations->findings($inv);
            $report = $inv->getReport();

            return $this->present->investigation($inv) + [
                'summary' => $inv->getSummary(),
                'error' => $inv->getError(),
                'stages' => array_map(static fn (InvestigationStep $s): array => array_filter([
                    'stage' => $s->getStage()->value,
                    'status' => $s->getStatus(),
                    'summary' => $s->getSummary(),
                    'error' => $s->getError(),
                ], static fn ($v): bool => null !== $v), $this->investigations->steps($inv)),
                'findings' => array_map($this->present->finding(...), $findings),
                'grounding' => $this->investigations->grounding($inv),
                'recommended_fix' => $report['recommended_fix'] ?? null,
                'recommended_fix_source' => $report['fix_source'] ?? null,
                'reproduction' => $report['reproduction'] ?? null,
                'limitations' => $report['limitations'] ?? [],
                'evidence_note' => 'Evidence ids (EV-n) refer to this investigation. Rejected findings: '.\count(array_filter($findings, static fn (Finding $f): bool => !$f->isVerified())).'.',
                'markdown_url' => $this->present->url('app_investigation_markdown', ['id' => $inv->getId()->toRfc4122()]),
            ];
        });
    }

    /**
     * @param string $incident Incident reference such as "SYM-5"
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'start_investigation',
        title: 'Start investigation',
        description: 'Queues a new investigation of an incident and returns its id immediately; the work runs asynchronously. If one is already running for the incident, that one is returned. Poll get_investigation_report with the id until status is "completed" or "failed". Requires a token with the "write" scope.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function start(string $incident): array
    {
        return $this->gate->run('tool', 'start_investigation', ['incident' => $incident], function (Workspace $ws) use ($incident): array {
            $i = $this->present->findIncident($ws, $incident);
            $inv = ($this->start)($i, null, 'mcp');

            return $this->present->investigation($inv) + [
                'next' => 'Call get_investigation_report with this id; stages update as the worker completes them.',
            ];
        }, McpToken::WRITE);
    }

    private function resolve(Workspace $ws, string $ref): Investigation
    {
        if (Uuid::isValid($ref)) {
            return $this->investigations->get($ws, $ref);
        }
        $incident = $this->present->findIncident($ws, $ref);
        $all = $this->investigations->forIncident($incident);
        if ([] === $all) {
            throw new \DomainException(\sprintf('%s has not been investigated yet. Use start_investigation (write scope) or the Investigate button in SYMTONY.', $incident->getReference()));
        }

        return $all[0];
    }
}
