<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\TestLab\FixLab;
use App\Domain\TestLab\FixProposal;
use App\Domain\TestLab\RegressionTest;
use App\Infrastructure\Security\WorkspaceContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Uid\Uuid;

/** AI fix proposals on a verified regression test: propose → review → prove in the isolated runner. */
final class FixController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly EntityManagerInterface $em,
        private readonly FixLab $fixes,
    ) {
    }

    #[Route('/app/test-lab/{id}/fix', name: 'app_fix_propose', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsCsrfTokenValid('test-lab', tokenKey: '_token')]
    public function propose(string $id): Response
    {
        $test = $this->find(RegressionTest::class, $id);
        try {
            $fix = $this->fixes->propose($test, $this->context->user());
            $this->addFlash($fix->passedStaticChecks() ? 'success' : 'warning', $fix->passedStaticChecks()
                ? 'Fix proposed. Review the change before running it.'
                : 'The proposed fix failed the static checks and cannot be run.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->generateUrl('app_test_show', ['id' => $id]).'#ai-fix');
    }

    #[Route('/app/fixes/{id}/review', name: 'app_fix_review', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsCsrfTokenValid('test-lab', tokenKey: '_token')]
    public function review(string $id, Request $request): Response
    {
        $fix = $this->find(FixProposal::class, $id);
        if ('1' !== $request->request->getString('confirm')) {
            $this->addFlash('error', 'Tick the box to confirm you reviewed the change.');
        } else {
            $this->fixes->review($fix, $this->context->user());
            $this->addFlash('success', 'Marked as reviewed. You can now prove it in the isolated runner.');
        }

        return $this->redirect($this->generateUrl('app_test_show', ['id' => $fix->getTest()->getId()]).'#ai-fix');
    }

    #[Route('/app/fixes/{id}/prove', name: 'app_fix_prove', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsCsrfTokenValid('test-lab', tokenKey: '_token')]
    public function prove(string $id): Response
    {
        $fix = $this->find(FixProposal::class, $id);
        try {
            $this->fixes->prove($fix, $this->context->user());
            $this->addFlash('success', 'Applying the fix to the buggy build and running the test in the isolated runner.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->generateUrl('app_test_show', ['id' => $fix->getTest()->getId()]).'#ai-fix');
    }

    #[Route('/app/fixes/{id}/verdict', name: 'app_fix_verdict', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function verdict(string $id): Response
    {
        $fix = $this->find(FixProposal::class, $id);

        return $this->render('test_lab/_fix_verdict.html.twig', ['fix' => $fix, 'fix_verdict' => $this->fixes->verdict($fix)]);
    }

    #[Route('/app/fixes/{id}.patch', name: 'app_fix_download', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function download(string $id): Response
    {
        $fix = $this->find(FixProposal::class, $id);

        return new Response($fix->getDiff(), 200, [
            'Content-Type' => 'text/x-diff; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="%s-ai-fix.patch"', strtolower($fix->getTest()->getIncident()->getReference())),
        ]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class, string $id): object
    {
        $entity = Uuid::isValid($id) ? $this->em->getRepository($class)->findOneBy(['id' => Uuid::fromString($id), 'workspace' => $this->context->workspace()]) : null;
        if (!$entity instanceof $class) {
            throw $this->createNotFoundException('Not found.');
        }

        return $entity;
    }
}
