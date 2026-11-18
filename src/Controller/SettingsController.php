<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Settings\SettingsService;
use App\Infrastructure\AI\AiSettings;
use App\Infrastructure\Ingestion\Redactor;
use App\Infrastructure\Mcp\McpTokenManager;
use App\Infrastructure\Security\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Settings: profile, workspace (retention, redaction), AI provider status, MCP tokens, audit log.
 */
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly SettingsService $settings,
    ) {
    }

    #[Route('/app/settings', name: 'app_settings', methods: ['GET'])]
    public function index(Request $request, AiSettings $ai, McpTokenManager $tokens): Response
    {
        $membership = $this->context->membership();
        $owner = 'owner' === $membership->getRole()->value;

        return $this->render('settings/index.html.twig', [
            'membership' => $membership,
            'owner' => $owner,
            'ai' => $ai,
            'built_in_keys' => Redactor::builtInKeys(),
            'tokens' => \count(array_filter($tokens->list($membership->getWorkspace()), static fn ($t): bool => !$t->isRevoked())),
            'audit' => $owner ? $this->settings->auditLog($membership->getWorkspace(), $request->query->getString('before') ?: null) : null,
        ]);
    }

    #[Route('/app/settings/profile', name: 'app_settings_profile', methods: ['POST'])]
    #[IsCsrfTokenValid('settings', tokenKey: '_token')]
    public function profile(Request $request): Response
    {
        return $this->save(fn () => $this->settings->updateProfile($this->context->user(), $this->context->workspace(), $request->request->getString('name'), $request->request->getString('theme')), 'Profile saved.', 'profile');
    }

    #[Route('/app/settings/workspace', name: 'app_settings_workspace', methods: ['POST'])]
    #[IsCsrfTokenValid('settings', tokenKey: '_token')]
    public function workspace(Request $request): Response
    {
        return $this->save(fn () => $this->settings->updateWorkspace($this->context->membership(), $request->request->getString('name'), $request->request->getInt('retention_days'), $request->request->getString('redact_keys')), 'Workspace settings saved.', 'workspace');
    }

    private function save(\Closure $change, string $ok, string $anchor): Response
    {
        try {
            $change();
            $this->addFlash('success', $ok);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->generateUrl('app_settings').'#'.$anchor);
    }
}
