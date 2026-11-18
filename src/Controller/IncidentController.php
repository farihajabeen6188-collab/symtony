<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Incident\IncidentActions;
use App\Application\Query\ApplicationQueries;
use App\Application\Query\IncidentQueries;
use App\Application\Query\InvestigationQueries;
use App\Application\Timeline\TimelineBuilder;
use App\Domain\Incident\IncidentSeverity;
use App\Domain\Incident\IncidentStatus;
use App\Infrastructure\Http\UiPreferences;
use App\Infrastructure\Security\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

#[Route('/app/incidents')]
final class IncidentController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly IncidentQueries $incidents,
    ) {
    }

    #[Route('', name: 'app_incidents', methods: ['GET'])]
    public function index(Request $request, UiPreferences $prefs, ApplicationQueries $apps): Response
    {
        $ws = $this->context->workspace();
        $filters = [
            'q' => $request->query->getString('q'),
            'status' => $request->query->getString('status', 'open'),
            'severity' => $request->query->getString('severity'),
            'app' => $request->query->getString('app'),
            'sort' => $request->query->getString('sort', 'last_seen'),
            'dir' => $request->query->getString('dir', 'desc'),
            'environment' => $prefs->environment(),
        ];
        $result = $this->incidents->list($ws, $filters, max(1, $request->query->getInt('page', 1)));

        return $this->render('incidents/index.html.twig', [
            'result' => $result,
            'filters' => $filters,
            'status_counts' => $this->incidents->statusCounts($ws),
            'severities' => IncidentSeverity::cases(),
            'statuses' => IncidentStatus::cases(),
            'applications' => $apps->list($ws),
        ]);
    }

    #[Route('/{number<\d+>}', name: 'app_incident_show', methods: ['GET'])]
    public function show(int $number, Request $request, IncidentActions $actions, UiPreferences $prefs, TimelineBuilder $timelines, InvestigationQueries $investigations): Response
    {
        $incident = $this->incidents->byNumber($this->context->workspace(), $number);
        $before = null;

        return $this->render('incidents/show.html.twig', [
            'incident' => $incident,
            'latest_investigation' => $investigations->forIncident($incident)[0] ?? null,
            'timeline' => $timelines->forIncident($incident, $request->query->getString('occurrence') ?: null),
            'latest' => $this->incidents->latestOccurrence($incident),
            'occurrences' => $this->incidents->occurrences($incident, $before),
            'notes' => $this->incidents->notes($incident),
            'related' => $this->incidents->related($incident),
            'services' => $this->incidents->affectedServices($incident),
            'series' => $this->incidents->occurrenceSeries($incident, $prefs->timeRange()),
            'range' => $prefs->timeRange(),
            'transitions' => $actions->availableTransitions($incident),
            'transition_labels' => IncidentActions::TRANSITION_LABELS,
        ]);
    }

    #[Route('/{number<\d+>}/occurrences', name: 'app_incident_occurrences', methods: ['GET'])]
    public function occurrences(int $number, Request $request): Response
    {
        $incident = $this->incidents->byNumber($this->context->workspace(), $number);

        $frame = (string) $request->headers->get('Turbo-Frame', 'occurrences-root');

        return $this->render('incidents/_occurrences.html.twig', [
            'incident' => $incident,
            'occurrences' => $this->incidents->occurrences($incident, $request->query->getString('before') ?: null),
            'frame_id' => preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $frame) ? $frame : 'occurrences-root',
        ]);
    }

    #[Route('/{number<\d+>}/transition', name: 'app_incident_transition', methods: ['POST'])]
    #[IsCsrfTokenValid('incident-action', tokenKey: '_token')]
    public function transition(int $number, Request $request, IncidentActions $actions): Response
    {
        $incident = $this->incidents->byNumber($this->context->workspace(), $number);
        try {
            $actions->transition($incident, $request->request->getString('transition'), $this->context->user(), $request->request->getString('note'));
            $this->addFlash('success', \sprintf('%s is now %s.', $incident->getReference(), strtolower($incident->getStatus()->label())));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_incident_show', ['number' => $number]);
    }

    #[Route('/{number<\d+>}/assign', name: 'app_incident_assign', methods: ['POST'])]
    #[IsCsrfTokenValid('incident-action', tokenKey: '_token')]
    public function assign(int $number, Request $request, IncidentActions $actions): Response
    {
        $incident = $this->incidents->byNumber($this->context->workspace(), $number);
        $toMe = 'me' === $request->request->getString('assignee');
        $actions->assign($incident, $toMe ? $this->context->user() : null);
        $this->addFlash('success', $toMe ? \sprintf('%s is assigned to you.', $incident->getReference()) : \sprintf('%s is unassigned.', $incident->getReference()));

        return $this->redirectToRoute('app_incident_show', ['number' => $number]);
    }

    #[Route('/{number<\d+>}/notes', name: 'app_incident_note', methods: ['POST'])]
    #[IsCsrfTokenValid('incident-action', tokenKey: '_token')]
    public function note(int $number, Request $request, IncidentActions $actions): Response
    {
        $incident = $this->incidents->byNumber($this->context->workspace(), $number);
        try {
            $actions->addNote($incident, $this->context->user(), $request->request->getString('body'));
            $this->addFlash('success', 'Note added.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_incident_show', ['number' => $number, '_fragment' => 'notes']);
    }
}
