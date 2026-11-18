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
        'app_test_lab' => ['Test Lab', 'lucide:flask-conical', 'Regression tests generated from findings, proven red then green.', 'No regression tests', 'Generate a test from an investigation finding to review it here.'],
        'app_knowledge' => ['Knowledge Base', 'lucide:library', 'Resolved incidents and what fixed them.', 'Nothing resolved yet', 'Resolved incidents become searchable history that new investigations can learn from.'],
        'app_mcp' => ['MCP Integrations', 'lucide:plug', 'Let AI clients such as Claude Code query incidents and evidence.', 'MCP server', 'The MCP server is running. Tool access tokens and client setup arrive here soon.'],
        'app_settings' => ['Settings', 'lucide:settings', 'Profile, workspace, AI provider and security.', 'Settings', 'Workspace settings arrive with the features they configure.'],
    ];
    #[Route('/app/test-lab', name: 'app_test_lab')]
    #[Route('/app/knowledge', name: 'app_knowledge')]
    #[Route('/app/mcp', name: 'app_mcp')]
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
