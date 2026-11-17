<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Workspace\User;
use App\Infrastructure\Security\DemoAccountProvisioner;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as BaseWebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Base for functional tests: fresh schema per test class run, plus a signed-in demo user helper.
 */
abstract class WebTestCase extends BaseWebTestCase
{
    use Factories;
    use ResetDatabase;

    protected function signedInClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->loginUser($this->demoUser());

        return $client;
    }

    protected function demoUser(): User
    {
        /** @var DemoAccountProvisioner $provisioner */
        $provisioner = static::getContainer()->get(DemoAccountProvisioner::class);

        return $provisioner->provision()['user'];
    }
}
