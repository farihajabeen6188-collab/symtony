<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Application\Query\IncidentQueries;
use App\Application\Query\InvestigationQueries;
use App\Application\Timeline\TimelineBuilder;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Mcp\McpGate;
use App\Infrastructure\Mcp\McpPresenter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * Incidents (grouped failures), their timelines and relations.
 */
final class IncidentTools
{
    public function __construct(
        private readonly McpGate $gate,
        private readonly IncidentQueries $incidents,
        private readonly InvestigationQueries $investigations,
        private readonly TimelineBuilder $timelines,
        private readonly McpPresenter $present,
    ) {
    }

    /**
     * @param string $status      open (default: everything not resolved), new, investigating, identified, resolved or all
     * @param string $severity    Optional: critical, high, medium or low
     * @param string $application Optional application slug
     * @param string $query       Optional text search (title, exception class, service) or a reference like SYM-5
     * @param int    $page        Page number, 25 incidents per page
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_incidents',
        title: 'List incidents',
        description: 'Lists incidents (failures grouped by fingerprint), most recently seen first: reference (SYM-n), title, status, severity, service, occurrence count. Paginated, 25 per page.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function list(
        #[Schema(enum: ['open', 'new', 'investigating', 'identified', 'resolved', 'all'])] string $status = 'open',
        #[Schema(enum: ['', 'critical', 'high', 'medium', 'low'])] string $severity = '',
        string $application = '',
        string $query = '',
        #[Schema(minimum: 1, maximum: 1000)] int $page = 1,
    ): array {
        $args = ['status' => $status, 'severity' => $severity, 'application' => $application, 'query' => $query, 'page' => $page];

        return $this->gate->run('tool', 'list_incidents', $args, function (Workspace $ws) use ($status, $severity, $application, $query, $page): array {
            $result = $this->incidents->list($ws, ['status' => $status, 'severity' => $severity, 'app' => $application, 'q' => $query], max(1, $page));

            return [
                'incidents' => array_map($this->present->incident(...), $result['items']),
                'page' => $result['page'],
                'pages' => $result['pages'],
                'total' => $result['total'],
            ];
        });
    }

    /**
     * @param string $incident Incident reference such as "SYM-5" (or its id)
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_incident',
        title: 'Get incident',
        description: 'One incident in detail: summary, counts, fingerprint, affected services, the latest occurrence (event id and trace id) and the latest investigation id and status. Pass the event id to get_event_details and the trace id to search_events.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function get(string $incident): array
    {
        return $this->gate->run('tool', 'get_incident', ['incident' => $incident], function (Workspace $ws) use ($incident): array {
            $i = $this->present->findIncident($ws, $incident);
            $latest = $this->incidents->latestOccurrence($i);
            $investigations = $this->investigations->forIncident($i);

            return $this->present->incident($i) + [
                'fingerprint' => $i->getFingerprint(),
                'normalized_message' => $this->present->text($i->getNormalizedMessage()),
                'reopen_count' => $i->getReopenCount(),
                'resolved_at' => $i->getResolvedAt()?->format(\DATE_ATOM),
                'affected_services' => $this->incidents->affectedServices($i),
                'latest_occurrence' => null !== $latest ? $this->present->eventSummary($latest) : null,
                'latest_investigation' => [] !== $investigations ? $this->present->investigation($investigations[0]) : null,
            ];
        });
    }

    /**
     * @param string $incident Incident reference such as "SYM-5"
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_related_incidents',
        title: 'Related incidents',
        description: 'Incidents related to this one, each with the reason: the same exception class, or occurring in the same request trace. A relation is a lead, not proof of a shared cause.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function related(string $incident): array
    {
        return $this->gate->run('tool', 'list_related_incidents', ['incident' => $incident], function (Workspace $ws) use ($incident): array {
            $i = $this->present->findIncident($ws, $incident);

            return [
                'incident' => $i->getReference(),
                'related' => array_map(fn (array $r): array => ['reason' => $r['reason']] + $this->present->incident($r['incident']), $this->incidents->related($i)),
            ];
        });
    }

    /**
     * @param string $incident Incident reference such as "SYM-5"
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_incident_timeline',
        title: 'Incident timeline',
        description: 'Reconstructed timeline of the latest occurrence: lanes (service/component), events in time order with offsets and durations, and links. Each item has "proven": true when it shares the trace or request id, false when it is only correlated by timing ("inferred"). Never treat inferred items as causes without other evidence.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function timeline(string $incident): array
    {
        return $this->gate->run('tool', 'get_incident_timeline', ['incident' => $incident], function (Workspace $ws) use ($incident): array {
            $i = $this->present->findIncident($ws, $incident);
            $timeline = $this->timelines->forIncident($i);
            if (null === $timeline) {
                throw new \DomainException(\sprintf('%s has no stored occurrence to build a timeline from.', $i->getReference()));
            }

            return ['incident' => $i->getReference()] + $this->present->timeline($timeline->toArray());
        });
    }
}
