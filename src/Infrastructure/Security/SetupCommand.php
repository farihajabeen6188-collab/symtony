<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'symtony:setup', description: 'Create the local demo workspace and sign-in account, and prepare the runner job directory (idempotent)')]
final class SetupCommand extends Command
{
    public function __construct(
        private readonly DemoAccountProvisioner $provisioner,
        #[Autowire('%symtony.jobs_dir%')] private readonly string $jobsDir,
    ) {
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

        // The shared job volume is created root-owned; the isolated runner runs as an unprivileged user
        // and must be able to write its heartbeat and results there (sticky bit: nobody deletes others' files).
        if (!is_dir($this->jobsDir) && !@mkdir($this->jobsDir, 0o1777, true) && !is_dir($this->jobsDir)) {
            $io->warning(sprintf('Could not create the runner job directory %s. Test Lab runs will not start.', $this->jobsDir));

            return Command::SUCCESS;
        }
        if (!@chmod($this->jobsDir, 0o1777)) {
            $io->warning(sprintf('Could not make %s writable for the runner. Run: docker compose exec app chmod 1777 %s', $this->jobsDir, $this->jobsDir));
        } else {
            $io->writeln(sprintf(' Runner job directory ready: %s', $this->jobsDir));
        }

        return Command::SUCCESS;
    }
}
