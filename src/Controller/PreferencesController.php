<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Shared\TimeRange;
use App\Domain\Workspace\ThemePreference;
use App\Infrastructure\Http\UiPreferences;
use App\Infrastructure\Security\WorkspaceContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

#[Route('/app/preferences')]
final class PreferencesController extends AbstractController
{
    #[Route('/range', name: 'app_pref_range', methods: ['POST'])]
    #[IsCsrfTokenValid('ui-pref', tokenKey: '_token')]
    public function range(Request $request, UiPreferences $preferences): RedirectResponse
    {
        $range = TimeRange::tryFrom($request->request->getString('range')) ?? TimeRange::Day;
        $preferences->setTimeRange($range);

        return $this->back($request);
    }

    #[Route('/environment', name: 'app_pref_environment', methods: ['POST'])]
    #[IsCsrfTokenValid('ui-pref', tokenKey: '_token')]
    public function environment(Request $request, UiPreferences $preferences): RedirectResponse
    {
        $env = trim($request->request->getString('environment'));
        $preferences->setEnvironment('' === $env ? null : $env);

        return $this->back($request);
    }

    #[Route('/theme', name: 'app_pref_theme', methods: ['POST'])]
    #[IsCsrfTokenValid('ui-pref', tokenKey: '_token')]
    public function theme(Request $request, WorkspaceContext $context, EntityManagerInterface $em): Response
    {
        $theme = ThemePreference::tryFrom($request->request->getString('theme')) ?? ThemePreference::Dark;
        $context->user()->setTheme($theme);
        $em->flush();

        if ('json' === $request->getPreferredFormat()) {
            return $this->json(['theme' => $theme->value]);
        }

        return $this->back($request);
    }

    private function back(Request $request): RedirectResponse
    {
        $target = $request->request->getString('_back');
        // Only allow same-site relative paths.
        if ('' === $target || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
            $target = $this->generateUrl('app_overview');
        }

        return $this->redirect($target);
    }
}
