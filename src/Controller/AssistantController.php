<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\IncidentQueries;
use App\Application\Query\InvestigationQueries;
use App\Domain\Incident\Incident;
use App\Infrastructure\AI\InvestigationAssistant;
use App\Infrastructure\Security\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * The assistant panel (a Turbo frame) on incident and investigation pages. Context comes from the URL:
 * the incident; the evidence is its latest completed investigation.
 */
final class AssistantController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly IncidentQueries $incidents,
        private readonly InvestigationAssistant $assistant,
    ) {
    }

    #[Route('/app/incidents/{number<\d+>}/assistant', name: 'app_assistant', methods: ['GET'])]
    public function panel(int $number, InvestigationQueries $investigations): Response
    {
        return $this->renderPanel($this->incidents->byNumber($this->context->workspace(), $number), $investigations);
    }

    #[Route('/app/incidents/{number<\d+>}/assistant', name: 'app_assistant_ask', methods: ['POST'])]
    #[IsCsrfTokenValid('assistant', tokenKey: '_token')]
    public function ask(int $number, Request $request, InvestigationQueries $investigations): Response
    {
        $incident = $this->incidents->byNumber($this->context->workspace(), $number);
        try {
            $this->assistant->ask($incident, $this->context->user(), $request->request->getString('question'));
        } catch (\DomainException $e) {
            return $this->renderPanel($incident, $investigations, $e->getMessage(), 422);
        }

        return $this->redirectToRoute('app_assistant', ['number' => $number], Response::HTTP_SEE_OTHER);
    }

    private function renderPanel(Incident $incident, InvestigationQueries $investigations, ?string $error = null, int $status = 200): Response
    {
        $latest = $investigations->forIncident($incident)[0] ?? null;

        return $this->render('assistant/_panel.html.twig', [
            'incident' => $incident,
            'messages' => $this->assistant->conversation($incident),
            'unavailable' => $this->assistant->unavailableReason($incident),
            'investigation' => $latest,
            'error' => $error,
        ], new Response(status: $status));
    }
}
