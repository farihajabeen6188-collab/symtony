<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Handler\ConnectApplication;
use App\Application\Query\ApplicationQueries;
use App\Application\Service\IntegrationSnippets;
use App\Domain\Application\Application;
use App\Domain\Application\Environment;
use App\Domain\Application\Framework;
use App\Domain\Application\IngestionMethod;
use App\Infrastructure\Http\UiPreferences;
use App\Infrastructure\Ingestion\CredentialManager;
use App\Infrastructure\Persistence\AuditLogger;
use App\Infrastructure\Security\WorkspaceContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

#[Route('/app/applications')]
final class ApplicationController extends AbstractController
{
    private const NEW_TOKEN_KEY = 'symtony.new_token.';

    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly ApplicationQueries $queries,
    ) {
    }

    #[Route('', name: 'app_applications', methods: ['GET'])]
    public function index(UiPreferences $prefs): Response
    {
        $ws = $this->context->workspace();
        $apps = $this->queries->list($ws);

        return $this->render('applications/index.html.twig', [
            'applications' => $apps,
            'counts' => $this->queries->eventCounts($ws, $prefs->timeRange()->since()),
            'range' => $prefs->timeRange(),
        ]);
    }

    #[Route('/new', name: 'app_applications_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->renderWizard([], []);
    }

    #[Route('', name: 'app_applications_create', methods: ['POST'])]
    #[IsCsrfTokenValid('application-create', tokenKey: '_token')]
    public function create(Request $request, ConnectApplication $connect): Response
    {
        $input = [
            'name' => trim($request->request->getString('name')),
            'description' => trim($request->request->getString('description')),
            'environment' => $request->request->getString('environment'),
            'framework' => $request->request->getString('framework'),
            'method' => $request->request->getString('method'),
        ];
        $errors = [];
        if ('' === $input['name']) {
            $errors['name'] = 'Give the application a name.';
        } elseif (mb_strlen($input['name']) > 80) {
            $errors['name'] = 'Use 80 characters or fewer.';
        }
        if (mb_strlen($input['description']) > 500) {
            $errors['description'] = 'Use 500 characters or fewer.';
        }
        $environment = Environment::tryFrom($input['environment']) ?? ($errors['environment'] = 'Choose an environment.') ;
        $framework = Framework::tryFrom($input['framework']) ?? ($errors['framework'] = 'Choose a framework.');
        $method = IngestionMethod::tryFrom($input['method']) ?? ($errors['method'] = 'Choose how events will be sent.');

        if ([] !== $errors || !$environment instanceof Environment || !$framework instanceof Framework || !$method instanceof IngestionMethod) {
            return $this->renderWizard($input, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $connect($this->context->workspace(), $input['name'], $input['description'], $environment, $framework, $method);
        $request->getSession()->set(self::NEW_TOKEN_KEY.$result['application']->getId(), $result['token']);

        return $this->redirectToRoute('app_applications_connect', ['id' => $result['application']->getId()]);
    }

    #[Route('/{id}/connect', name: 'app_applications_connect', methods: ['GET'])]
    public function connect(string $id, Request $request, IntegrationSnippets $snippets): Response
    {
        $app = $this->queries->get($this->context->workspace(), $id);
        // The plain token is available exactly once, right after creation or rotation.
        $token = $request->getSession()->remove(self::NEW_TOKEN_KEY.$app->getId());
        $token = \is_string($token) ? $token : null;
        $endpoint = $this->endpoint($request);

        return $this->render('applications/connect.html.twig', [
            'application' => $app,
            'token' => $token,
            'endpoint' => $endpoint,
            'snippets' => $snippets->for($app, $endpoint, $token),
            'latest' => $this->queries->latestEvent($app),
        ]);
    }

    #[Route('/{id}/status', name: 'app_applications_status', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        $app = $this->queries->get($this->context->workspace(), $id);
        $latest = $this->queries->latestEvent($app);

        return $this->json([
            'status' => $app->getConnectionStatus()->value,
            'label' => $app->getConnectionStatus()->label(),
            'first_event_at' => $app->getFirstEventAt()?->format(\DATE_ATOM),
            'last_event_at' => $app->getLastEventAt()?->format(\DATE_ATOM),
            'latest' => null === $latest ? null : [
                'id' => $latest->getId()->toRfc4122(),
                'type' => $latest->getType()->label(),
                'severity' => $latest->getSeverity()->value,
                'service' => $latest->getService(),
                'message' => mb_substr($latest->getMessage(), 0, 200),
                'occurred_at' => $latest->getOccurredAt()->format('Y-m-d H:i:s.v').' UTC',
            ],
        ]);
    }

    #[Route('/{id}', name: 'app_applications_show', methods: ['GET'])]
    public function show(string $id, Request $request, IntegrationSnippets $snippets, UiPreferences $prefs): Response
    {
        $ws = $this->context->workspace();
        $app = $this->queries->get($ws, $id);
        $token = $request->getSession()->remove(self::NEW_TOKEN_KEY.$app->getId());
        $endpoint = $this->endpoint($request);

        return $this->render('applications/show.html.twig', [
            'application' => $app,
            'token' => \is_string($token) ? $token : null,
            'credentials' => $this->queries->credentials($app),
            'failures' => $this->queries->recentFailures($app),
            'counts' => $this->queries->eventCounts($ws, $prefs->timeRange()->since())[$app->getId()->toRfc4122()] ?? null,
            'range' => $prefs->timeRange(),
            'latest' => $this->queries->latestEvent($app),
            'endpoint' => $endpoint,
            'snippets' => $snippets->for($app, $endpoint, \is_string($token) ? $token : null),
            'environments' => Environment::cases(),
            'frameworks' => Framework::cases(),
        ]);
    }

    #[Route('/{id}', name: 'app_applications_update', methods: ['POST'])]
    #[IsCsrfTokenValid('application-update', tokenKey: '_token')]
    public function update(string $id, Request $request, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        $app = $this->queries->get($this->context->workspace(), $id);
        $name = trim($request->request->getString('name'));
        $environment = Environment::tryFrom($request->request->getString('environment'));
        $framework = Framework::tryFrom($request->request->getString('framework'));
        if ('' === $name || mb_strlen($name) > 80 || null === $environment || null === $framework) {
            $this->addFlash('error', 'Name, environment and framework are required. Nothing was changed.');

            return $this->redirectToRoute('app_applications_show', ['id' => $id, '_fragment' => 'settings']);
        }
        $app->update($name, mb_substr(trim($request->request->getString('description')), 0, 500), $environment, $framework);
        $audit->userAction($app->getWorkspace(), 'application.updated', $app);
        $em->flush();
        $this->addFlash('success', 'Application saved.');

        return $this->redirectToRoute('app_applications_show', ['id' => $id]);
    }

    #[Route('/{id}/delete', name: 'app_applications_delete', methods: ['POST'])]
    #[IsCsrfTokenValid('application-delete', tokenKey: '_token')]
    public function delete(string $id, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        $app = $this->queries->get($this->context->workspace(), $id);
        $audit->userAction($app->getWorkspace(), 'application.deleted', $app, ['name' => $app->getName()]);
        $em->remove($app);
        $em->flush();
        $this->addFlash('success', \sprintf('Deleted %s and all of its events.', $app->getName()));

        return $this->redirectToRoute('app_applications');
    }

    #[Route('/{id}/credentials', name: 'app_credentials_create', methods: ['POST'])]
    #[IsCsrfTokenValid('credential', tokenKey: '_token')]
    public function createCredential(string $id, Request $request, CredentialManager $credentials): Response
    {
        $app = $this->queries->get($this->context->workspace(), $id);
        $label = mb_substr(trim($request->request->getString('label')) ?: 'Token', 0, 80);
        $issued = $credentials->issue($app, $label);
        $request->getSession()->set(self::NEW_TOKEN_KEY.$app->getId(), $issued['token']);
        $this->addFlash('success', 'Token created. Copy it now: it will not be shown again.');

        return $this->redirectToRoute('app_applications_show', ['id' => $id, '_fragment' => 'credentials']);
    }

    #[Route('/{id}/credentials/{credentialId}/rotate', name: 'app_credentials_rotate', methods: ['POST'])]
    #[IsCsrfTokenValid('credential', tokenKey: '_token')]
    public function rotate(string $id, string $credentialId, Request $request, CredentialManager $credentials): Response
    {
        $app = $this->queries->get($this->context->workspace(), $id);
        $old = $this->queries->credential($app, $credentialId);
        if ($old->isRevoked()) {
            $this->addFlash('error', 'That token is already revoked. Create a new one instead.');

            return $this->redirectToRoute('app_applications_show', ['id' => $id, '_fragment' => 'credentials']);
        }
        $issued = $credentials->rotate($old);
        $request->getSession()->set(self::NEW_TOKEN_KEY.$app->getId(), $issued['token']);
        $this->addFlash('success', \sprintf('Token rotated. %s… no longer works. Copy the new token now.', $old->getPrefix()));

        return $this->redirectToRoute('app_applications_show', ['id' => $id, '_fragment' => 'credentials']);
    }

    #[Route('/{id}/credentials/{credentialId}/revoke', name: 'app_credentials_revoke', methods: ['POST'])]
    #[IsCsrfTokenValid('credential', tokenKey: '_token')]
    public function revoke(string $id, string $credentialId, CredentialManager $credentials): Response
    {
        $app = $this->queries->get($this->context->workspace(), $id);
        $credential = $this->queries->credential($app, $credentialId);
        $credentials->revoke($credential);
        $this->addFlash('success', \sprintf('Token %s… revoked.', $credential->getPrefix()));

        return $this->redirectToRoute('app_applications_show', ['id' => $id, '_fragment' => 'credentials']);
    }

    /**
     * @param array<string, string> $input
     * @param array<string, string> $errors
     */
    private function renderWizard(array $input, array $errors, int $status = 200): Response
    {
        return $this->render('applications/new.html.twig', [
            'input' => $input + ['name' => '', 'description' => '', 'environment' => 'production', 'framework' => 'symfony', 'method' => 'symfony_bundle'],
            'errors' => $errors,
            'environments' => Environment::cases(),
            'frameworks' => Framework::cases(),
            'methods' => IngestionMethod::cases(),
        ], new Response(status: $status));
    }

    private function endpoint(Request $request): string
    {
        return $request->getSchemeAndHttpHost().$this->generateUrl('api_ingest');
    }
}
