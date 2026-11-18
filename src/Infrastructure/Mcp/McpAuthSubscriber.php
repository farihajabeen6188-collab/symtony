<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The Streamable HTTP endpoint (/mcp) requires "Authorization: Bearer smcp_…". The resolved token id
 * is put on the request; McpGate reads it for every tool call. Unauthenticated requests never reach the server.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 30)]
final class McpAuthSubscriber
{
    public const ATTRIBUTE = '_symtony_mcp_token';

    public function __construct(private readonly McpTokenManager $tokens)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/mcp')) {
            return;
        }
        $header = (string) $request->headers->get('Authorization', '');
        $token = 1 === preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $this->tokens->authenticate($m[1]) : null;
        if (null === $token) {
            $event->setResponse(new JsonResponse(
                ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => '' === $header
                    ? 'Missing bearer token. Create one on the MCP Integrations page and send "Authorization: Bearer <token>".'
                    : 'Invalid or revoked MCP token.']],
                401,
                ['WWW-Authenticate' => 'Bearer realm="symtony-mcp"'],
            ));

            return;
        }
        $request->attributes->set(self::ATTRIBUTE, $token->getId()->toRfc4122());
    }
}
