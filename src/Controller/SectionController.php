<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Demo\LandingTimelineExportCommand;
use App\Infrastructure\Mcp\McpOverview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public landing page. The hero timeline is real demo data exported from a demo-shop run
 * (symtony:landing:export-timeline); the MCP tool list comes from the live server registry.
 */
final class SectionController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(McpOverview $mcp, #[Autowire('%kernel.project_dir%')] string $projectDir): Response
    {
        $file = $projectDir.LandingTimelineExportCommand::FILE;
        $timeline = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return $this->render('landing/index.html.twig', [
            'timeline' => \is_array($timeline) ? $timeline : null,
            'tools' => $mcp->capabilities()['tools'],
            'mcp_url' => $mcp->httpUrl(),
        ]);
    }
}
