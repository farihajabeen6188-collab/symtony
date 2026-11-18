<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Domain\Mcp\McpToken;
use App\Domain\Workspace\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'symtony:mcp:token', description: 'Create an MCP token for a workspace (printed once)')]
final class McpTokenCommand
{
    public function __construct(
        private readonly McpTokenManager $tokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'A name for the token, e.g. "Claude Code on my laptop"')] string $label,
        #[Option(description: 'Workspace slug')] string $workspace = 'demo',
        #[Option(description: 'Also allow start_investigation')] bool $write = false,
    ): int {
        $ws = $this->em->getRepository(Workspace::class)->findOneBy(['slug' => $workspace]);
        if (!$ws instanceof Workspace) {
            $io->error(\sprintf('No workspace with slug "%s".', $workspace));

            return Command::FAILURE;
        }
        $issued = $this->tokens->issue($ws, $label, $write ? [McpToken::READ, McpToken::WRITE] : [McpToken::READ], null);
        $io->success(\sprintf('Token "%s" for workspace "%s" (scopes: %s). It is shown only once:', $label, $ws->getName(), implode(', ', $issued['entity']->getScopes())));
        $io->writeln($issued['token']);

        return Command::SUCCESS;
    }
}
