<?php

declare(strict_types=1);

namespace App\Application\TestLab;

use App\Application\Investigation\EvidencePackage;
use App\Domain\Investigation\EvidenceItem;
use App\Domain\Investigation\Finding;
use App\Domain\TestLab\RegressionTest;
use App\Infrastructure\AI\AiSettings;
use App\Infrastructure\AI\EvidenceSession;
use App\Infrastructure\AI\InvalidModelOutput;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks the "fixer" agent for a minimal code change that makes a verified regression test pass.
 * It reads the evidence and the allow-listed sources through tools; the answer is only a proposal.
 */
final class LlmFixWriter
{
    public const INSTRUCTION = 'Write the fix now. Answer with the JSON object described in your instructions.';

    public function __construct(
        #[Autowire(service: 'ai.agent.fixer')] private readonly AgentInterface $agent,
        private readonly EvidenceSession $session,
        private readonly SourceContext $source,
        private readonly AiSettings $settings,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->settings->isConfigured();
    }

    public function engine(): string
    {
        return $this->settings->engineLabel();
    }

    /**
     * @param list<Finding>               $findings
     * @param array<string, EvidenceItem> $evidence
     *
     * @return array{summary: string, diff: string, citations: list<string>}
     */
    public function write(RegressionTest $test, array $findings, array $evidence): array
    {
        $package = new EvidencePackage($test->getIncident(), null);
        foreach ($evidence as $item) {
            $package->add($item);
        }
        $verified = array_values(array_filter($findings, static fn (Finding $f): bool => $f->isVerified() && Finding::HYPOTHESIS === $f->getKind()));
        $editable = array_values(array_filter($this->source->allowed(), static fn (string $p): bool => str_starts_with($p, 'src/')));
        $report = $test->getInvestigation()?->getReport() ?? [];

        $brief = \sprintf(
            "Incident %s.\nRoot-cause hypothesis (verified against evidence): %s\nRecommended fix from the investigation: %s\n\n"
            ."This regression test fails on the current code and must pass after your change (do not modify it):\n```php\n%s\n```\n\n"
            .'Files you may change (read each one with source_file before editing; context lines must match exactly): %s. '
            .'You may also read: %s.',
            $test->getIncident()->getReference(),
            null !== ($verified[0] ?? null) ? $verified[0]->getStatement() : 'not available',
            (string) ($report['recommended_fix'] ?? 'not available'),
            $test->getCode(),
            implode(', ', $editable),
            implode(', ', array_diff($this->source->allowed(), $editable)),
        );

        $this->session->open($package);
        try {
            $content = $this->agent->call(new MessageBag(Message::ofUser($brief), Message::ofUser(self::INSTRUCTION)), ['temperature' => 0.1])->getContent();
        } finally {
            $this->session->close();
        }

        $data = \is_string($content) ? json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', $content)), true) : (array) $content;
        if (!\is_array($data) || !\is_string($data['diff'] ?? null) || !\is_string($data['summary'] ?? null)) {
            throw new InvalidModelOutput('The model did not return a fix as JSON with "summary" and "diff".');
        }
        $diff = str_replace("\r\n", "\n", $data['diff']);

        return [
            'summary' => mb_substr(trim($data['summary']), 0, 2000),
            'diff' => str_ends_with($diff, "\n") ? $diff : $diff."\n",
            'citations' => array_values(array_filter(array_map('strval', (array) ($data['citations'] ?? [])), static fn (string $r): bool => isset($evidence[$r]))),
        ];
    }
}
