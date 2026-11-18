<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\Investigation\EvidencePackage;
use App\Application\Investigation\FindingDraft;
use App\Application\Knowledge\SimilarIncident;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks the Symfony AI "investigator" agent for hypotheses over the evidence package.
 * The agent can only read this investigation's evidence through registered tools.
 */
final class LlmInvestigator
{
    public const PROMPT_VERSION = 'inv-2026-11.1';
    public const INSTRUCTION = 'Investigate this incident using the evidence. Answer with the JSON object described in your instructions.';

    public function __construct(
        #[Autowire(service: 'ai.agent.investigator')] private readonly AgentInterface $agent,
        private readonly EvidenceSession $session,
        private readonly LlmReportParser $parser,
        private readonly AiSettings $settings,
    ) {
    }

    /**
     * @param list<FindingDraft>    $heuristics
     * @param list<SimilarIncident> $history
     *
     * @return array{summary: string, drafts: list<FindingDraft>, report: array<string, mixed>, usage: array<string, int|float|null>, tools: list<string>}
     */
    public function investigate(EvidencePackage $package, array $heuristics, array $history): array
    {
        $brief = "Incident evidence (redacted). Items marked NOT IN TRACE are inferred from timing only.\n\n"
            .$package->index()
            ."\n\nFindings from SYMTONY's deterministic analyzers (verify them; do not just repeat them):\n"
            .implode("\n", array_map(static fn (FindingDraft $d): string => \sprintf('- [%s] %s (cites %s)', $d->kind, $d->statement, implode(', ', $d->citations)), $heuristics));

        $this->session->open($package, $history);
        $started = microtime(true);
        try {
            $execution = $this->agent->call(new MessageBag(Message::ofUser($brief), Message::ofUser(self::INSTRUCTION)), [
                'temperature' => 0.1,
                'max_output_tokens' => 2500,
            ]);
            $content = $execution->getContent();
            $metadata = $execution->getMetadata();
        } finally {
            $tools = $this->session->toolLog();
            $this->session->close();
        }

        $parsed = $this->parser->parse($content, $this->settings->engineLabel());
        $usage = ['latency_ms' => (int) round((microtime(true) - $started) * 1000)];
        $tokens = $metadata->get('token_usage');
        if ($tokens instanceof TokenUsageInterface) {
            $usage += ['prompt_tokens' => $tokens->getPromptTokens(), 'completion_tokens' => $tokens->getCompletionTokens(), 'total_tokens' => $tokens->getTotalTokens()];
        }

        return $parsed + ['usage' => $usage, 'tools' => $tools];
    }
}
