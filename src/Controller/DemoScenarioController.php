<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\ApplicationQueries;
use App\Domain\Application\IngestionMethod;
use App\Infrastructure\Persistence\AuditLogger;
use App\Infrastructure\Security\WorkspaceContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Asks the bundled demo shop to reproduce its checkout incident with real requests.
 * SYMTONY then only sees what the shop actually emits.
 */
final class DemoScenarioController extends AbstractController
{
    #[Route('/app/applications/{id}/scenario', name: 'app_demo_scenario', methods: ['POST'])]
    #[IsCsrfTokenValid('demo-scenario', tokenKey: '_token')]
    public function __invoke(
        string $id,
        WorkspaceContext $context,
        ApplicationQueries $queries,
        HttpClientInterface $httpClient,
        AuditLogger $audit,
        EntityManagerInterface $em,
        #[Autowire(env: 'DEMO_SHOP_URL')] string $shopUrl,
        #[Autowire(env: 'DEMO_SHOP_SECRET')] string $secret,
    ): Response {
        $app = $queries->get($context->workspace(), $id);
        if (IngestionMethod::Demo !== $app->getIngestionMethod()) {
            throw $this->createNotFoundException('Only the demo application can run the failure scenario.');
        }

        try {
            $response = $httpClient->request('POST', rtrim($shopUrl, '/').'/_demo/scenario', [
                'headers' => ['X-Demo-Secret' => $secret],
                'timeout' => 60,
            ]);
            $status = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'The demo shop did not respond ('.$e->getMessage().'). Start it with: docker compose up -d demo-shop');

            return $this->redirectToRoute('app_applications_show', ['id' => $id]);
        }

        if (200 !== $status) {
            $this->addFlash('error', \sprintf('The demo shop refused the scenario (HTTP %d). Check DEMO_SHOP_SECRET in both apps.', $status));

            return $this->redirectToRoute('app_applications_show', ['id' => $id]);
        }

        $checkouts = array_values(array_filter($result['steps'] ?? [], static fn (array $s): bool => 'checkout' === ($s['step'] ?? null)));
        $failed = \count(array_filter($checkouts, static fn (array $s): bool => ($s['status'] ?? 0) >= 500));
        $audit->userAction($context->workspace(), 'demo.scenario_run', $app, ['checkouts' => \count($checkouts), 'failed' => $failed]);
        $em->flush();

        $this->addFlash($failed > 0 ? 'warning' : 'success', \sprintf(
            'Scenario ran: release %s deployed, %d checkouts sent, %d failed with HTTP 500. Events are arriving now.',
            (string) ($result['release'] ?? '?'), \count($checkouts), $failed,
        ));

        return $this->redirectToRoute('app_applications_show', ['id' => $id]);
    }
}
