<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\OverviewQueries;
use App\Infrastructure\Http\UiPreferences;
use App\Infrastructure\Security\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OverviewController extends AbstractController
{
    #[Route('/app', name: 'app_overview', methods: ['GET'])]
    public function __invoke(WorkspaceContext $context, OverviewQueries $queries, UiPreferences $prefs): Response
    {
        $ws = $context->workspace();
        $range = $prefs->timeRange();
        $env = $prefs->environment();
        $metrics = $queries->metrics($ws, $range, $env);

        return $this->render('overview/index.html.twig', [
            'metrics' => $metrics,
            'range' => $range,
            'series' => 0 === $metrics['applications'] ? [] : $queries->occurrenceSeries($ws, $range, $env),
            'services' => 0 === $metrics['applications'] ? [] : $queries->serviceHealth($ws, $range, $env),
            'incidents' => 0 === $metrics['applications'] ? [] : $queries->recentIncidents($ws, $env),
            'activity' => $queries->activity($ws),
        ]);
    }
}
