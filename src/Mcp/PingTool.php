<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Attribute\McpTool;

/**
 * Health check for MCP clients: proves the SYMTONY MCP server is reachable.
 */
final class PingTool
{
    /**
     * @return array{status: string, server: string, time: string}
     */
    #[McpTool(name: 'ping', description: 'Health check. Returns "ok" and the server time when the SYMTONY MCP server is reachable.')]
    public function __invoke(): array
    {
        return [
            'status' => 'ok',
            'server' => 'symtony',
            'time' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        ];
    }
}
