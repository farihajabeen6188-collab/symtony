<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Incident\IncidentGrouper;
use App\Application\Investigation\InvestigationRunner;
use App\Application\Investigation\StartInvestigation;
use App\Application\Query\InvestigationQueries;
use App\Domain\Assistant\AssistantMessage;
use App\Domain\Incident\Incident;
use App\Infrastructure\AI\AiSettings;
use App\Infrastructure\AI\EvidenceSession;
use App\Infrastructure\AI\InvestigationAssistant;
use App\Infrastructure\AI\Tool\EvidenceTools;
use App\Infrastructure\Security\DemoAccountProvisioner;
use App\Tests\Support\EventFactory;
use App\Tests\Support\StaleCacheScenario;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MockAgent;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AssistantTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $em;
    private Incident $incident;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $app = (new EventFactory($this->em))->application(null, 'shop');
        $this->incident = (new StaleCacheScenario($this->em, $app))->create(static::getContainer()->get(IncidentGrouper::class));
    }

    public function testRefusesWithoutProviderOrInvestigation(): void
    {
        $default = static::getContainer()->get(InvestigationAssistant::class);
        self::assertStringContainsString('No AI provider configured', (string) $default->unavailableReason($this->incident));

        $assistant = $this->assistant(new MockAgent());
        self::assertStringContainsString('Investigate '.$this->incident->getReference().' first', (string) $assistant->unavailableReason($this->incident));
        $this->expectException(\DomainException::class);
        $assistant->ask($this->incident, $this->user(), 'Why did this happen?');
    }

    public function testAnswersFromEvidenceAndRecordsRealToolCalls(): void
    {
        $this->investigate();
        $tools = static::getContainer()->get(EvidenceTools::class);
        $sent = '';
        $agent = new MockAgent(['Why did this happen?' => function (MessageBag $messages) use ($tools, &$sent): string {
            // What a model would do: read the evidence through the tools, then answer citing refs.
            $sent = $tools->list();
            $tools->get('EV-1');

            return 'The stock write violated stock_non_negative (EV-1). A cached stock value was used (EV-999 does not exist).';
        }]);
        $assistant = $this->assistant($agent);

        $reply = $assistant->ask($this->incident, $this->user(), 'Why did this happen?');

        self::assertSame(AssistantMessage::ASSISTANT, $reply->getRole());
        self::assertSame(['evidence_list()', 'evidence_get(EV-1)'], $reply->getToolCalls());
        self::assertSame(['EV-1'], $reply->getEvidence(), 'Only refs that exist become evidence chips');
        self::assertStringStartsWith('llm:huggingface/', (string) $reply->getSource());
        self::assertStringNotContainsString('topsecret', $sent, 'The model only sees redacted evidence');

        $conversation = $assistant->conversation($this->incident);
        self::assertSame(['user', 'assistant'], array_map(static fn (AssistantMessage $m): string => $m->getRole(), $conversation));
    }

    public function testFollowUpsCarryTheConversationAndFailuresAreShown(): void
    {
        $this->investigate();
        $second = [];
        $agent = new MockAgent([
            'First?' => 'First answer.',
            'And then?' => static function (MessageBag $m) use (&$second): string {
                $second = $m->getMessages();
                throw new \RuntimeException('provider timeout');
            },
        ]);
        $assistant = $this->assistant($agent);
        $assistant->ask($this->incident, $this->user(), 'First?');
        $failed = $assistant->ask($this->incident, $this->user(), 'And then?');

        self::assertSame(AssistantMessage::ERROR, $failed->getRole());
        self::assertStringContainsString('provider timeout', $failed->getContent());
        $texts = [];
        foreach ($second as $msg) {
            $content = $msg->getContent();
            foreach (\is_array($content) ? $content : [$content] as $part) {
                $texts[] = \is_string($part) ? $part : (method_exists($part, 'getText') ? $part->getText() : '');
            }
        }
        self::assertCount(4, $second, 'Intro, first question, first answer, follow-up');
        self::assertStringContainsString('First answer.', implode(' ', $texts), 'Earlier turns are sent as context');
    }

    private function investigate(): void
    {
        $inv = static::getContainer()->get(StartInvestigation::class)($this->incident, null, 'system');
        static::getContainer()->get(InvestigationRunner::class)->run($inv);
    }

    private function assistant(MockAgent $agent): InvestigationAssistant
    {
        $c = static::getContainer();

        return new InvestigationAssistant($agent, $c->get(EvidenceSession::class), new AiSettings('hf_test_key', 'Qwen/Qwen3.5-35B-A3B', null, true),
            $c->get(InvestigationQueries::class), $this->em, new NullLogger());
    }

    private function user(): \App\Domain\Workspace\User
    {
        return static::getContainer()->get(DemoAccountProvisioner::class)->provision()['user'];
    }
}
