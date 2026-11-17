<?php

declare(strict_types=1);

namespace App\Twig;

use App\Application\Query\ApplicationQueries;
use App\Domain\Shared\TimeRange;
use App\Infrastructure\Http\UiPreferences;
use App\Infrastructure\Security\WorkspaceContext;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final class AppExtension
{
    public function __construct(
        private readonly Navigation $navigation,
        private readonly UiPreferences $preferences,
        private readonly WorkspaceContext $workspaceContext,
        private readonly ApplicationQueries $applications,
    ) {
    }

    #[AsTwigFunction('workspace_name')]
    public function workspaceName(): string
    {
        return $this->workspaceContext->hasWorkspace() ? $this->workspaceContext->workspace()->getName() : 'No workspace';
    }

    /** @return array<string, list<array{route: string, label: string, icon: string, section: string, keywords: string}>> */
    #[AsTwigFunction('nav_groups')]
    public function navGroups(): array
    {
        return $this->navigation->grouped();
    }

    /** @return list<array{route: string, label: string, icon: string, section: string, keywords: string}> */
    #[AsTwigFunction('nav_items')]
    public function navItems(): array
    {
        return $this->navigation->items();
    }

    /** @return list<string> */
    #[AsTwigFunction('workspace_environments')]
    public function workspaceEnvironments(): array
    {
        return $this->workspaceContext->hasWorkspace() ? $this->applications->environments($this->workspaceContext->workspace()) : [];
    }

    #[AsTwigFunction('current_environment')]
    public function currentEnvironment(): ?string
    {
        return $this->preferences->environment();
    }

    #[AsTwigFunction('time_range')]
    public function timeRange(): TimeRange
    {
        return $this->preferences->timeRange();
    }

    /** @return list<TimeRange> */
    #[AsTwigFunction('time_ranges')]
    public function timeRanges(): array
    {
        return TimeRange::cases();
    }

    /**
     * "3m ago" style relative time; the full timestamp goes in a title attribute.
     */
    #[AsTwigFilter('ago')]
    public function ago(?\DateTimeInterface $date, ?\DateTimeInterface $now = null): string
    {
        if (null === $date) {
            return '—';
        }
        $seconds = ($now ?? new \DateTimeImmutable())->getTimestamp() - $date->getTimestamp();
        if ($seconds < 0) {
            return 'just now';
        }

        return match (true) {
            $seconds < 45 => 'just now',
            $seconds < 3600 => (int) round($seconds / 60).'m ago',
            $seconds < 86400 => (int) round($seconds / 3600).'h ago',
            $seconds < 86400 * 30 => (int) round($seconds / 86400).'d ago',
            default => $date->format('M j, Y'),
        };
    }

    /** Milliseconds as "840 ms" / "1.24 s" / "2m 03s". */
    #[AsTwigFilter('duration')]
    public function duration(int|float|null $ms): string
    {
        if (null === $ms) {
            return '—';
        }

        return match (true) {
            $ms < 1 => number_format($ms, 2).' ms',
            $ms < 1000 => number_format($ms, $ms < 10 ? 1 : 0).' ms',
            $ms < 60000 => number_format($ms / 1000, 2).' s',
            default => \sprintf('%dm %02ds', intdiv((int) $ms, 60000), (int) (($ms % 60000) / 1000)),
        };
    }

    /** Compact counts: 1.2k, 34k, 1.1M. */
    #[AsTwigFilter('compact')]
    public function compact(int|float|null $n): string
    {
        if (null === $n) {
            return '—';
        }

        return match (true) {
            $n >= 1_000_000 => rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.').'M',
            $n >= 10_000 => number_format($n / 1000, 0).'k',
            $n >= 1000 => rtrim(rtrim(number_format($n / 1000, 1), '0'), '.').'k',
            default => (string) (int) $n,
        };
    }
}
