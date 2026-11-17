<?php

declare(strict_types=1);

namespace Symtony\HttpClient;

use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symtony\Tracer;

/**
 * Wraps the app's HTTP client: one external_http span per request, W3C traceparent propagated.
 */
final class TracingHttpClient implements HttpClientInterface
{
    use AsyncDecoratorTrait;

    public function __construct(HttpClientInterface $client, private readonly Tracer $tracer)
    {
        $this->client = $client;
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $parts = parse_url($url) ?: [];
        $host = ($parts['host'] ?? 'unknown').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = $parts['path'] ?? '/';
        $span = $this->tracer->start(\sprintf('%s %s%s', $method, $host, $path), 'external_http', [
            'component' => 'external:'.($parts['host'] ?? 'unknown'),
            'external' => ['method' => $method, 'host' => $host, 'path' => $path],
        ], detached: true);

        $options['headers'] = (array) ($options['headers'] ?? []);
        $options['headers']['traceparent'] = $this->tracer->context()->traceparent($span);
        if (null !== $this->tracer->context()->requestId()) {
            $options['headers']['X-Request-Id'] ??= $this->tracer->context()->requestId();
        }

        $tracer = $this->tracer;
        $passthru = static function (ChunkInterface $chunk, AsyncContext $context) use ($span, $tracer): \Generator {
            try {
                $isLast = $chunk->isLast();
            } catch (TransportExceptionInterface $e) {
                $tracer->end($span, ['external' => ['error' => mb_substr($e->getMessage(), 0, 300)]], true);
                throw $e;
            }
            if ($isLast) {
                $status = $context->getStatusCode();
                $tracer->end($span, ['external' => ['status' => $status]], $status >= 500);
            }

            yield $chunk;
        };

        return new AsyncResponse($this->client, $method, $url, $options, $passthru);
    }
}
