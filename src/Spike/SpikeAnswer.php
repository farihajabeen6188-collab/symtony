<?php

declare(strict_types=1);

namespace App\Spike;

/**
 * Phase 0 spike only: structured-output target.
 */
final class SpikeAnswer
{
    /**
     * @param string $serverTime the exact value returned by the get_server_time tool
     * @param string $summary    one sentence confirming which tool was called
     */
    public function __construct(
        public string $serverTime = '',
        public string $summary = '',
    ) {
    }
}
