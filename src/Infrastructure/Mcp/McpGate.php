<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Domain\Mcp\McpToken;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Persistence\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\PromptGetException;
use Mcp\Exception\ResourceReadException;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Every MCP tool, resource and prompt runs through here: resolve the token (HTTP bearer or
 * SYMTONY_MCP_TOKEN for STDIO), check scope, rate-limit per token, run the handler inside the token's
 * workspace, audit the call, and turn failures into safe MCP errors (never a stack trace).
 */
final class McpGate
{
    public function __construct(
        private readonly McpTokenManager $tokens,
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requests,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.mcp')] private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire(env: 'default::SYMTONY_MCP_TOKEN')] private readonly ?string $stdioToken = null,
    ) {
    }

    /**
     * @template T
     *
     * @param 'tool'|'resource'|'prompt'                 $kind
     * @param array<string, mixed>                       $args
     * @param \Closure(Workspace, McpToken): T          $handler
     *
     * @return T
     */
    public function run(string $kind, string $name, array $args, \Closure $handler, string $scope = McpToken::READ): mixed
    {
        $started = hrtime(true);
        $request = $this->requests->getMainRequest();
        $token = $this->resolve($kind);

        if (!$token->allows($scope)) {
            $this->record($token, $kind, $name, $args, $started, false, 'missing scope '.$scope, 0);
            throw $this->error($kind, \sprintf('This token does not have the "%s" scope. Create a token with %s access on the MCP Integrations page.', $scope, $scope));
        }
        $limit = $this->limiter->create($token->getId()->toRfc4122())->consume();
        if (!$limit->isAccepted()) {
            $this->record($token, $kind, $name, $args, $started, false, 'rate limited', 0);
            throw $this->error($kind, \sprintf('Rate limit reached for this token. Retry after %s.', $limit->getRetryAfter()->format(\DATE_ATOM)));
        }

        try {
            $result = $handler($token->getWorkspace(), $token);
        } catch (NotFoundHttpException|\DomainException|\InvalidArgumentException $e) {
            $this->record($token, $kind, $name, $args, $started, false, $e->getMessage(), 0);
            throw $this->error($kind, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('MCP {kind} {name} failed', ['kind' => $kind, 'name' => $name, 'exception' => $e]);
            $this->record($token, $kind, $name, $args, $started, false, 'internal error', 0);
            throw $this->error($kind, \sprintf('SYMTONY could not complete "%s" because of an internal error. It has been logged.', $name));
        }

        $this->record($token, $kind, $name, $args, $started, true, null, \strlen((string) json_encode($result)), null !== $request ? 'http' : 'stdio');

        return $result;
    }

    private function resolve(string $kind): McpToken
    {
        $request = $this->requests->getMainRequest();
        if (null !== $request) {
            $id = $request->attributes->get(McpAuthSubscriber::ATTRIBUTE);
            $token = \is_string($id) ? $this->em->find(McpToken::class, $id) : null;
        } else {
            // STDIO is one long-lived process: start each call from fresh data.
            $this->em->clear();
            $token = null !== $this->stdioToken && '' !== $this->stdioToken ? $this->tokens->authenticate($this->stdioToken) : null;
        }
        if (!$token instanceof McpToken || $token->isRevoked()) {
            throw $this->error($kind, null === $request
                ? 'No valid MCP token. Set SYMTONY_MCP_TOKEN to a token from the MCP Integrations page.'
                : 'Invalid or revoked MCP token.');
        }

        return $token;
    }

    /** @param array<string, mixed> $args */
    private function record(McpToken $token, string $kind, string $name, array $args, int|float $started, bool $ok, ?string $error, int $bytes, ?string $transport = null): void
    {
        $summary = [];
        foreach ($args as $k => $v) {
            $summary[$k] = \is_scalar($v) || null === $v ? mb_substr((string) json_encode($v), 0, 120) : \gettype($v);
        }
        $this->audit->write($token->getWorkspace(), 'mcp', $token->getLabel(), 'mcp.call', $token, array_filter([
            'kind' => $kind,
            'name' => $name,
            'args' => $summary,
            'ok' => $ok,
            'error' => null !== $error ? mb_substr($error, 0, 300) : null,
            'result_bytes' => $bytes,
            'duration_ms' => round((hrtime(true) - $started) / 1e6, 1),
            'transport' => $transport ?? (null !== $this->requests->getMainRequest() ? 'http' : 'stdio'),
        ], static fn ($v): bool => null !== $v));
        $this->em->flush();
    }

    private function error(string $kind, string $message): \RuntimeException
    {
        return match ($kind) {
            'resource' => new ResourceReadException($message),
            'prompt' => new PromptGetException($message),
            default => new ToolCallException($message),
        };
    }
}
