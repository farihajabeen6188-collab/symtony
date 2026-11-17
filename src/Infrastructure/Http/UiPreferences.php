<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Shared\TimeRange;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Per-session UI preferences from the top bar (time range, environment filter).
 */
final class UiPreferences
{
    private const RANGE_KEY = 'ui.range';
    private const ENV_KEY = 'ui.environment';

    public function __construct(private readonly RequestStack $requests)
    {
    }

    public function timeRange(): TimeRange
    {
        $request = $this->requests->getCurrentRequest();
        $fromQuery = $request?->query->getString('range');
        if (null !== $fromQuery && null !== ($range = TimeRange::tryFrom($fromQuery))) {
            return $range;
        }

        $stored = $request?->hasSession() ? $request->getSession()->get(self::RANGE_KEY) : null;

        return \is_string($stored) ? (TimeRange::tryFrom($stored) ?? TimeRange::Day) : TimeRange::Day;
    }

    public function setTimeRange(TimeRange $range): void
    {
        $this->requests->getSession()->set(self::RANGE_KEY, $range->value);
    }

    /** Null means "all environments". */
    public function environment(): ?string
    {
        $request = $this->requests->getCurrentRequest();
        $stored = $request?->hasSession() ? $request->getSession()->get(self::ENV_KEY) : null;

        return \is_string($stored) && '' !== $stored ? $stored : null;
    }

    public function setEnvironment(?string $environment): void
    {
        $this->requests->getSession()->set(self::ENV_KEY, $environment ?? '');
    }
}
