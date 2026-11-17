<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Query\ApplicationQueries;
use App\Domain\Application\Application;
use App\Domain\Application\Environment;
use App\Domain\Application\Framework;
use App\Domain\Application\IngestionMethod;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Ingestion\CredentialManager;
use App\Infrastructure\Persistence\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Creates an application and its first ingestion token.
 */
final class ConnectApplication
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApplicationQueries $queries,
        private readonly CredentialManager $credentials,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array{application: Application, token: string}
     */
    public function __invoke(Workspace $workspace, string $name, string $description, Environment $environment, Framework $framework, IngestionMethod $method): array
    {
        $base = strtolower((string) (new AsciiSlugger())->slug($name)) ?: 'app';
        $slug = $base;
        for ($i = 2; !$this->queries->slugAvailable($workspace, $slug); ++$i) {
            $slug = $base.'-'.$i;
        }

        return $this->em->wrapInTransaction(function () use ($workspace, $name, $slug, $description, $environment, $framework, $method): array {
            $app = new Application($workspace, trim($name), $slug, $environment, $framework, $method, trim($description));
            $this->em->persist($app);
            $this->audit->userAction($workspace, 'application.created', $app, ['name' => $app->getName(), 'method' => $method->value]);
            $issued = $this->credentials->issue($app);

            return ['application' => $app, 'token' => $issued['token']];
        });
    }
}
