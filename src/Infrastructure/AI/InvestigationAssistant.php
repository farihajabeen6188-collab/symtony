<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\Investigation\EvidencePackage;
use App\Application\Query\InvestigationQueries;
use App\Domain\Assistant\AssistantMessage;
use App\Domain\Incident\Incident;
use App\Domain\Investigation\Investigation;
use App\Domain\Investigation\InvestigationStatus;
use App\Domain\Workspace\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Answers questions about one incident from its latest completed investigation's evidence, through the
 * same read-only evidence tools as the investigator. Records the tools really called and the refs cited.
 * Without a provider or an investigation it refuses with the reason; it never makes up an answer.
 */
final class InvestigationAssistant
{
    private const HISTORY = 8;

    public function __construct(
        #[Autowire(service: 'ai.agent.assistant')] private readonly AgentInterface $agent,
        private readonly EvidenceSession $session,
        private readonly AiSettings $settings,
        private readonly InvestigationQueries $investigations,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Null when the assistant can answer; otherwise why it cannot. */
    public function unavailableReason(Incident $incident): ?string
    {
        if (!$this->settings->isConfigured()) {
            return $this->settings->whyNotConfigured();
        }
        if (null === $this->evidenceSource($incident)) {
            return \sprintf('Investigate %s first: the assistant answers from an investigation\'s evidence.', $incident->getReference());
        }

        return null;
    }

    /** @return list<AssistantMessage> */
    public function conversation(Incident $incident, int $limit = 50): array
    {
        $rows = $this->em->getRepository(AssistantMessage::class)->findBy(['incident' => $incident], ['createdAt' => 'DESC'], $limit);

        return array_reverse($rows);
    }

    public function ask(Incident $incident, User $user, string $question): AssistantMessage
    {
        $question = trim(mb_substr($question, 0, 1000));
        if ('' === $question) {
            throw new \DomainException('Type a question first.');
        }
        if (null !== ($why = $this->unavailableReason($incident))) {
            throw new \DomainException($why);
        }
        $inv = $this->evidenceSource($incident);
        \assert($inv instanceof Investigation);

        $history = $this->conversation($incident, self::HISTORY);
        $this->em->persist(new AssistantMessage($incident, $inv, $user, AssistantMessage::USER, $question));

        $evidence = $this->investigations->evidence($inv);
        $package = new EvidencePackage($incident, null);
        foreach ($evidence as $item) {
            $package->add($item);
        }
        $messages = [Message::ofUser(\sprintf('Incident %s: %s. Evidence package: %d items from investigation %s.', $incident->getReference(), $incident->getTitle(), \count($evidence), $inv->getShortId()))];
        foreach ($history as $m) {
            if (AssistantMessage::USER === $m->getRole()) {
                $messages[] = Message::ofUser($m->getContent());
            } elseif (AssistantMessage::ASSISTANT === $m->getRole()) {
                $messages[] = Message::ofAssistant($m->getContent());
            }
        }
        $messages[] = Message::ofUser($question);

        $this->session->open($package);
        try {
            $content = $this->agent->call(new MessageBag(...$messages), ['temperature' => 0.2])->getContent();
            $answer = \is_string($content) ? trim($content) : '';
            if ('' === $answer) {
                throw new InvalidModelOutput('The model returned an empty answer.');
            }
            preg_match_all('/\bEV-\d+\b/', $answer, $m);
            $cited = array_values(array_intersect(array_unique($m[0]), array_keys($evidence)));
            $reply = new AssistantMessage($incident, $inv, null, AssistantMessage::ASSISTANT, $answer, $this->session->toolLog(), $cited, $this->settings->engineLabel());
        } catch (\Throwable $e) {
            $this->logger->error('Assistant call failed', ['incident' => $incident->getReference(), 'exception' => $e]);
            $reply = new AssistantMessage($incident, $inv, null, AssistantMessage::ERROR, 'The model call failed: '.mb_substr($e->getMessage(), 0, 300), $this->session->toolLog(), [], $this->settings->engineLabel());
        } finally {
            $this->session->close();
        }
        $this->em->persist($reply);
        $this->em->flush();

        return $reply;
    }

    private function evidenceSource(Incident $incident): ?Investigation
    {
        foreach ($this->investigations->forIncident($incident) as $inv) {
            if (InvestigationStatus::Completed === $inv->getStatus()) {
                return $inv;
            }
        }

        return null;
    }
}
