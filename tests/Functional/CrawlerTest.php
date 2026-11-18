<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Incident\IncidentGrouper;
use App\Application\Investigation\InvestigationRunner;
use App\Application\Investigation\StartInvestigation;
use App\Application\TestLab\TestLab;
use App\Domain\Workspace\Workspace;
use App\Tests\Support\EventFactory;
use App\Tests\Support\StaleCacheScenario;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Visits every page reachable by links from the landing page and the app as the demo user, on real
 * data (incident, investigation, regression test), and asserts no dead link and no server error.
 */
final class CrawlerTest extends WebTestCase
{
    private const MAX_PAGES = 120;

    public function testEveryInternalLinkWorks(): void
    {
        $client = $this->signedInClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $app = (new EventFactory($em))->application($em->getRepository(Workspace::class)->findOneBy(['slug' => 'demo']), 'demo-shop');
        $incident = (new StaleCacheScenario($em, $app))->create(static::getContainer()->get(IncidentGrouper::class));
        $inv = static::getContainer()->get(StartInvestigation::class)($incident, null, 'system');
        static::getContainer()->get(InvestigationRunner::class)->run($inv);
        $em->refresh($inv);
        static::getContainer()->get(TestLab::class)->propose($inv, null);

        $queue = ['/', '/app', '/styleguide'];
        $seen = [];
        $failures = [];
        $variants = [];
        while ([] !== $queue && \count($seen) < self::MAX_PAGES) {
            $url = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $crawler = $client->request('GET', $url);
            $status = $client->getResponse()->getStatusCode();
            if ($status >= 300 && $status < 400) {
                $location = (string) $client->getResponse()->headers->get('Location');
                $path = (string) parse_url($location, \PHP_URL_PATH);
                if ('' !== $path && !isset($seen[$path])) {
                    $queue[] = $path.(null !== ($q = parse_url($location, \PHP_URL_QUERY)) ? '?'.$q : '');
                }
                continue;
            }
            if ($status >= 400) {
                $failures[] = "$status $url";
                continue;
            }
            $type = (string) $client->getResponse()->headers->get('Content-Type');
            if (!str_contains($type, 'text/html')) {
                continue;
            }
            foreach ($crawler->filter('a[href], turbo-frame[src]') as $a) {
                $href = (string) ($a->getAttribute('href') ?: $a->getAttribute('src'));
                $path = (string) parse_url($href, \PHP_URL_PATH);
                // Many links differ only by query (filters, selected event): sample a few per path.
                $variants[$path] = ($variants[$path] ?? 0) + 1;
                if (str_contains($href, '?') && $variants[$path] > 3) {
                    continue;
                }
                if (!str_starts_with($href, '/') || str_starts_with($href, '//')) {
                    continue;
                }
                $href = (string) strtok($href, '#');
                if ('' === $href || str_contains($href, '/logout') || str_starts_with($href, '/_') || str_contains($href, 'before=')) {
                    continue;
                }
                if (!isset($seen[$href])) {
                    $queue[] = $href;
                }
            }
        }

        self::assertSame([], $failures, 'Dead links or server errors');
        self::assertGreaterThan(25, \count($seen), 'The crawl reached the app: '.implode(', ', array_keys($seen)));
        foreach (['/app/incidents/1', '/app/investigations/'.$inv->getId(), '/app/test-lab', '/app/knowledge', '/app/mcp', '/app/settings', '/app/notifications'] as $must) {
            self::assertArrayHasKey($must, $seen, "$must was reachable by a link");
        }
    }
}
