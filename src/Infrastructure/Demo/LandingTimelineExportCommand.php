<?php

declare(strict_types=1);

namespace App\Infrastructure\Demo;

use App\Application\Timeline\TimelineBuilder;
use App\Domain\Incident\Incident;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Mcp\McpPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exports the demo checkout failure's real timeline (from a demo-shop run) to a JSON file that the
 * public landing page renders, labelled "Demo data". Values pass the Redactor like MCP output.
 */
#[AsCommand(name: 'symtony:landing:export-timeline', description: 'Export the demo incident timeline for the landing page')]
final class LandingTimelineExportCommand
{
    public const FILE = '/config/landing/demo-timeline.json';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TimelineBuilder $timelines,
        private readonly McpPresenter $present,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Workspace slug')] string $workspace = 'demo'): int
    {
        $ws = $this->em->getRepository(Workspace::class)->findOneBy(['slug' => $workspace]);
        $incident = null !== $ws ? $this->em->createQueryBuilder()->select('i')->from(Incident::class, 'i')
            ->where('i.workspace = :ws')->andWhere('i.title LIKE :t')->setParameter('ws', $ws)->setParameter('t', '%stock_non_negative%')
            ->orderBy('i.lastSeenAt', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult() : null;
        if (!$incident instanceof Incident) {
            $io->error('No stock_non_negative incident in that workspace. Run the failure scenario from the demo application first.');

            return Command::FAILURE;
        }
        $timeline = $this->timelines->forIncident($incident);
        if (null === $timeline) {
            $io->error('The incident has no stored occurrence.');

            return Command::FAILURE;
        }
        $data = $this->present->timeline($timeline->toArray());
        unset($data['legend']);
        $data['exported_at'] = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $data['source'] = \sprintf('%s from a demo-shop run (release 1.4.0)', $incident->getReference());
        file_put_contents($this->projectDir.self::FILE, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
        $io->success(\sprintf('Exported %d events of %s to %s.', \count($data['items']), $incident->getReference(), self::FILE));

        return Command::SUCCESS;
    }
}
