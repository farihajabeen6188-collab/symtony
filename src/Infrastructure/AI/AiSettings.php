<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which model (if any) investigations may call. External model calls are explicit and configurable:
 * no key → no call, and the UI says so.
 */
final class AiSettings
{
    public function __construct(
        #[Autowire(env: 'HUGGINGFACE_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'SYMTONY_AI_MODEL')] private readonly string $model,
        #[Autowire(env: 'default::HUGGINGFACE_PROVIDER')] private readonly ?string $provider,
        #[Autowire(env: 'bool:SYMTONY_AI_ENABLED')] private readonly bool $enabled,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->enabled && '' !== trim($this->apiKey) && '' !== trim($this->model);
    }

    public function platform(): string
    {
        return 'huggingface';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function engineLabel(): string
    {
        return \sprintf('llm:%s/%s%s', $this->platform(), $this->model, null !== $this->provider && '' !== $this->provider ? ' via '.$this->provider : '');
    }

    public function whyNotConfigured(): string
    {
        return match (true) {
            !$this->enabled => 'Model calls are disabled (SYMTONY_AI_ENABLED=0).',
            '' === trim($this->apiKey) => 'No AI provider configured: set HUGGINGFACE_API_KEY in .env.local.',
            default => 'No model configured: set SYMTONY_AI_MODEL.',
        };
    }
}
