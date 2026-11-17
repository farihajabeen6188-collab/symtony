<?php

declare(strict_types=1);

namespace App\Infrastructure\Demo;

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
            foreach ([
                'Healthy storefront traffic (24 h)' => $this->history->background($now),
                'Resolved pricing incident (21 days ago)' => $this->history->pricingIncident($now),
                'Flapping Redis warnings (3 days)' => $this->history->redisFlapping($now),
                'Mailer timeouts (3 days ago)' => $this->history->mailerTimeouts($now, false),
            ] as $label => $events) {
                $totals = $this->client->send($result['token'], $events);
                $io->writeln(\sprintf('  %-42s %4d accepted, %d already present', $label, $totals['accepted'], $totals['duplicates']));
            }
        }

        $io->success('Demo ready. Sign in, open Applications → Acme Shop, and run the failure scenario.');

        return Command::SUCCESS;
    }
}
