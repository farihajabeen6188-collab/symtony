<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Domain\Application\Application;
use App\Domain\Application\Environment;
use App\Domain\Application\Framework;
use App\Domain\Application\IngestionCredential;
use App\Domain\Application\IngestionMethod;
use App\Domain\Audit\AuditLog;
use App\Domain\Workspace\Workspace;
use App\Tests\Functional\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ApplicationFlowTest extends WebTestCase
{
    public function testWizardCreatesApplicationAndShowsTokenExactlyOnce(): void
    {
        $client = $this->signedInClient();
        $crawler = $client->request('GET', '/app/applications/new');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Create application and token')->form([
            'name' => 'Shop API',
            'environment' => 'staging',
            'framework' => 'symfony',
            'method' => 'symfony_bundle',
        ]));
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();

        $token = trim($crawler->filter('[data-token]')->text());
        self::assertMatchesRegularExpression('/^sym_stag_[0-9A-Za-z]{32}$/', $token);
        // Instructions are generated for this app and contain the token + bundle config.
        self::assertStringContainsString("service: 'shop-api'", $crawler->text());
        self::assertStringContainsString('SYMTONY_TOKEN='.$token, $crawler->text());

        // Reload: the token is gone for good.
        $client->request('GET', $client->getRequest()->getUri());
        self::assertSelectorNotExists('[data-token]');
        self::assertStringNotContainsString($token, (string) $client->getResponse()->getContent());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $credential = $em->getRepository(IngestionCredential::class)->findOneBy([]);
        self::assertSame(hash('sha256', $token), (new \ReflectionProperty(IngestionCredential::class, 'tokenHash'))->getValue($credential));
        self::assertNotNull($em->getRepository(AuditLog::class)->findOneBy(['action' => 'application.created']));
    }

    public function testWizardValidationRerendersWithErrors(): void
    {
        $client = $this->signedInClient();
        $crawler = $client->request('GET', '/app/applications/new');
        $client->submit($crawler->selectButton('Create application and token')->form(['name' => '']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#name-error', 'Give the application a name.');
    }

    public function testEveryIngestionMethodRendersInstructions(): void
    {
        $client = $this->signedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $ws = $em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo']);

        $ids = [];
        foreach (IngestionMethod::cases() as $method) {
            $app = new Application($ws, $method->label(), 'm-'.$method->value, Environment::Production, Framework::Symfony, $method);
            $em->persist($app);
            $ids[$method->value] = (string) $app->getId();
        }
        $em->flush();

        foreach ($ids as $method => $id) {
            $client->request('GET', '/app/applications/'.$id);
            self::assertResponseIsSuccessful($method);
            self::assertSelectorExists('[data-controller="code"]');
        }
    }

    public function testRotateAndRevoke(): void
    {
        $client = $this->signedInClient();
        $crawler = $client->request('GET', '/app/applications/new');
        $client->submit($crawler->selectButton('Create application and token')->form(['name' => 'Rotator']));
        $client->followRedirect();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $app = $em->getRepository(Application::class)->findOneBy(['name' => 'Rotator']);

        $crawler = $client->request('GET', '/app/applications/'.$app->getId());
        $client->submit($crawler->filter('form[action$="/rotate"]')->form());
        $crawler = $client->followRedirect();
        self::assertSelectorExists('[data-token]');

        $credentials = $em->getRepository(IngestionCredential::class)->findBy(['application' => $app], ['createdAt' => 'ASC']);
        self::assertCount(2, $credentials);
        self::assertTrue($credentials[0]->isRevoked());
        self::assertFalse($credentials[1]->isRevoked());

        $client->submit($crawler->filter('form[action$="/revoke"]')->form());
        $client->followRedirect();
        $em->clear();
        self::assertTrue($em->getRepository(IngestionCredential::class)->find($credentials[1]->getId())->isRevoked());
    }

    public function testOtherWorkspacesCannotSeeTheApplication(): void
    {
        $client = $this->signedInClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $other = new Workspace('Other', 'other');
        $foreign = new Application($other, 'Secret app', 'secret', Environment::Production, Framework::Symfony, IngestionMethod::HttpJson);
        $em->persist($other);
        $em->persist($foreign);
        $em->flush();

        $client->request('GET', '/app/applications/'.$foreign->getId());
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/app/applications/'.$foreign->getId().'/status');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/app/applications');
        self::assertStringNotContainsString('Secret app', (string) $client->getResponse()->getContent());
    }

    public function testStatusEndpointReportsFirstEvent(): void
    {
        $client = $this->signedInClient();
        $crawler = $client->request('GET', '/app/applications/new');
        $client->submit($crawler->selectButton('Create application and token')->form(['name' => 'Poller', 'method' => 'http_json']));
        $crawler = $client->followRedirect();
        $token = trim($crawler->filter('[data-token]')->text());
        $statusUrl = $crawler->filter('[data-connect-status-url-value]')->attr('data-connect-status-url-value');

        $client->request('GET', $statusUrl);
        self::assertSame('pending', json_decode((string) $client->getResponse()->getContent(), true)['status']);

        $client->request('POST', '/api/v1/events', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'message' => 'SYMTONY connection test']));
        self::assertResponseStatusCodeSame(202);

        $client->request('GET', $statusUrl);
        $status = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('connected', $status['status']);
        self::assertSame('SYMTONY connection test', $status['latest']['message']);
    }
}
