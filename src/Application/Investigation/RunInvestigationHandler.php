<?php

declare(strict_types=1);

namespace App\Application\Investigation;

use App\Domain\Investigation\Investigation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunInvestigationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvestigationRunner $runner,
    ) {
    }

    public function __invoke(RunInvestigation $message): void
    {
        $investigation = $this->em->find(Investigation::class, $message->investigationId);
        if ($investigation instanceof Investigation) {
            $this->runner->run($investigation);
        }
    }
}
