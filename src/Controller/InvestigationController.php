<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Investigation\MarkdownReport;
use App\Application\Investigation\StartInvestigation;
use App\Application\Query\IncidentQueries;
use App\Application\Query\InvestigationQueries;
use App\Application\Timeline\TimelineBuilder;
use App\Infrastructure\AI\AiSettings;
use App\Infrastructure\Security\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

final class InvestigationController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly InvestigationQueries $investigations,
    ) {
    }

    #[Route('/app/incidents/{number<\d+>}/investigate', name: 'app_incident_investigate', methods: ['POST'])]
    #[IsCsrfTokenValid('incident-action', tokenKey: '_token')]
    public function start(int $number, IncidentQueries $incidents, StartInvestigation $start): Response
    {
        $incident = $incidents->byNumber($this->context->workspace(), $number);
        $investigation = $start($incident, $this->context->user());

        return $this->redirectToRoute('app_investigation_show', ['id' => $investigation->getId()]);
    }

    #[Route('/app/investigations', name: 'app_investigations', methods: ['GET'])]
    public function index(AiSettings $ai): Response
    {
        return $this->render('investigations/index.html.twig', [
            'result' => $this->investigations->list($this->context->workspace()),
            'ai_configured' => $ai->isConfigured(),
            'ai_reason' => $ai->whyNotConfigured(),
        ]);
    }

    #[Route('/app/investigations/{id}', name: 'app_investigation_show', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function show(string $id, TimelineBuilder $timelines): Response
    {
        $inv = $this->investigations->get($this->context->workspace(), $id);
        $evidence = $this->investigations->evidence($inv);

        return $this->render('investigations/show.html.twig', [
            'inv' => $inv,
            'steps' => $this->investigations->steps($inv),
            'findings' => $this->investigations->findings($inv),
            'evidence' => $evidence,
            'grounding' => $this->investigations->grounding($inv),
            'history' => $this->investigations->forIncident($inv->getIncident()),

        ]);
    }

    /** Live progress fragment, polled while the investigation runs. */
    #[Route('/app/investigations/{id}/progress', name: 'app_investigation_progress', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function progress(string $id): Response
    {
        $inv = $this->investigations->get($this->context->workspace(), $id);

        return $this->render('investigations/_progress.html.twig', [
            'inv' => $inv,
            'steps' => $this->investigations->steps($inv),
        ]);
    }

    #[Route('/app/investigations/{id}.md', name: 'app_investigation_markdown', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function markdown(string $id, MarkdownReport $report): Response
    {
        $inv = $this->investigations->get($this->context->workspace(), $id);
        $md = $report->render($inv, $this->investigations->steps($inv), $this->investigations->findings($inv), $this->investigations->evidence($inv));

        return new Response($md, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="%s-%s.md"', $inv->getIncident()->getReference(), $inv->getShortId()),
        ]);
    }
}
