<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\PaletteSearch;
use App\Infrastructure\Security\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Command palette results (an HTML fragment of palette items), fetched as the user types.
 */
final class PaletteController extends AbstractController
{
    #[Route('/app/palette', name: 'app_palette', methods: ['GET'])]
    public function __invoke(Request $request, PaletteSearch $search, WorkspaceContext $context): Response
    {
        $q = $request->query->getString('q');

        return $this->render('app/_palette_results.html.twig', [
            'q' => trim($q),
            'r' => $search->search($context->workspace(), $q, $request->query->getString('context')),
        ]);
    }
}
