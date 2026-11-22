<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI;

use App\Infrastructure\AI\AiEnvVarProcessor;
use App\Infrastructure\AI\AiProviders;
use App\Infrastructure\AI\AiSettings;
use PHPUnit\Framework\TestCase;

final class AiProvidersTest extends TestCase
{
    public function testTheModelFallsBackToTheSelectedProvidersDefault(): void
    {
        $processor = new AiEnvVarProcessor();

        self::assertSame('claude-sonnet-5-5', $processor->getEnv('symtony_ai_model', 'SYMTONY_AI_MODEL', $this->env(['SYMTONY_AI_PROVIDER' => 'anthropic', 'SYMTONY_AI_MODEL' => ''])));
        self::assertSame('gemini-3.5-flash', $processor->getEnv('symtony_ai_model', 'SYMTONY_AI_MODEL', $this->env(['SYMTONY_AI_PROVIDER' => 'Gemini', 'SYMTONY_AI_MODEL' => ' '])));
        self::assertSame('Qwen/Qwen3.5-35B-A3B', $processor->getEnv('symtony_ai_model', 'SYMTONY_AI_MODEL', $this->env(['SYMTONY_AI_MODEL' => ''])), 'No provider = Hugging Face, as before.');
        self::assertSame('gpt-5.5', $processor->getEnv('symtony_ai_model', 'SYMTONY_AI_MODEL', $this->env(['SYMTONY_AI_PROVIDER' => 'openai', 'SYMTONY_AI_MODEL' => 'gpt-5.5'])));
    }

    public function testOnlyTheSelectedProvidersKeyIsRead(): void
    {
        $processor = new AiEnvVarProcessor();
        $env = $this->env(['SYMTONY_AI_PROVIDER' => 'openrouter', 'OPENROUTER_API_KEY' => 'or-key', 'OPENAI_API_KEY' => 'oa-key']);

        self::assertSame('or-key', $processor->getEnv('symtony_ai_key', 'SYMTONY_AI_PROVIDER', $env));
        self::assertSame('', $processor->getEnv('symtony_ai_key', 'SYMTONY_AI_PROVIDER', $this->env(['SYMTONY_AI_PROVIDER' => 'mistral'])));
        self::assertSame('', $processor->getEnv('symtony_ai_key', 'SYMTONY_AI_PROVIDER', $this->env(['SYMTONY_AI_PROVIDER' => 'nope', 'OPENAI_API_KEY' => 'oa-key'])));
    }

    public function testSettingsNameTheMissingSettingForTheSelectedProvider(): void
    {
        $missing = new AiSettings('', 'gpt-5.4-mini', null, true, 'openai');
        self::assertFalse($missing->isConfigured());
        self::assertSame('No AI provider configured: set OPENAI_API_KEY in .env.local.', $missing->whyNotConfigured());

        $claude = new AiSettings('sk-ant-test', 'claude-sonnet-5-5', 'together', true, 'anthropic');
        self::assertTrue($claude->isConfigured());
        self::assertSame('llm:anthropic/claude-sonnet-5-5', $claude->engineLabel(), 'The Hugging Face routing provider only applies to Hugging Face.');
        self::assertSame('Anthropic (Claude)', $claude->platformLabel());

        $unknown = new AiSettings('x', 'm', null, true, 'skynet');
        self::assertFalse($unknown->isConfigured());
        self::assertStringContainsString('Unknown SYMTONY_AI_PROVIDER "skynet"', $unknown->whyNotConfigured());
    }

    public function testAnOpenAiCompatibleEndpointNeedsAUrlAndAModelButNoKey(): void
    {
        self::assertTrue((new AiSettings('', 'llama-3.3-70b', null, true, 'generic', 'http://host.docker.internal:11434/v1'))->isConfigured());
        self::assertSame('No AI provider configured: set GENERIC_BASE_URL in .env.local.', (new AiSettings('', 'llama', null, true, 'generic'))->whyNotConfigured());
        self::assertFalse((new AiSettings('', '', null, true, 'generic', 'http://x/v1'))->isConfigured());
    }

    public function testEveryProviderHasAKeySettingAndAllButGenericADefaultModel(): void
    {
        foreach (AiProviders::ALL as $name => $provider) {
            self::assertNotSame('', $provider['key_env'], $name);
            self::assertSame('generic' === $name, '' === $provider['default_model'], $name);
        }
    }

    /** @param array<string, string> $values */
    private function env(array $values): \Closure
    {
        return static fn (string $name): string => $values[$name] ?? throw new \RuntimeException('Environment variable not found: '.$name);
    }
}
