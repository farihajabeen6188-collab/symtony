<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\ApplicationQueries;
use App\Application\Query\EventQueries;
use App\Domain\Event\EventType;
use App\Domain\Event\Severity;
use App\Infrastructure\Http\UiPreferences;
use App\Infrastructure\Security\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/events')]
final class EventController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly EventQueries $events,
    ) {
    }

    #[Route('', name: 'app_events', methods: ['GET'])]
    public function index(Request $request, UiPreferences $prefs, ApplicationQueries $apps): Response
    {
        $ws = $this->context->workspace();
        $filters = [
            'q' => $request->query->getString('q'),
            'severity' => $request->query->getString('severity'),
            'type' => $request->query->getString('type'),
            'service' => $request->query->getString('service'),
            'app' => $request->query->getString('app'),
            'trace_id' => $request->query->getString('trace_id'),
            'request_id' => $request->query->getString('request_id'),
            'environment' => $prefs->environment(),
        ];
        $result = $this->events->search($ws, $filters, $prefs->timeRange()->since(), $request->query->getString('before') ?: null);
        $selected = $request->query->getString('event') ?: ($result['items'][0] ?? null)?->getId()->toRfc4122();

        // "Load more" requests come from a lazy turbo-frame named more-<cursor>.
        $frame = (string) $request->headers->get('Turbo-Frame', '');
        $isMore = 1 === preg_match('/^more-[A-Za-z0-9_-]{1,40}$/', $frame);

        return $this->render($isMore ? 'events/_more.html.twig' : 'events/index.html.twig', [
            'frame_id' => $frame,
            'result' => $result,
            'filters' => $filters,
            'selected' => $selected,
            'range' => $prefs->timeRange(),
            'severities' => Severity::cases(),
            'types' => EventType::cases(),
            'services' => $this->events->services($ws),
            'applications' => $apps->list($ws),
        ]);
    }

    #[Route('/{id}', name: 'app_event_panel', methods: ['GET'])]
    public function panel(string $id): Response
    {
        $event = $this->events->get($this->context->workspace(), $id);

        return $this->render('events/_panel.html.twig', [
            'event' => $event,
            'related' => $this->events->related($event, 60),
            'incident_number' => $this->events->incidentNumber($event),
        ]);
    }
}
