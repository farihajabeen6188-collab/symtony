<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

/**
 * The model providers SYMTONY can use, all through Symfony AI platform bridges.
 * SYMTONY_AI_PROVIDER picks one; each reads its own key from .env.local.
 */
final class AiProviders
{
    public const DEFAULT = 'huggingface';

    /** @var array<string, array{label: string, key_env: string, default_model: string, keys_url: string}> */
    public const ALL = [
        'huggingface' => ['label' => 'Hugging Face Inference Providers', 'key_env' => 'HUGGINGFACE_API_KEY', 'default_model' => 'Qwen/Qwen3.5-35B-A3B', 'keys_url' => 'https://huggingface.co/settings/tokens'],
        'openai' => ['label' => 'OpenAI', 'key_env' => 'OPENAI_API_KEY', 'default_model' => 'gpt-5.4-mini', 'keys_url' => 'https://platform.openai.com/api-keys'],
        'anthropic' => ['label' => 'Anthropic (Claude)', 'key_env' => 'ANTHROPIC_API_KEY', 'default_model' => 'claude-sonnet-5-5', 'keys_url' => 'https://console.anthropic.com/settings/keys'],
        'gemini' => ['label' => 'Google Gemini', 'key_env' => 'GEMINI_API_KEY', 'default_model' => 'gemini-3.5-flash', 'keys_url' => 'https://aistudio.google.com/apikey'],
        'openrouter' => ['label' => 'OpenRouter', 'key_env' => 'OPENROUTER_API_KEY', 'default_model' => 'openai/gpt-5-mini', 'keys_url' => 'https://openrouter.ai/settings/keys'],
        'mistral' => ['label' => 'Mistral AI', 'key_env' => 'MISTRAL_API_KEY', 'default_model' => 'mistral-medium-latest', 'keys_url' => 'https://console.mistral.ai/api-keys'],
        'deepseek' => ['label' => 'DeepSeek', 'key_env' => 'DEEPSEEK_API_KEY', 'default_model' => 'deepseek-v4-flash', 'keys_url' => 'https://platform.deepseek.com/api_keys'],
        // Any OpenAI-compatible endpoint: Groq, Together, Fireworks, Ollama, LM Studio, vLLM, ...
        'generic' => ['label' => 'OpenAI-compatible endpoint', 'key_env' => 'GENERIC_API_KEY', 'default_model' => '', 'keys_url' => ''],
    ];

    public static function normalize(string $provider): string
    {
        $provider = strtolower(trim($provider));

        return '' === $provider ? self::DEFAULT : $provider;
    }

    public static function isKnown(string $provider): bool
    {
        return isset(self::ALL[self::normalize($provider)]);
    }

    /** @return array{label: string, key_env: string, default_model: string, keys_url: string}|null */
    public static function get(string $provider): ?array
    {
        return self::ALL[self::normalize($provider)] ?? null;
    }
}
