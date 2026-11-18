<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Domain\Audit\AuditLog;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Server;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Data for the MCP Integrations page: the server's live registry (not a hardcoded list),
 * client configurations, and recent calls from the audit log.
 */
final class McpOverview
{
    public const STDIO_COMMAND = 'docker compose exec -T -e SYMTONY_MCP_TOKEN app php bin/console mcp:server';

    public function __construct( // @phpstan-ignore constructor.unusedParameter (the server is injected for its build side effect)
        // Injected only so the server is built: building runs discovery and fills the registry.
        #[Autowire(service: 'mcp.server.default')] Server $server,
        #[Autowire(service: 'mcp.server.default.registry')] private readonly RegistryInterface $registry,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array{tools: list<array{name: string, title: ?string, description: string, write: bool}>, resources: list<array{uri: string, description: string, mime: ?string}>, prompts: list<array{name: string, description: string, arguments: list<string>}>} */
    public function capabilities(): array
    {
        // Building the server (injected above) runs discovery and fills the registry.
        $tools = [];
        foreach ($this->registry->getTools() as $tool) {
            $tools[] = ['name' => $tool->name, 'title' => $tool->title ?? null, 'description' => (string) $tool->description, 'write' => false === ($tool->annotations->readOnlyHint ?? true)];
        }
        usort($tools, static fn (array $a, array $b): int => [$a['write'], $a['name']] <=> [$b['write'], $b['name']]);
        $resources = [];
        foreach ($this->registry->getResources() as $r) {
            $resources[] = ['uri' => $r->uri, 'description' => (string) $r->description, 'mime' => $r->mimeType];
        }
        foreach ($this->registry->getResourceTemplates() as $r) {
            $resources[] = ['uri' => $r->uriTemplate, 'description' => (string) $r->description, 'mime' => $r->mimeType];
        }
        $prompts = [];
        foreach ($this->registry->getPrompts() as $p) {
            $prompts[] = ['name' => $p->name, 'description' => (string) $p->description, 'arguments' => array_map(static fn ($a): string => $a->name, $p->arguments ?? [])];
        }

        return ['tools' => $tools, 'resources' => $resources, 'prompts' => $prompts];
    }

    public function httpUrl(): string
    {
        return $this->urls->generate('_mcp_endpoint_default', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** @return array<string, array{label: string, lang: string, code: string}> */
    public function configs(string $token): array
    {
        $url = $this->httpUrl();

        return [
            'claude-http' => ['label' => 'Claude Code (HTTP)', 'lang' => 'bash', 'code' => \sprintf('claude mcp add --transport http symtony %s --header "Authorization: Bearer %s"', $url, $token)],
            'claude-stdio' => ['label' => 'Claude Code (STDIO)', 'lang' => 'bash', 'code' => \sprintf("cd path/to/symtony\nclaude mcp add --env SYMTONY_MCP_TOKEN=%s --transport stdio symtony -- %s", $token, self::STDIO_COMMAND)],
            'desktop' => ['label' => 'Claude Desktop (JSON)', 'lang' => 'json', 'code' => (string) json_encode(['mcpServers' => ['symtony' => [
                'command' => 'docker',
                'args' => ['compose', '-f', 'path/to/symtony/compose.yaml', 'exec', '-T', '-e', 'SYMTONY_MCP_TOKEN', 'app', 'php', 'bin/console', 'mcp:server'],
                'env' => ['SYMTONY_MCP_TOKEN' => $token],
            ]]], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)],
            'curl' => ['label' => 'Any client (raw HTTP)', 'lang' => 'bash', 'code' => \sprintf("curl -i %s \\\n  -H 'Authorization: Bearer %s' \\\n  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \\\n  -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"initialize\",\"params\":{\"protocolVersion\":\"2025-06-18\",\"capabilities\":{},\"clientInfo\":{\"name\":\"curl\",\"version\":\"1\"}}}'", $url, $token)],
        ];
    }

    /** @return list<AuditLog> */
    public function recentCalls(Workspace $workspace, int $limit = 25): array
    {
        /** @var list<AuditLog> */
        return $this->em->createQueryBuilder()
            ->select('l')->from(AuditLog::class, 'l')
            ->where('l.workspace = :ws')->andWhere("l.action = 'mcp.call'")
            ->setParameter('ws', $workspace)
            ->orderBy('l.createdAt', 'DESC')->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
