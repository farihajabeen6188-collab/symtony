<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which model (if any) investigations may call. External model calls are explicit and configurable:
 * no key → no call, and the UI says so. SYMTONY_AI_PROVIDER picks the provider (see AiProviders).
 */
final class AiSettings
{
    private readonly string $platform;

    public function __construct(
        #[Autowire(env: 'symtony_ai_key:SYMTONY_AI_PROVIDER')] private readonly string $apiKey,
        #[Autowire(env: 'symtony_ai_model:SYMTONY_AI_MODEL')] private readonly string $model,
        #[Autowire(env: 'default::HUGGINGFACE_PROVIDER')] private readonly ?string $provider,
        #[Autowire(env: 'bool:SYMTONY_AI_ENABLED')] private readonly bool $enabled,
        #[Autowire(env: 'default::SYMTONY_AI_PROVIDER')] ?string $platform = null,
        #[Autowire(env: 'default::GENERIC_BASE_URL')] private readonly ?string $baseUrl = null,
    ) {
        $this->platform = AiProviders::normalize($platform ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->enabled && AiProviders::isKnown($this->platform) && $this->hasKey() && '' !== trim($this->model);
    }

    /** Whether the selected provider has credentials (an OpenAI-compatible endpoint needs a URL; its key is optional). */
    public function hasKey(): bool
    {
        return 'generic' === $this->platform ? '' !== trim($this->baseUrl ?? '') : '' !== trim($this->apiKey);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Hugging Face only: the inference provider requests are routed to (null = routed by Hugging Face). */
    public function provider(): ?string
    {
        return 'huggingface' === $this->platform && null !== $this->provider && '' !== $this->provider ? $this->provider : null;
    }

    public function platform(): string
    {
        return $this->platform;
    }

    public function platformLabel(): string
    {
        return AiProviders::get($this->platform)['label'] ?? $this->platform;
    }

    /** The setting to fill in for this provider (a name, never a value). */
    public function keyEnv(): string
    {
        return 'generic' === $this->platform ? 'GENERIC_BASE_URL' : (AiProviders::get($this->platform)['key_env'] ?? '');
    }

    public function model(): string
    {
        return $this->model;
    }

    public function engineLabel(): string
    {
        return \sprintf('llm:%s/%s%s', $this->platform, $this->model, null !== $this->provider() ? ' via '.$this->provider() : '');
    }

    public function whyNotConfigured(): string
    {
        return match (true) {
            !$this->enabled => 'Model calls are disabled (SYMTONY_AI_ENABLED=0).',
            !AiProviders::isKnown($this->platform) => \sprintf('Unknown SYMTONY_AI_PROVIDER "%s". Use one of: %s.', $this->platform, implode(', ', array_keys(AiProviders::ALL))),
            !$this->hasKey() => \sprintf('No AI provider configured: set %s in .env.local.', $this->keyEnv()),
            default => 'No model configured: set SYMTONY_AI_MODEL.',
        };
    }
}
