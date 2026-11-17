<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'symtony:setup', description: 'Create the local demo workspace and sign-in account (idempotent)')]
final class SetupCommand extends Command
{
    public function __construct(private readonly DemoAccountProvisioner $provisioner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->provisioner->provision();

        $io->success(sprintf(
            '%s workspace "%s" with user %s.',
            $result['created'] ? 'Created' : 'Found existing',
            $result['workspace']->getName(),
            $result['user']->getEmail(),
        ));

        return Command::SUCCESS;
    }
}
