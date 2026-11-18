<?php

declare(strict_types=1);

namespace App\Application\Investigation\Analyzer;

use App\Application\Investigation\EvidencePackage;
use App\Application\Investigation\FindingDraft;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A deterministic analysis over the evidence package. Must only cite refs it actually inspected.
 */
#[AutoconfigureTag('symtony.analyzer')]
interface Analyzer
{
    public function name(): string;

    /** @return list<FindingDraft> */
    public function analyze(EvidencePackage $package): array;
}
