<?php

declare(strict_types=1);

namespace Symtony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symtony\Tracer;

#[AsCommand(name: 'symtony:release', description: 'Send a deploy marker to SYMTONY (run it from your deploy pipeline)')]
final class ReleaseCommand extends Command
{
    public function __construct(private readonly Tracer $tracer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('release', InputArgument::REQUIRED, 'Release name or version, e.g. 1.4.0')
            ->addOption('commit', null, InputOption::VALUE_REQUIRED, 'Commit SHA')
            ->addOption('author', null, InputOption::VALUE_REQUIRED, 'Who deployed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $release = (string) $input->getArgument('release');
        $ok = $this->tracer->deploy(
            $release,
            \is_string($input->getOption('commit')) ? $input->getOption('commit') : null,
            \is_string($input->getOption('author')) ? $input->getOption('author') : null,
        );
        $output->writeln($ok ? \sprintf('<info>Deploy marker for %s sent to SYMTONY.</info>', $release) : '<error>SYMTONY did not accept the deploy marker. Check endpoint and token.</error>');

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
