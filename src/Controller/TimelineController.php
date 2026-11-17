<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\IncidentQueries;
use App\Application\Timeline\TimelineBuilder;
use App\Infrastructure\Security\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TimelineController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly TimelineBuilder $timelines,
    ) {
    }

    #[Route('/app/traces/{traceId}', name: 'app_trace', requirements: ['traceId' => '[A-Za-z0-9._:\/-]{1,64}'], methods: ['GET'])]
    public function trace(string $traceId, Request $request): Response
    {
        $timeline = $this->timelines->forTrace($this->context->workspace(), $traceId);
        if (null === $timeline) {
            throw $this->createNotFoundException('No events with this trace id in your workspace.');
        }
        if ('json' === $request->getPreferredFormat() || 'json' === $request->query->get('format')) {
            return $this->json($timeline->toArray());
        }

        return $this->render('timeline/trace.html.twig', ['timeline' => $timeline, 'trace_id' => $traceId]);
    }

    #[Route('/app/incidents/{number<\d+>}/timeline.json', name: 'app_incident_timeline', methods: ['GET'])]
    public function incident(int $number, Request $request, IncidentQueries $incidents): JsonResponse
    {
        $incident = $incidents->byNumber($this->context->workspace(), $number);
        $timeline = $this->timelines->forIncident($incident, $request->query->getString('occurrence') ?: null);

        return $this->json($timeline?->toArray() ?? ['items' => []]);
    }
}
