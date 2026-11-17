<?php

declare(strict_types=1);

namespace App\Application\Incident;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'symtony:incidents:refingerprint', description: 'Recompute fingerprints after an algorithm change and merge incidents that are now the same failure')]
final class RefingerprintCommand extends Command
{
    public function __construct(private readonly IncidentMerger $merger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $log = $this->merger->refingerprint();
        [] === $log ? $io->success('All fingerprints are current; nothing to merge.') : $io->listing($log);

        return Command::SUCCESS;
    }
}
