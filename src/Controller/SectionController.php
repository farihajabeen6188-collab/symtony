<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sections whose real pages arrive in later phases render an honest empty state here.
 * Each route moves to its own controller when the feature ships.
 */
final class SectionController extends AbstractController
{
    private const SECTIONS = [
        'app_settings' => ['Settings', 'lucide:settings', 'Profile, workspace, AI provider and security.', 'Settings', 'Workspace settings arrive with the features they configure.'],
    ];
    #[Route('/app/settings', name: 'app_settings')]
    public function show(string $_route): Response
    {
        [$title, $icon, $description, $emptyTitle, $emptyText] = self::SECTIONS[$_route];

        return $this->render('app/section.html.twig', [
            'title' => $title,
            'icon' => $icon,
            'description' => $description,
            'empty_title' => $emptyTitle,
            'empty_text' => $emptyText,
        ]);
    }

    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->redirectToRoute(null !== $this->getUser() ? 'app_overview' : 'app_login');
    }
}
