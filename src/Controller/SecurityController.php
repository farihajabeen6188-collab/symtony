<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Workspace\User;
use App\Infrastructure\Security\DemoSignIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(Request $request, AuthenticationUtils $auth, DemoSignIn $demoSignIn, Security $security, EntityManagerInterface $em): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_overview');
        }

        // Public demo: open the app directly as the demo account. "?form" (used after signing out) shows the form.
        $demo = $demoSignIn->credentials();
        if (null !== $demo && $demoSignIn->autoSignIn() && !$request->query->has('form')) {
            $user = $em->getRepository(User::class)->findOneBy(['email' => mb_strtolower($demo['email'])]);
            if ($user instanceof User) {
                return $security->login($user, 'form_login', 'main') ?? $this->redirectToRoute('app_overview');
            }
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
