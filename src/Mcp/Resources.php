<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Application\Investigation\MarkdownReport;
use App\Application\Query\ApplicationQueries;
use App\Application\Query\IncidentQueries;
use App\Application\Query\InvestigationQueries;
use App\Application\Timeline\TimelineBuilder;
use App\Domain\Shared\TimeRange;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Mcp\McpGate;
use App\Infrastructure\Mcp\McpPresenter;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;

/**
 * Read-only resources. Same scoping, redaction and audit as the tools (they run through McpGate).
 */
final class Resources
{
    public function __construct(
        private readonly McpGate $gate,
        private readonly ApplicationQueries $applications,
        private readonly IncidentQueries $incidents,
        private readonly InvestigationQueries $investigations,
        private readonly TimelineBuilder $timelines,
        private readonly MarkdownReport $markdown,
        private readonly McpPresenter $present,
    ) {
    }

    #[McpResource(uri: 'symtony://health', name: 'workspace-health', description: 'Workspace health: applications and their connection status, incidents by status, errors per service in the last 24 hours.', mimeType: 'application/json')]
    public function health(): string
    {
        return $this->gate->run('resource', 'symtony://health', [], function (Workspace $ws): string {
            $since = TimeRange::Day->since();
            $apps = [];
            foreach ($this->applications->list($ws) as $app) {
                $apps[] = $this->present->application($app) + ['services_24h' => $this->applications->serviceHealth($app, $since)];
            }

            return $this->json([
                'workspace' => $ws->getName(),
                'generated_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                'incidents_by_status' => $this->incidents->statusCounts($ws),
                'applications' => $apps,
            ]);
        });
    }

    #[McpResourceTemplate(uriTemplate: 'symtony://applications/{id}', name: 'application', description: 'One application by slug or id, with per-service health for the last 24 hours.', mimeType: 'application/json')]
    public function application(string $id): string
    {
        return $this->gate->run('resource', 'symtony://applications/'.$id, ['id' => $id], function (Workspace $ws) use ($id): string {
            $app = $this->applications->find($ws, $id);

            return $this->json($this->present->application($app) + ['services_24h' => $this->applications->serviceHealth($app, TimeRange::Day->since())]);
        });
    }

    #[McpResourceTemplate(uriTemplate: 'symtony://incidents/{id}', name: 'incident', description: 'One incident by reference (SYM-5) or id.', mimeType: 'application/json')]
    public function incident(string $id): string
    {
        return $this->gate->run('resource', 'symtony://incidents/'.$id, ['id' => $id], function (Workspace $ws) use ($id): string {
            $incident = $this->present->findIncident($ws, $id);

            return $this->json($this->present->incident($incident) + ['affected_services' => $this->incidents->affectedServices($incident)]);
        });
    }

    #[McpResourceTemplate(uriTemplate: 'symtony://incidents/{id}/timeline', name: 'incident-timeline', description: 'Timeline of the incident\'s latest occurrence. proven=false items are correlated by timing only.', mimeType: 'application/json')]
    public function timeline(string $id): string
    {
        return $this->gate->run('resource', 'symtony://incidents/'.$id.'/timeline', ['id' => $id], function (Workspace $ws) use ($id): string {
            $incident = $this->present->findIncident($ws, $id);
            $timeline = $this->timelines->forIncident($incident) ?? throw new \DomainException(\sprintf('%s has no stored occurrence.', $incident->getReference()));

            return $this->json(['incident' => $incident->getReference()] + $this->present->timeline($timeline->toArray()));
        });
    }

    #[McpResourceTemplate(uriTemplate: 'symtony://investigations/{id}/report', name: 'investigation-report', description: 'An investigation report as Markdown, with findings, citations and the evidence list.', mimeType: 'text/markdown')]
    public function report(string $id): string
    {
        return $this->gate->run('resource', 'symtony://investigations/'.$id.'/report', ['id' => $id], function (Workspace $ws) use ($id): string {
            $inv = $this->investigations->get($ws, $id);

            return $this->present->text($this->markdown->render($inv, $this->investigations->steps($inv), $this->investigations->findings($inv), $this->investigations->evidence($inv)));
        });
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
