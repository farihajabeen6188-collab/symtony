<?php

declare(strict_types=1);

namespace App\Application\TestLab;

use App\Application\Investigation\EvidencePackage;
use App\Domain\Investigation\EvidenceItem;
use App\Domain\Investigation\Finding;
use App\Domain\Investigation\Investigation;
use App\Infrastructure\AI\AiSettings;
use App\Infrastructure\AI\EvidenceSession;
use App\Infrastructure\AI\InvalidModelOutput;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks the "test_writer" agent for a PHPUnit test. It may read the investigation's evidence and the
 * allow-listed source files through tools; the result is a proposal that still needs static checks and review.
 */
final class LlmTestWriter
{
    public const INSTRUCTION = 'Write the regression test now. Answer with the JSON object described in your instructions.';

    public function __construct(
        #[Autowire(service: 'ai.agent.test_writer')] private readonly AgentInterface $agent,
        private readonly EvidenceSession $session,
        private readonly SourceContext $source,
        private readonly AiSettings $settings,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->settings->isConfigured();
    }

    /**
     * @param list<Finding>               $findings
     * @param array<string, EvidenceItem> $evidence
     */
    public function write(Investigation $inv, array $findings, array $evidence): ?TestProposal
    {
        $verified = array_values(array_filter($findings, static fn (Finding $f): bool => $f->isVerified() && Finding::HYPOTHESIS === $f->getKind()));
        if ([] === $verified) {
            return null;
        }
        $package = new EvidencePackage($inv->getIncident(), null);
        foreach ($evidence as $item) {
            $package->add($item);
        }
        $class = \sprintf('Sym%dRegressionTest', $inv->getIncident()->getNumber());
        $brief = \sprintf(
            "Incident %s. Root-cause hypothesis (verified against evidence): %s\nCited evidence: %s\nReproduction strategy: %s\n\n"
            ."Write ONE PHPUnit test class named %s in namespace %s extending %s. Allowed source files (read them with source_file): %s.",
            $inv->getIncident()->getReference(), $verified[0]->getStatement(), implode(', ', $verified[0]->getCitations()),
            (string) ($inv->getReport()['reproduction'] ?? 'not available'), $class, (string) $this->source->config('test_namespace'),
            (string) $this->source->config('base_class'), implode(', ', $this->source->allowed()),
        );

        $this->session->open($package);
        try {
            $content = $this->agent->call(new MessageBag(Message::ofUser($brief), Message::ofUser(self::INSTRUCTION)), ['temperature' => 0.1])->getContent();
        } finally {
            $this->session->close();
        }

        $data = \is_string($content) ? json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', $content)), true) : (array) $content;
        if (!\is_array($data) || !\is_string($data['code'] ?? null) || !\is_string($data['title'] ?? null)) {
            throw new InvalidModelOutput('The model did not return a test as JSON with "title" and "code".');
        }
        $refs = array_values(array_filter(array_map('strval', (array) ($data['citations'] ?? [])), static fn (string $r): bool => isset($evidence[$r])));

        return new TestProposal(
            title: mb_substr($data['title'], 0, 200),
            purpose: (string) ($data['purpose'] ?? ''),
            preconditions: array_values(array_map('strval', (array) ($data['preconditions'] ?? []))),
            scenario: (string) ($data['scenario'] ?? ''),
            expected: (string) ($data['expected'] ?? ''),
            className: $class,
            code: $data['code'],
            citations: $refs,
            source: $this->settings->engineLabel(),
        );
    }
}
