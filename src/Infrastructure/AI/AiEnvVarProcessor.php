<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * Resolves the active provider's settings at runtime, so switching providers is only an .env.local change.
 *
 * - %env(symtony_ai_model:SYMTONY_AI_MODEL)%   the configured model, or the provider's default when empty
 * - %env(symtony_ai_key:SYMTONY_AI_PROVIDER)%  the API key of the selected provider (never logged or shown)
 */
final class AiEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $definition = AiProviders::get($this->read($getEnv, 'SYMTONY_AI_PROVIDER'));

        if ('symtony_ai_model' === $prefix) {
            $model = trim($this->read($getEnv, $name));

            return '' !== $model ? $model : ($definition['default_model'] ?? '');
        }

        return null === $definition ? '' : trim($this->read($getEnv, $definition['key_env']));
    }

    public static function getProvidedTypes(): array
    {
        return ['symtony_ai_model' => 'string', 'symtony_ai_key' => 'string'];
    }

    private function read(\Closure $getEnv, string $name): string
    {
        try {
            return (string) $getEnv($name);
        } catch (\Throwable) {
            return '';
        }
    }
}
