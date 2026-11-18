<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use App\Domain\Workspace\WorkspaceMembership;
use App\Domain\Workspace\WorkspaceRole;
use App\Infrastructure\Ingestion\Redactor;
use Doctrine\ORM\EntityManagerInterface;

final class SettingsTest extends WebTestCase
{
    public function testOwnerUpdatesProfileAndWorkspaceAndSeesTheAuditLog(): void
    {
        $client = $this->signedInClient();
        $client->disableReboot();
        $crawler = $client->request('GET', '/app/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#ai', 'Not configured');
        self::assertSelectorTextContains('#ai', 'Not set');
        self::assertSelectorTextNotContains('main', 'hf_', 'No key material on the page');

        $client->submit($crawler->filter('form[action$="/settings/profile"]')->form(['name' => 'Ada Investigator', 'theme' => 'light']));
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.toast-stack', 'Profile saved.');

        $client->submit($crawler->filter('form[action$="/settings/workspace"]')->form(['name' => 'Acme', 'retention_days' => '14', 'redact_keys' => "customer_note\ntax-id"]));
        $client->followRedirect();
        self::assertSelectorTextContains('.toast-stack', 'Workspace settings saved.');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'demo@symtony.local']);
        self::assertSame('Ada Investigator', $user->getName());
        self::assertSame('light', $user->getTheme()->value);
        $ws = $em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo']);
        self::assertSame(14, $ws->getRetentionDays());
        self::assertSame(['customer_note', 'tax-id'], $ws->getRedactKeys());

        // The saved keys are what ingestion passes to the Redactor.
        $redacted = (new Redactor())->redactArray(['customerNote' => 'call me on 555', 'order' => 1], $ws->getRedactKeys());
        self::assertNotSame('call me on 555', $redacted['data']['customerNote']);
        self::assertSame(1, $redacted['data']['order']);

        $crawler = $client->request('GET', '/app/settings');
        self::assertSelectorTextContains('#audit', 'settings.workspace_updated');
        self::assertSelectorTextContains('#audit', 'settings.profile_updated');

        // Validation errors are shown, nothing is saved.
        $client->submit($crawler->filter('form[action$="/settings/workspace"]')->form(['retention_days' => '0']));
        $client->followRedirect();
        self::assertSelectorTextContains('.toast-stack', 'between 1 and 365');
        $client->submit($client->getCrawler()->filter('form[action$="/settings/workspace"]')->form(['redact_keys' => 'bad key!']));
        $client->followRedirect();
        self::assertSelectorTextContains('.toast-stack', 'not a valid key');
    }

    public function testMembersCannotChangeTheWorkspaceOrReadTheAuditLog(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->demoUser();
        $ws = $em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo']);
        $member = new User('member@example.test', 'Member');
        $member->setPassword('x');
        $em->persist($member);
        $em->persist(new WorkspaceMembership($member, $ws, WorkspaceRole::Member));
        $em->flush();
        $client->loginUser($member);

        $crawler = $client->request('GET', '/app/settings');
        self::assertSelectorTextContains('#audit', 'Only owners can read the audit log.');
        self::assertSelectorExists('#w-ret[disabled]');
        $token = $crawler->filter('form[action$="/settings/profile"] input[name="_token"]')->attr('value');
        $client->request('POST', '/app/settings/workspace', ['_token' => $token, 'name' => 'Hijacked', 'retention_days' => '1', 'redact_keys' => '']);
        $client->followRedirect();
        self::assertSelectorTextContains('.toast-stack', 'Only workspace owners');
        $em->clear();
        self::assertNotSame('Hijacked', $em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo'])->getName());
    }
}
