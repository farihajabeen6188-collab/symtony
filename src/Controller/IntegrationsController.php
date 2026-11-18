<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Mcp\McpToken;
use App\Infrastructure\Mcp\McpOverview;
use App\Infrastructure\Mcp\McpTokenManager;
use App\Infrastructure\Security\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * MCP Integrations: endpoints, live capabilities, token management, client configs, recent calls.
 */
final class IntegrationsController extends AbstractController
{
    private const NEW_TOKEN = '_symtony_new_mcp_token';

    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly McpTokenManager $tokens,
        private readonly McpOverview $overview,
    ) {
    }

    #[Route('/app/mcp', name: 'app_mcp', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $ws = $this->context->workspace();
        // The plain token is shown exactly once, right after it was created.
        $new = $request->getSession()->remove(self::NEW_TOKEN);

        return $this->render('integrations/index.html.twig', [
            'tokens' => $this->tokens->list($ws),
            'new_token' => \is_string($new) ? $new : null,
            'configs' => $this->overview->configs(\is_string($new) ? $new : '<token>'),
            'capabilities' => $this->overview->capabilities(),
            'http_url' => $this->overview->httpUrl(),
            'stdio_command' => McpOverview::STDIO_COMMAND,
            'calls' => $this->overview->recentCalls($ws),
        ]);
    }

    #[Route('/app/mcp/tokens', name: 'app_mcp_token_create', methods: ['POST'])]
    #[IsCsrfTokenValid('mcp', tokenKey: '_token')]
    public function create(Request $request): Response
    {
        $scopes = $request->request->getBoolean('write') ? [McpToken::READ, McpToken::WRITE] : [McpToken::READ];
        try {
            $issued = $this->tokens->issue($this->context->workspace(), $request->request->getString('label'), $scopes, $this->context->user());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_mcp');
        }
        $request->getSession()->set(self::NEW_TOKEN, $issued['token']);
        $this->addFlash('success', \sprintf('Token "%s" created. Copy it now: it is not shown again.', $issued['entity']->getLabel()));

        return $this->redirect($this->generateUrl('app_mcp').'#connect');
    }

    #[Route('/app/mcp/tokens/{id}/revoke', name: 'app_mcp_token_revoke', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsCsrfTokenValid('mcp', tokenKey: '_token')]
    public function revoke(string $id): Response
    {
        $token = $this->tokens->get($this->context->workspace(), $id) ?? throw $this->createNotFoundException('Token not found.');
        $this->tokens->revoke($token, $this->context->user());
        $this->addFlash('success', \sprintf('Token "%s" revoked. Clients using it are refused from now on.', $token->getLabel()));

        return $this->redirectToRoute('app_mcp');
    }
}
