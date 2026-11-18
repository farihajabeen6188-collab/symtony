<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Domain\Workspace\Workspace;
use App\Infrastructure\Mcp\McpGate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * Health check for MCP clients: proves the server is reachable and the token is valid.
 */
final class PingTool
{
    public function __construct(private readonly McpGate $gate)
    {
    }

    /**
     * @return array{status: string, server: string, workspace: string, scopes: list<string>, time: string}
     */
    #[McpTool(
        name: 'ping',
        description: 'Health check. Returns "ok", the workspace this token can read, its scopes and the server time.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function __invoke(): array
    {
        return $this->gate->run('tool', 'ping', [], static fn (Workspace $ws, $token): array => [
            'status' => 'ok',
            'server' => 'symtony',
            'workspace' => $ws->getName(),
            'scopes' => $token->getScopes(),
            'time' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        ]);
    }
}
