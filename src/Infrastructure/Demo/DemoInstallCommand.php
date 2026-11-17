<?php

declare(strict_types=1);

namespace App\Infrastructure\Demo;

use App\Application\Incident\IncidentGrouper;
use App\Application\Incident\PendingGrouping;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'symtony:demo:install', description: 'Set up the demo workspace, the Acme Shop application and its token, and replay seeded history')]
final class DemoInstallCommand extends Command
{
    public function __construct(
        private readonly DemoInstaller $installer,
        private readonly DemoHistory $history,
        private readonly IngestionClient $client,
        private readonly DemoStories $stories,
        private readonly IncidentGrouper $grouper,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-history', null, InputOption::VALUE_NONE, 'Skip replaying seeded history');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->installer->install();
        $io->writeln(\sprintf('Workspace <info>%s</info>, application <info>%s</info>.', $result['workspace']->getName(), $result['application']->getName()));
        $io->writeln(null !== $result['env_file']
            ? 'Demo shop token written to <info>demo-shop/.env.local</info>.'
            : '<comment>demo-shop/ not found: set SYMTONY_TOKEN for your shop manually.</comment>');

        if (!$input->getOption('no-history')) {
            $now = new \DateTimeImmutable();
            $appId = $result['application']->getId()->toRfc4122();
            foreach ([
                'Healthy storefront traffic (24 h)' => $this->history->background($now),
                'Resolved pricing incident (21 days ago)' => $this->history->pricingIncident($now),
                'Flapping Redis warnings (3 days)' => $this->history->redisFlapping($now),
                'Mailer timeouts (3 days ago)' => $this->history->mailerTimeouts($now, false),
            ] as $label => $events) {
                $totals = $this->client->send($result['token'], $events);
                $io->writeln(\sprintf('  %-42s %4d accepted, %d already present', $label, $totals['accepted'], $totals['duplicates']));
            }

            // Group now (do not wait for the worker), then give the history its outcomes.
            PendingGrouping::run($this->db, $this->grouper, $appId);
            foreach ($this->stories->resolveHistory($result['application'], $now) as $line) {
                $io->writeln('  '.$line);
            }
            $totals = $this->client->send($result['token'], $this->history->mailerTimeouts($now, true));
            $io->writeln(\sprintf('  %-42s %4d accepted, %d already present', 'Mailer timeouts recur (5 hours ago)', $totals['accepted'], $totals['duplicates']));
            $grouped = PendingGrouping::run($this->db, $this->grouper, $appId);
            $io->writeln(\sprintf('  Grouping: %d incidents reopened by new occurrences.', $grouped['reopened']));
        }

        $io->success('Demo ready. Sign in, open Applications → Acme Shop, and run the failure scenario.');

        return Command::SUCCESS;
    }
}
