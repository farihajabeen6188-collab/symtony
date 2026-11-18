<?php

declare(strict_types=1);

namespace App\Application\Retention;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'symtony:retention:purge', description: 'Delete events older than each workspace\'s retention period (incident and evidence events are kept)')]
final class RetentionPurgeCommand
{
    public function __construct(private readonly RetentionPurge $purge)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Only count what would be deleted')] bool $dryRun = false): int
    {
        $result = ($this->purge)(null, $dryRun);
        $io->table(['Workspace', $dryRun ? 'Would delete' : 'Deleted'], array_map(null, array_keys($result), array_values($result)));
        $io->success($dryRun ? 'Dry run: nothing was deleted.' : 'Retention purge finished.');

        return Command::SUCCESS;
    }
}
