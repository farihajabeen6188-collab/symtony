<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Domain\Application\Application;
use App\Domain\Application\Environment;
use App\Domain\Application\Framework;
use App\Domain\Application\IngestionMethod;
use App\Domain\Event\Event;
use App\Domain\Event\IngestionFailure;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Ingestion\CredentialManager;
use App\Infrastructure\Ingestion\Message\EventsIngested;
use App\Tests\Functional\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class IngestionApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAcceptsASingleEventAndDispatchesGrouping(): void
    {
        [$app, $token] = $this->app();

        $this->post($token, $this->event(['message' => 'Checkout failed', 'severity' => 'error']));

        self::assertResponseStatusCodeSame(202);
        $body = $this->json();
        self::assertSame(1, $body['accepted']);
        self::assertCount(1, $this->em->getRepository(Event::class)->findBy(['application' => $app]));

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(EventsIngested::class, $sent[0]->getMessage());

        $this->em->refresh($app);
        self::assertNotNull($app->getFirstEventAt());
    }

    public function testBatchKeepsValidEventsAndReportsInvalidOnes(): void
    {
        [, $token] = $this->app();

        $this->post($token, ['events' => [
            $this->event(['message' => 'one']),
            ['message' => 'no timestamp'],
            $this->event(['message' => 'three', 'id' => 'client-1']),
            $this->event(['message' => 'three again', 'id' => 'client-1']),
        ]]);

        self::assertResponseStatusCodeSame(202);
        $body = $this->json();
        self::assertSame(2, $body['accepted']);
        self::assertSame(1, $body['duplicates']);
        self::assertSame(1, $body['rejected'][0]['index']);
        self::assertArrayHasKey('timestamp', $body['rejected'][0]['errors']);
        self::assertCount(1, $this->em->getRepository(IngestionFailure::class)->findBy(['reason' => 'invalid_event']));
    }

    public function testRedactsBeforeStoring(): void
    {
        [$app, $token] = $this->app();

        $this->post($token, $this->event([
            'message' => 'login failed for ann@example.com',
            'context' => ['password' => 'hunter2', 'card' => '4111111111111111', 'sku' => 'SKU-1'],
        ]));

        $event = $this->em->getRepository(Event::class)->findOneBy(['application' => $app]);
        self::assertInstanceOf(Event::class, $event);
        self::assertSame('login failed for [email]', $event->getMessage());
        self::assertSame('[redacted]', $event->getContext()['password']);
        self::assertSame('[redacted]', $event->getContext()['card']);
        self::assertSame('SKU-1', $event->getContext()['sku']);
        self::assertSame(3, $event->getRedactions());
    }

    public function testAuthenticationFailures(): void
    {
        [$app, $token] = $this->app();

        $this->client->request('POST', '/api/v1/events', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(401);

        $this->post('sym_prod_'.str_repeat('a', 32), $this->event());
        self::assertResponseStatusCodeSame(401);

        $credential = static::getContainer()->get(CredentialManager::class)->findActiveByToken($token);
        static::getContainer()->get(CredentialManager::class)->revoke($credential);
        $this->post($token, $this->event());
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('revoked', $this->json()['detail']);
        self::assertCount(1, $this->em->getRepository(IngestionFailure::class)->findBy(['application' => $app, 'reason' => 'revoked_token']));
    }

    public function testMalformedAndOversizedBodies(): void
    {
        [, $token] = $this->app();

        $this->client->request('POST', '/api/v1/events', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'], '{"timestamp": ');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));

        $this->client->request('POST', '/api/v1/events', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'], str_repeat('x', 1048577));
        self::assertResponseStatusCodeSame(413);

        $this->post($token, ['events' => []]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRateLimitPerToken(): void
    {
        [, $token] = $this->app();
        // Test env limit: 3 requests per hour.
        for ($i = 0; $i < 3; ++$i) {
            $this->post($token, $this->event());
            self::assertResponseStatusCodeSame(202);
        }
        $this->post($token, $this->event());
        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    /** @return array{0: Application, 1: string} */
    private function app(?Workspace $workspace = null): array
    {
        $workspace ??= new Workspace('Test', 'test-'.bin2hex(random_bytes(3)));
        $this->em->persist($workspace);
        $app = new Application($workspace, 'Shop', 'shop', Environment::Production, Framework::Symfony, IngestionMethod::HttpJson);
        $this->em->persist($app);
        $this->em->flush();
        $issued = static::getContainer()->get(CredentialManager::class)->issue($app);

        return [$app, $issued['token']];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        return $overrides + ['timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'), 'message' => 'hello', 'service' => 'shop'];
    }

    /** @param array<mixed> $body */
    private function post(string $token, array $body): void
    {
        $this->client->request('POST', '/api/v1/events', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
