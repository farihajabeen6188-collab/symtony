<?php

declare(strict_types=1);

namespace Symtony;

use Doctrine\DBAL\Driver\Middleware as DbalMiddleware;
use Monolog\Handler\AbstractProcessingHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symtony\Bridge\Monolog\SymtonyHandler;
use Symtony\Cache\TracedCache;
use Symtony\Command\ReleaseCommand;
use Symtony\Doctrine\TracingMiddleware as DoctrineTracingMiddleware;
use Symtony\HttpClient\TracingHttpClient;
use Symtony\Messenger\TracingMiddleware as MessengerTracingMiddleware;
use Symtony\Symfony\KernelSubscriber;
use Symtony\Trace\TraceContext;
use Symtony\Transport\EventBuffer;
use Symtony\Transport\HttpTransport;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * symtony:
 *     endpoint: '%env(SYMTONY_ENDPOINT)%'   # https://symtony.example/api/v1/events
 *     token: '%env(SYMTONY_TOKEN)%'
 *     service: 'shop'
 *     release: '%env(default::APP_RELEASE)%'
 */
final class SymtonyBundle extends AbstractBundle
{
    protected string $extensionAlias = 'symtony';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('endpoint')->defaultValue('')->info('SYMTONY ingestion URL, e.g. https://symtony.example/api/v1/events')->end()
                ->scalarNode('token')->defaultValue('')->info('Ingestion token (sym_…). Keep it in .env.local.')->end()
                ->scalarNode('service')->defaultValue('app')->end()
                ->scalarNode('release')->defaultNull()->end()
                ->scalarNode('environment')->defaultNull()->info('Overrides the environment configured in SYMTONY.')->end()
                ->floatNode('timeout')->defaultValue(2.0)->info('Seconds to wait when delivering events.')->end()
                ->scalarNode('transport')->defaultNull()->info('Service id of a custom TransportInterface (tests: record events in memory).')->end()
                ->arrayNode('capture')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('http')->defaultTrue()->end()
                        ->booleanNode('doctrine')->defaultTrue()->end()
                        ->booleanNode('http_client')->defaultTrue()->end()
                        ->booleanNode('messenger')->defaultTrue()->end()
                        ->booleanNode('cache')->defaultTrue()->info('Decorates cache.app with hit/miss + age spans.')->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array{enabled: bool, endpoint: string, token: string, service: string, release: ?string, environment: ?string, timeout: float, transport: ?string, capture: array<string, bool>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()->defaults()->autowire(false)->autoconfigure(false);

        $services->set('symtony.transport', HttpTransport::class)
            ->args([
                $config['enabled'] ? $config['endpoint'] : '',
                $config['token'],
                $config['timeout'],
                'sdk',
                [service('symtony.delivery_logger'), 'onError'],
            ]);
        $services->set('symtony.delivery_logger', DeliveryLogger::class)
            ->args([service('logger')->nullOnInvalid()])
            ->tag('monolog.logger', ['channel' => 'symtony']);

        $services->set('symtony.buffer', EventBuffer::class)->args([service($config['transport'] ?? 'symtony.transport')]);
        $services->set('symtony.trace_context', TraceContext::class);
        $services->set(Tracer::class)
            ->args([
                service('symtony.trace_context'),
                service('symtony.buffer'),
                $config['service'],
                $config['release'],
                $config['environment'],
                param('kernel.project_dir'),
            ])
            ->public();
        $services->alias('symtony.tracer', Tracer::class)->public();

        if ($config['capture']['http']) {
            $services->set('symtony.kernel_subscriber', KernelSubscriber::class)
                ->args([service(Tracer::class)])
                ->tag('kernel.event_subscriber');
        }

        if ($config['capture']['doctrine'] && interface_exists(DbalMiddleware::class)) {
            $services->set('symtony.doctrine_middleware', DoctrineTracingMiddleware::class)
                ->args([service(Tracer::class)])
                ->tag('doctrine.middleware');
        }

        if ($config['capture']['http_client'] && interface_exists(HttpClientInterface::class) && trait_exists(\Symfony\Component\HttpClient\AsyncDecoratorTrait::class)) {
            $services->set('symtony.http_client', TracingHttpClient::class)
                ->decorate('http_client', null, -10, ContainerBuilder::IGNORE_ON_INVALID_REFERENCE)
                ->args([service('.inner'), service(Tracer::class)]);
        }

        if ($config['capture']['cache'] && interface_exists(CacheInterface::class)) {
            // Only the contracts CacheInterface autowiring alias is traced; PSR-6 users of cache.app are untouched.
            $services->set('symtony.cache_app', TracedCache::class)
                ->args([service('cache.app'), service(Tracer::class), 'cache.app']);
            $services->alias(CacheInterface::class, 'symtony.cache_app');
        }

        if ($config['capture']['messenger'] && interface_exists(MiddlewareInterface::class)) {
            $services->set('symtony.messenger_middleware', MessengerTracingMiddleware::class)
                ->args([service(Tracer::class)]);
        }

        if (class_exists(AbstractProcessingHandler::class)) {
            // Wire with: monolog.handlers.symtony: { type: service, id: symtony.monolog_handler }
            $services->set('symtony.monolog_handler', SymtonyHandler::class)
                ->args(['', '', $config['service'], 'warning', true, service('symtony.buffer'), service(Tracer::class)]);
        }

        $services->set('symtony.release_command', ReleaseCommand::class)
            ->args([service(Tracer::class)])
            ->tag('console.command');
    }
}

