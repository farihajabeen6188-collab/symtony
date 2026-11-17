<?php

declare(strict_types=1);

namespace App\Application\Incident;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Groups failure events that no worker has processed yet (backlog, or no worker running). Idempotent.
 */
#[AsCommand(name: 'symtony:incidents:group-pending', description: 'Group failure events that are not linked to an incident yet')]
final class GroupPendingCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly IncidentGrouper $grouper,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = PendingGrouping::run($this->db, $this->grouper);
        $io->success(\sprintf('Linked %d events; %d incidents created, %d reopened.', $result['grouped'], $result['created'], $result['reopened']));

        return Command::SUCCESS;
    }
}
