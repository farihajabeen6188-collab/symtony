<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Incident\IncidentGrouper;
use App\Application\Investigation\InvestigationRunner;
use App\Application\Investigation\StartInvestigation;
use App\Domain\Investigation\Investigation;
use App\Domain\TestLab\RegressionTest;
use App\Domain\TestLab\TestExecution;
use App\Domain\Workspace\Workspace;
use App\Tests\Support\EventFactory;
use App\Tests\Support\StaleCacheScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;

final class TestLabPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $jobs;

    protected function setUp(): void
    {
        $this->client = $this->signedInClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->jobs = (string) static::getContainer()->getParameter('symtony.jobs_dir');
        (new Filesystem())->remove($this->jobs);
        (new Filesystem())->mkdir($this->jobs);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->jobs);
        parent::tearDown();
    }

    public function testGenerateReviewProveFlow(): void
    {
        $inv = $this->investigation($this->em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo']));

        $crawler = $this->client->request('GET', '/app/investigations/'.$inv->getId());
        $this->client->submit($crawler->filter('form[action$="/tests"]')->form());
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Checkout must not use a stale cached stock level');
        self::assertSelectorTextContains('main', 'Unverified proposal');
        self::assertSelectorTextContains('main', 'Static checks passed');
        self::assertSelectorTextContains('main', 'Not reviewed');
        self::assertSelectorTextContains('main', 'no model');
        self::assertSelectorNotExists('form[action$="/prove"]', 'No run before review');

        $test = $this->em->getRepository(RegressionTest::class)->findOneBy([]);
        self::assertNotNull($test);

        // Review needs the explicit confirmation box.
        $this->client->request('POST', '/app/test-lab/'.$test->getId().'/review', ['_token' => $crawler->filter('form[action$="/review"] input[name="_token"]')->attr('value')]);
        $test = $this->em->find(RegressionTest::class, $test->getId());
        self::assertFalse($test->isReviewed());
        $crawler = $this->client->submit($crawler->filter('form[action$="/review"]')->form(['confirm' => '1']));
        $crawler = $this->client->followRedirect();
        $test = $this->em->find(RegressionTest::class, $test->getId());
        self::assertTrue($test->isReviewed());

        // Runner offline: the button is disabled and a forced POST is refused with the reason.
        self::assertSelectorExists('form[action$="/prove"] button[disabled]');
        $token = $crawler->filter('form[action$="/prove"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/app/test-lab/'.$test->getId().'/prove', ['_token' => $token]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast-stack', 'runner is offline');
        self::assertSame(0, $this->em->getRepository(TestExecution::class)->count([]));

        // Runner online: two runs are queued and the verdict frame polls.
        file_put_contents($this->jobs.'/.heartbeat', (string) time());
        $this->client->request('POST', '/app/test-lab/'.$test->getId().'/prove', ['_token' => $token]);
        self::assertResponseRedirects('/app/test-lab/'.$test->getId());
        self::assertSame(2, $this->em->getRepository(TestExecution::class)->count([]));
        $this->client->request('GET', '/app/test-lab/'.$test->getId().'/verdict');
        self::assertSelectorExists('turbo-frame#test-verdict[data-poll-final-value="false"]');
        self::assertSelectorTextContains('turbo-frame', 'Running');

        $this->client->request('GET', '/app/test-lab/'.$test->getId().'/download');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($test->getClassName().'.php', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame($test->getCode(), $this->client->getResponse()->getContent());

        $this->client->request('GET', '/app/test-lab');
        self::assertSelectorTextContains('main', $test->getClassName());
        self::assertSelectorTextContains('main', 'Running');
    }

    public function testEmptyStateAndRunnerStatus(): void
    {
        $this->client->request('GET', '/app/test-lab');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No regression tests yet');
        self::assertSelectorTextContains('main', 'Runner offline');
    }

    public function testForeignTestsAre404(): void
    {
        $inv = $this->investigation(null);
        $test = new RegressionTest($inv->getIncident(), $inv, 'secret', 'p', [], 's', 'e', '<?php', 'X', [], [], 'template:x', []);
        $this->em->persist($test);
        $this->em->flush();
        $id = $test->getId()->toRfc4122();

        foreach (['', '/verdict', '/download'] as $suffix) {
            $this->client->request('GET', '/app/test-lab/'.$id.$suffix);
            self::assertResponseStatusCodeSame(404, $suffix);
        }
        $this->client->request('POST', '/app/investigations/'.$inv->getId().'/tests');
        self::assertFalse($this->client->getResponse()->isSuccessful(), 'Refused without a CSRF token');
        self::assertSame(1, $this->em->getRepository(RegressionTest::class)->count([]), 'Nothing was generated');
        $this->client->loginUser($this->demoUser());

        $this->client->request('GET', '/app/test-lab');
        self::assertSelectorTextNotContains('main', 'secret');
    }

    private function investigation(?Workspace $workspace): Investigation
    {
        $app = (new EventFactory($this->em))->application($workspace, null === $workspace ? 'foreign' : 'shop');
        $incident = (new StaleCacheScenario($this->em, $app))->create(static::getContainer()->get(IncidentGrouper::class));
        $inv = static::getContainer()->get(StartInvestigation::class)($incident, null, 'system');
        static::getContainer()->get(InvestigationRunner::class)->run($inv);
        $this->em->refresh($inv);

        return $inv;
    }
}
