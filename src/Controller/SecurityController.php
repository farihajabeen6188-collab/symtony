<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Security\DemoSignIn;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $auth, DemoSignIn $demoSignIn): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_overview');
        }

        return $this->render('security/login.html.twig', [
            'last_email' => $auth->getLastUsername(),
            'error' => $auth->getLastAuthenticationError(),
            // Local setups always show the demo account; production only when SYMTONY_PUBLIC_DEMO=1.
            'demo' => $demoSignIn->credentials(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Handled by the firewall.');
    }
}
