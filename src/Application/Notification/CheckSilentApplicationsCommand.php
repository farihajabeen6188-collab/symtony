<?php

declare(strict_types=1);

namespace App\Application\Notification;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'symtony:applications:check-silent', description: 'Notify about applications that stopped sending events (also runs every 5 minutes in the worker)')]
final class CheckSilentApplicationsCommand
{
    public function __construct(private readonly CheckSilentApplications $check)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success(\sprintf('%d silent application(s); members were notified once per period of silence.', ($this->check)()));

        return Command::SUCCESS;
    }
}
