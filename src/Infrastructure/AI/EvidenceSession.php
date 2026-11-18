<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\Investigation\EvidencePackage;
use App\Application\Knowledge\SimilarIncident;

/**
 * Holds the evidence package of the investigation currently talking to the model, so tools can only
 * ever read that investigation's (already redacted) evidence — never the database at large.
 */
final class EvidenceSession
{
    private ?EvidencePackage $package = null;
    /** @var list<SimilarIncident> */
    private array $history = [];
    /** @var list<string> */
    private array $toolLog = [];

    /** @param list<SimilarIncident> $history */
    public function open(EvidencePackage $package, array $history = []): void
    {
        $this->package = $package;
        $this->history = $history;
        $this->toolLog = [];
    }

    public function close(): void
    {
        $this->package = null;
        $this->history = [];
    }

    public function package(): EvidencePackage
    {
        return $this->package ?? throw new \LogicException('No investigation is in progress.');
    }

    /** @return list<SimilarIncident> */
    public function history(): array
    {
        return $this->history;
    }

    public function log(string $call): void
    {
        $this->toolLog[] = $call;
    }

    /** @return list<string> */
    public function toolLog(): array
    {
        return $this->toolLog;
    }
}
