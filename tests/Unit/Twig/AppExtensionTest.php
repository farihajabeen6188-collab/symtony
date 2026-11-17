<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Application\Query\ApplicationQueries;
use App\Domain\Shared\TimeRange;
use App\Infrastructure\Http\UiPreferences;
use App\Infrastructure\Security\WorkspaceContext;
use App\Twig\AppExtension;
use App\Twig\Navigation;
use PHPUnit\Framework\Attributes\DataProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final class AppExtensionTest extends TestCase
{
    private AppExtension $ext;

    protected function setUp(): void
    {
        $this->ext = new AppExtension(
            new Navigation(),
            new UiPreferences(new RequestStack()),
            new WorkspaceContext($this->createStub(Security::class), $this->createStub(EntityManagerInterface::class)),
            new ApplicationQueries($this->createStub(EntityManagerInterface::class)),
        );
    }

    /** @return iterable<array{int|float|null, string}> */
    public static function durations(): iterable
    {
        yield [null, '—'];
        yield [0.42, '0.42 ms'];
        yield [4.25, '4.3 ms'];
        yield [840, '840 ms'];
        yield [1240, '1.24 s'];
        yield [123000, '2m 03s'];
    }

    #[DataProvider('durations')]
    public function testDuration(int|float|null $ms, string $expected): void
    {
        self::assertSame($expected, $this->ext->duration($ms));
    }

    public function testCompact(): void
    {
        self::assertSame('999', $this->ext->compact(999));
        self::assertSame('1.2k', $this->ext->compact(1200));
        self::assertSame('48k', $this->ext->compact(48210));
        self::assertSame('1.1M', $this->ext->compact(1_100_000));
        self::assertSame('—', $this->ext->compact(null));
    }

    public function testAgo(): void
    {
        $now = new \DateTimeImmutable('2026-11-17 12:00:00');
        self::assertSame('just now', $this->ext->ago($now->modify('-10 seconds'), $now));
        self::assertSame('5m ago', $this->ext->ago($now->modify('-5 minutes'), $now));
        self::assertSame('3h ago', $this->ext->ago($now->modify('-3 hours'), $now));
        self::assertSame('2d ago', $this->ext->ago($now->modify('-2 days'), $now));
        self::assertSame('—', $this->ext->ago(null, $now));
    }

    public function testTimeRangeDefaultsToLastDay(): void
    {
        self::assertSame(TimeRange::Day, $this->ext->timeRange());
        self::assertSame(1800, TimeRange::Day->bucketSeconds());
        self::assertEquals(new \DateTimeImmutable('2026-11-16 12:00:00'), TimeRange::Day->since(new \DateTimeImmutable('2026-11-17 12:00:00')));
    }
}
