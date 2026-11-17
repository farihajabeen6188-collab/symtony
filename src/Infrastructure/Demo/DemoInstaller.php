<?php

declare(strict_types=1);

namespace App\Infrastructure\Demo;

use App\Domain\Application\Application;
use App\Domain\Application\Environment;
use App\Domain\Application\Framework;
use App\Domain\Application\IngestionCredential;
use App\Domain\Application\IngestionMethod;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Ingestion\CredentialManager;
use App\Infrastructure\Security\DemoAccountProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prepares the demo: workspace + sign-in, the "Acme Shop" application, a fresh ingestion token written to
 * demo-shop/.env.local (git-ignored) so the shop can report to SYMTONY.
 */
final class DemoInstaller
{
    public const APP_SLUG = 'acme-shop';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DemoAccountProvisioner $accounts,
        private readonly CredentialManager $credentials,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /** @return array{workspace: Workspace, application: Application, token: string, env_file: ?string} */
    public function install(): array
    {
        $workspace = $this->accounts->provision()['workspace'];

        $app = $this->em->getRepository(Application::class)->findOneBy(['workspace' => $workspace, 'slug' => self::APP_SLUG]);
        if (!$app instanceof Application) {
            $app = new Application($workspace, 'Acme Shop', self::APP_SLUG, Environment::Production, Framework::Symfony, IngestionMethod::Demo,
                'Storefront checkout, inventory and payments. Bundled demo app instrumented with symtony/symfony-bundle.');
            $this->em->persist($app);
            $this->em->flush();
        }

        // One active token for the demo: revoke older ones so the shop's .env.local is the only valid copy.
        foreach ($this->em->getRepository(IngestionCredential::class)->findBy(['application' => $app]) as $old) {
            if (!$old->isRevoked()) {
                $old->revoke();
            }
        }
        $this->em->flush();
        $issued = $this->credentials->issue($app, 'demo-shop');

        return [
            'workspace' => $workspace,
            'application' => $app,
            'token' => $issued['token'],
            'env_file' => $this->writeShopEnv($issued['token']),
        ];
    }

    public function demoApplication(Workspace $workspace): ?Application
    {
        $app = $this->em->getRepository(Application::class)->findOneBy(['workspace' => $workspace, 'slug' => self::APP_SLUG]);

        return $app instanceof Application ? $app : null;
    }

    private function writeShopEnv(string $token): ?string
    {
        $dir = $this->projectDir.'/demo-shop';
        if (!is_dir($dir)) {
            return null;
        }
        $file = $dir.'/.env.local';
        $contents = "# Written by `php bin/console symtony:demo:install` in SYMTONY. Git-ignored.\nSYMTONY_TOKEN={$token}\n";

        return false !== file_put_contents($file, $contents) ? $file : null;
    }
}
