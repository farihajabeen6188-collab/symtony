<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Knowledge\KnowledgeQueries;
use App\Infrastructure\Security\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Knowledge Base: resolved incidents and what fixed them, searchable. Investigations use the same retrieval.
 */
final class KnowledgeController extends AbstractController
{
    #[Route('/app/knowledge', name: 'app_knowledge', methods: ['GET'])]
    public function index(Request $request, KnowledgeQueries $knowledge, WorkspaceContext $context): Response
    {
        $ws = $context->workspace();

        return $this->render('knowledge/index.html.twig', [
            'result' => $knowledge->browse($ws, $request->query->getString('q')),
            'resolved' => $knowledge->resolvedCount($ws),
        ]);
    }
}
