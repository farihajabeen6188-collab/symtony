<?php

declare(strict_types=1);

namespace App\Infrastructure\Demo;

use App\Application\Incident\IncidentGrouper;
use App\Application\Incident\PendingGrouping;
use App\Domain\Application\Application;
use App\Domain\Application\Environment;
use App\Domain\Application\Framework;
use App\Domain\Application\IngestionMethod;
use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use App\Domain\Workspace\WorkspaceMembership;
use App\Domain\Workspace\WorkspaceRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Load-testing data in a SEPARATE workspace ("Perf Lab", user perf@symtony.local). Never mixed with the demo.
 * Inserted directly for speed: this is synthetic volume for measuring queries, not product data.
 */
#[AsCommand(name: 'symtony:perf:seed', description: 'Create a separate perf workspace with N synthetic events (default 100k) for performance checks')]
final class PerfSeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly IncidentGrouper $grouper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('events', null, InputOption::VALUE_REQUIRED, 'Number of events', '100000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $total = max(1000, (int) $input->getOption('events'));

        $ws = $this->em->getRepository(Workspace::class)->findOneBy(['slug' => 'perf']);
        if ($ws instanceof Workspace) {
            $io->writeln('Removing the previous perf workspace…');
            $this->db->executeStatement('DELETE FROM workspace WHERE id = ?', [$ws->getId()->toRfc4122()]);
            $this->em->clear();
        }
        $ws = new Workspace('Perf Lab', 'perf');
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'perf@symtony.local']) ?? new User('perf@symtony.local', 'Perf Tester');
        $user->setPassword($this->hasher->hashPassword($user, 'perf-password'));
        $this->em->persist($ws);
        $this->em->persist($user);
        $this->em->persist(new WorkspaceMembership($user, $ws, WorkspaceRole::Owner));
        $apps = [];
        foreach (['storefront', 'billing', 'search', 'catalog'] as $slug) {
            $apps[] = $app = new Application($ws, ucfirst($slug), $slug, Environment::Production, Framework::Symfony, IngestionMethod::HttpJson);
            $this->em->persist($app);
        }
        $this->em->flush();

        mt_srand(99);
        $now = time();
        $io->progressStart($total);
        $types = ['http_request', 'db_query', 'cache', 'external_http', 'service_call', 'log'];
        for ($done = 0; $done < $total; $done += 1000) {
            $rows = [];
            $params = [];
            for ($i = 0; $i < 1000 && $done + $i < $total; ++$i) {
                $app = $apps[mt_rand(0, 3)];
                $t = $now - mt_rand(0, 30 * 86400);
                $fail = mt_rand(1, 100) <= 4;
                $kind = mt_rand(1, 25);
                $rows[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                array_push($params,
                    Uuid::v7()->toRfc4122(), $ws->getId()->toRfc4122(), $app->getId()->toRfc4122(),
                    gmdate('Y-m-d H:i:s', $t).'.'.str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT).'+00', gmdate('Y-m-d H:i:s', $t).'+00',
                    $fail ? 'error' : 'info', $fail ? 'exception' : $types[mt_rand(0, 5)], $app->getSlug(), 'production',
                    $fail ? "Upstream failure kind $kind for request ".mt_rand() : 'GET /items/'.mt_rand(1, 999),
                    $fail ? "Upstream failure kind {n} for request {n}" : 'GET /items/{n}',
                    bin2hex(random_bytes(16)), bin2hex(random_bytes(8)), round(mt_rand(1, 4000) / 10, 1),
                    $fail ? 'App\Upstream\Failure'.$kind : null, $fail ? "Upstream failure kind $kind" : null,
                    '{"perf": true}', 'http', 0,
                );
            }
            $this->db->executeStatement(
                'INSERT INTO event (id, workspace_id, application_id, occurred_at, received_at, severity, type, service, environment, message, normalized_message, trace_id, span_id, duration_ms, exception_class, exception_message, context, source, redactions) VALUES '.implode(',', $rows),
                $params,
            );
            $io->progressAdvance(\count($rows));
        }
        $io->progressFinish();
        $this->db->executeStatement('UPDATE application SET first_event_at = now() - interval \'30 days\', last_event_at = now() WHERE workspace_id = ?', [$ws->getId()->toRfc4122()]);
        $this->db->executeStatement('ANALYZE event');

        $io->writeln('Grouping failures…');
        $grouped = PendingGrouping::run($this->db, $this->grouper);
        $io->success(\sprintf('%d events in workspace "Perf Lab"; %d incidents. Sign in as perf@symtony.local / perf-password.', $total, $grouped['created']));

        return Command::SUCCESS;
    }
}
