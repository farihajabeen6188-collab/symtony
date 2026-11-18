<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Application\Query\ApplicationQueries;
use App\Domain\Shared\TimeRange;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Mcp\McpGate;
use App\Infrastructure\Mcp\McpPresenter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * Applications monitored in the token's workspace.
 */
final class ApplicationTools
{
    public function __construct(
        private readonly McpGate $gate,
        private readonly ApplicationQueries $applications,
        private readonly McpPresenter $present,
    ) {
    }

    /** @return array{applications: list<array<string, mixed>>} */
    #[McpTool(
        name: 'list_applications',
        title: 'List applications',
        description: 'Lists the applications SYMTONY monitors in this workspace: name, slug, environment, connection status (connected / silent / pending) and when the last event arrived. Start here to find the slug used by other tools.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function listApplications(): array
    {
        return $this->gate->run('tool', 'list_applications', [], fn (Workspace $ws): array => [
            'applications' => array_map($this->present->application(...), $this->applications->list($ws)),
        ]);
    }

    /**
     * @param string $application Application slug (e.g. "demo-shop") or id, from list_applications
     * @param string $range       Time window: 1h, 24h, 7d or 30d
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_application_health',
        title: 'Application health',
        description: 'Events, errors and error rate per service of one application within a time window, computed from stored events. Use it to see which service is failing before drilling into incidents.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function health(
        string $application,
        #[Schema(enum: ['1h', '24h', '7d', '30d'])] string $range = '24h',
    ): array {
        return $this->gate->run('tool', 'get_application_health', ['application' => $application, 'range' => $range], function (Workspace $ws) use ($application, $range): array {
            $window = TimeRange::tryFrom($range) ?? throw new \InvalidArgumentException('range must be one of 1h, 24h, 7d, 30d.');
            $app = $this->applications->find($ws, $application);
            $services = $this->applications->serviceHealth($app, $window->since());

            return [
                'application' => $this->present->application($app),
                'range' => $window->value,
                'since' => $window->since()->format(\DATE_ATOM),
                'totals' => [
                    'events' => array_sum(array_column($services, 'events')),
                    'errors' => array_sum(array_column($services, 'errors')),
                ],
                'services' => $services,
            ];
        });
    }
}
