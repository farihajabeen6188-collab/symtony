<?php

declare(strict_types=1);

namespace App\Spike;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Phase 0 spike: verifies tool calling + structured output against the configured model.
 * Prints exactly what happened. Never reports success it did not observe.
 */
#[AsCommand(name: 'app:spike:ai', description: 'Phase 0: verify tool calling and structured output on the configured AI model')]
final class SpikeAiCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'ai.agent.spike')]
        private readonly AgentInterface $agent,
        #[Autowire(env: 'SYMTONY_AI_MODEL')]
        private readonly string $model,
        #[Autowire(env: 'HUGGINGFACE_API_KEY')]
        private readonly string $apiKey,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SYMTONY AI spike — model: '.$this->model);

        if ('' === trim($this->apiKey)) {
            $io->warning('HUGGINGFACE_API_KEY is empty. Not tested — set it in .env.local and run again.');

            return Command::FAILURE;
        }

        $messages = new MessageBag(
            Message::forSystem('You are a test harness. Always call the get_server_time tool before answering. Never guess the time.'),
            Message::ofUser('What is the server time right now? Answer with the exact tool value.'),
        );

        // 1) Tool calling
        try {
            $execution = $this->agent->call($messages);
            $text = $execution->asText();
        } catch (\Throwable $e) {
            $io->error('Tool-calling call failed: '.$e::class.': '.$e->getMessage());

            return Command::FAILURE;
        }

        $toolCalled = false;
        foreach ($execution->getMessages()->getMessages() as $message) {
            if ($message instanceof \Symfony\AI\Platform\Message\ToolCallMessage) {
                $toolCalled = true;
                $io->writeln(sprintf('  tool result received: %s => %s', $message->getToolCall()->getName(), $message->asText() ?? '(no text)'));
            }
        }
        $io->writeln('  model answer: '.$text);
        $toolCalled
            ? $io->success('Tool calling: WORKS (the model called get_server_time)')
            : $io->error('Tool calling: NOT OBSERVED (no tool call in the conversation)');

        // 2) Structured output
        $structuredOk = false;
        try {
            $execution = $this->agent->call(
                new MessageBag(
                    Message::forSystem('Call get_server_time, then answer in the requested JSON structure.'),
                    Message::ofUser('Report the server time.'),
                ),
                ['response_format' => SpikeAnswer::class],
            );
            $object = $execution->asObject();
            if ($object instanceof SpikeAnswer && '' !== $object->serverTime) {
                $structuredOk = true;
                $io->writeln(sprintf('  structured: serverTime=%s summary=%s', $object->serverTime, $object->summary));
                $io->success('Structured output: WORKS');
            } else {
                $io->error('Structured output: unexpected result '.get_debug_type($object));
            }
        } catch (\Throwable $e) {
            $io->error('Structured output failed: '.$e::class.': '.$e->getMessage());
        }

        return $toolCalled && $structuredOk ? Command::SUCCESS : Command::FAILURE;
    }
}
