<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use Psr\Container\ContainerInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * The platform every agent uses: forwards to the Symfony AI platform chosen by SYMTONY_AI_PROVIDER.
 * Only the selected platform is ever instantiated, so unused providers need no key.
 */
final class SelectedPlatform implements PlatformInterface
{
    public function __construct(
        #[AutowireLocator([
            'huggingface' => new Autowire(service: 'ai.platform.huggingface'),
            'openai' => new Autowire(service: 'ai.platform.openai'),
            'anthropic' => new Autowire(service: 'ai.platform.anthropic'),
            'gemini' => new Autowire(service: 'ai.platform.gemini'),
            'openrouter' => new Autowire(service: 'ai.platform.openrouter'),
            'mistral' => new Autowire(service: 'ai.platform.mistral'),
            'deepseek' => new Autowire(service: 'ai.platform.deepseek'),
            'generic' => new Autowire(service: 'ai.platform.generic.custom'),
        ])]
        private readonly ContainerInterface $platforms,
        #[Autowire(env: 'default::SYMTONY_AI_PROVIDER')] private readonly ?string $provider,
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        return $this->platform()->invoke($model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->platform()->getModelCatalog();
    }

    public function provider(): string
    {
        return AiProviders::normalize($this->provider ?? '');
    }

    private function platform(): PlatformInterface
    {
        $provider = $this->provider();
        if (!$this->platforms->has($provider)) {
            throw new \InvalidArgumentException(\sprintf('Unknown SYMTONY_AI_PROVIDER "%s". Use one of: %s.', $provider, implode(', ', array_keys(AiProviders::ALL))));
        }

        return $this->platforms->get($provider);
    }
}
