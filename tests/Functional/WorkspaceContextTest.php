<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use App\Domain\Workspace\WorkspaceMembership;
use App\Domain\Workspace\WorkspaceRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * In a long-running process (worker mode, or a test client without reboot) the workspace of one
 * user must never carry over into the next request of another user.
 */
final class WorkspaceContextTest extends WebTestCase
{
    public function testWorkspaceIsResolvedPerRequestAndPerUser(): void
    {
        $client = $this->signedInClient();
        $client->disableReboot();
        $client->request('GET', '/app/mcp');
        self::assertSelectorTextContains('aside, nav, body', 'Acme Commerce');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $other = new User('other@example.test', 'Other Person');
        $other->setPassword('x');
        $ws = new Workspace('Other Corp', 'other-corp');
        $em->persist($other);
        $em->persist($ws);
        $em->persist(new WorkspaceMembership($other, $ws, WorkspaceRole::Owner));
        $em->flush();

        $client->loginUser($other);
        $client->request('GET', '/app/mcp');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Other Corp');
        self::assertSelectorTextNotContains('body', 'Acme Commerce');
    }
}
