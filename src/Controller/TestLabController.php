<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\InvestigationQueries;
use App\Application\TestLab\TestLab;
use App\Domain\TestLab\RegressionTest;
use App\Domain\TestLab\TestExecution;
use App\Infrastructure\Security\WorkspaceContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Uid\Uuid;

final class TestLabController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly EntityManagerInterface $em,
        private readonly TestLab $lab,
    ) {
    }

    #[Route('/app/test-lab', name: 'app_test_lab', methods: ['GET'])]
    public function index(): Response
    {
        $tests = $this->em->getRepository(RegressionTest::class)->findBy(['workspace' => $this->context->workspace()], ['createdAt' => 'DESC'], 100);

        return $this->render('test_lab/index.html.twig', [
            'tests' => array_map(fn (RegressionTest $t): array => ['test' => $t] + $this->lab->verdict($t), $tests),
            'runner_online' => $this->lab->runnerOnline(),
        ]);
    }

    #[Route('/app/investigations/{id}/tests', name: 'app_test_propose', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsCsrfTokenValid('test-lab', tokenKey: '_token')]
    public function propose(string $id, InvestigationQueries $investigations): Response
    {
        $inv = $investigations->get($this->context->workspace(), $id);
        try {
            $test = $this->lab->propose($inv, $this->context->user());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_investigation_show', ['id' => $id]);
        }
        $this->addFlash($test->passedStaticChecks() ? 'success' : 'warning', $test->passedStaticChecks() ? 'Test proposed. Review the code before running it.' : 'The proposal failed static checks and cannot be run.');

        return $this->redirectToRoute('app_test_show', ['id' => $test->getId()]);
    }

    #[Route('/app/test-lab/{id}', name: 'app_test_show', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function show(string $id, \App\Application\TestLab\FixLab $fixes): Response
    {
        $test = $this->test($id);
        $fix = $fixes->latest($test);

        return $this->render('test_lab/show.html.twig', [
            'test' => $test,
            'verdict' => $this->lab->verdict($test),
            'history' => $this->em->getRepository(TestExecution::class)->findBy(['test' => $test], ['createdAt' => 'DESC'], 40),
            'runner_online' => $this->lab->runnerOnline(),
            'fix' => $fix,
            'fix_verdict' => null !== $fix ? $fixes->verdict($fix) : null,
            'fix_blocked' => $fixes->cannotPropose($test),
            'evidence' => null !== $test->getInvestigation() ? $this->em->getRepository(\App\Domain\Investigation\EvidenceItem::class)->findBy(['investigation' => $test->getInvestigation()]) : [],
        ]);
    }

    #[Route('/app/test-lab/{id}/verdict', name: 'app_test_verdict', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function verdict(string $id): Response
    {
        $test = $this->test($id);
        $verdict = $this->lab->verdict($test);
        if (TestLab::RUNNING === $verdict['status'] && null !== $verdict['buggy']) {
            // Opportunistic collection (the worker also does this every 2 s).
            $this->lab->collect($verdict['buggy']->getBatchId()->toRfc4122());
            $verdict = $this->lab->verdict($test);
        }

        return $this->render('test_lab/_verdict.html.twig', ['test' => $test, 'verdict' => $verdict]);
    }

    #[Route('/app/test-lab/{id}/review', name: 'app_test_review', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsCsrfTokenValid('test-lab', tokenKey: '_token')]
    public function review(string $id, Request $request): Response
    {
        $test = $this->test($id);
        if ('1' !== $request->request->getString('confirm')) {
            $this->addFlash('error', 'Tick the box to confirm you reviewed the code.');
        } else {
            $this->lab->review($test, $this->context->user());
            $this->addFlash('success', 'Marked as reviewed. You can now run it in the isolated runner.');
        }

        return $this->redirectToRoute('app_test_show', ['id' => $id]);
    }

    #[Route('/app/test-lab/{id}/prove', name: 'app_test_prove', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsCsrfTokenValid('test-lab', tokenKey: '_token')]
    public function prove(string $id): Response
    {
        $test = $this->test($id);
        try {
            $this->lab->prove($test, $this->context->user());
            $this->addFlash('success', 'Running against the buggy build and the fixed build in the isolated runner.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_test_show', ['id' => $id]);
    }

    #[Route('/app/test-lab/{id}/download', name: 'app_test_download', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function download(string $id): Response
    {
        $test = $this->test($id);

        return new Response($test->getCode(), 200, [
            'Content-Type' => 'text/x-php; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="%s"', $test->fileName()),
        ]);
    }

    private function test(string $id): RegressionTest
    {
        $test = Uuid::isValid($id) ? $this->em->getRepository(RegressionTest::class)->findOneBy(['id' => Uuid::fromString($id), 'workspace' => $this->context->workspace()]) : null;
        if (!$test instanceof RegressionTest) {
            throw $this->createNotFoundException('Test not found.');
        }

        return $test;
    }
}
