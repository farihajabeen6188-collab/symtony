<?php

declare(strict_types=1);

namespace App\Spike;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Phase 0 spike only: proves the configured model can call a tool. Removed once verified.
 */
#[AsTool(name: 'get_server_time', description: 'Returns the current server time in UTC as an ISO-8601 string.')]
final class ClockTool
{
    public function __invoke(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }
}
