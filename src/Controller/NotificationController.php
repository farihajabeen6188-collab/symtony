<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Notification\NotificationQueries;
use App\Infrastructure\Security\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Notifications of the signed-in user: dropdown fragment, full page, open (marks read), mark all read.
 */
final class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationQueries $notifications,
        private readonly WorkspaceContext $context,
    ) {
    }

    #[Route('/app/notifications', name: 'app_notifications', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $unreadOnly = 'unread' === $request->query->getString('show');

        return $this->render('notifications/index.html.twig', [
            'result' => $this->notifications->page($this->context->user(), $unreadOnly, $request->query->getString('before') ?: null),
            'unread_only' => $unreadOnly,
        ]);
    }

    #[Route('/app/notifications/menu', name: 'app_notifications_menu', methods: ['GET'])]
    public function menu(): Response
    {
        return $this->render('notifications/_menu.html.twig', [
            'items' => $this->notifications->page($this->context->user(), false, null, 8)['items'],
        ]);
    }

    #[Route('/app/notifications/{id}/open', name: 'app_notification_open', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsCsrfTokenValid('notifications', tokenKey: '_token')]
    public function open(string $id): Response
    {
        $n = $this->notifications->get($this->context->user(), $id) ?? throw $this->createNotFoundException('Notification not found.');
        $this->notifications->markRead($n);
        $url = $n->getUrl();

        // Only in-app paths, never an external redirect.
        return str_starts_with($url, '/app/') ? $this->redirect($url) : $this->redirectToRoute('app_notifications');
    }

    #[Route('/app/notifications/read-all', name: 'app_notifications_read_all', methods: ['POST'])]
    #[IsCsrfTokenValid('notifications', tokenKey: '_token')]
    public function readAll(Request $request): Response
    {
        $count = $this->notifications->markAllRead($this->context->user());
        $this->addFlash('success', \sprintf('%d notification%s marked as read.', $count, 1 === $count ? '' : 's'));

        return $this->redirectToRoute('app_notifications');
    }
}
