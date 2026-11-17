<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(
        AuthenticationUtils $auth,
        #[Autowire(env: 'SYMTONY_DEMO_EMAIL')] string $demoEmail,
        #[Autowire(env: 'SYMTONY_DEMO_PASSWORD')] string $demoPassword,
        #[Autowire('%kernel.environment%')] string $environment,
    ): Response {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_overview');
        }

        return $this->render('security/login.html.twig', [
            'last_email' => $auth->getLastUsername(),
            'error' => $auth->getLastAuthenticationError(),
            // Show the local demo credentials only outside production.
            'demo' => 'prod' !== $environment ? ['email' => $demoEmail, 'password' => $demoPassword] : null,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Handled by the firewall.');
    }
}
