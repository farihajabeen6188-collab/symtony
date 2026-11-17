<?php

declare(strict_types=1);

namespace Symtony\Transport;

/**
 * Posts batches to SYMTONY's ingestion API with short timeouts. Dependency-free (curl or streams),
 * so it is never itself traced and never breaks the host application.
 */
final class HttpTransport implements TransportInterface
{
    /** @var (\Closure(string): void)|null */
    private ?\Closure $onError;
    private ?string $lastError = null;

    /**
     * @param callable(string): void|null $onError called with a short reason when delivery fails
     */
    public function __construct(
        private readonly string $endpoint,
        #[\SensitiveParameter] private readonly string $token,
        private readonly float $timeout = 2.0,
        private readonly string $source = 'sdk',
        ?callable $onError = null,
    ) {
        $this->onError = null !== $onError ? $onError(...) : null;
    }

    public function send(array $events): bool
    {
        if ([] === $events || '' === $this->endpoint || '' === $this->token) {
            return '' !== $this->endpoint && '' !== $this->token;
        }

        try {
            $body = json_encode(['events' => $events], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException $e) {
            return $this->fail('could not encode events: '.$e->getMessage());
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer '.$this->token,
            'X-Symtony-Source: '.$this->source,
            'User-Agent: symtony-php/1.0',
        ];

        try {
            [$status, $response] = \function_exists('curl_init') ? $this->curl($body, $headers) : $this->stream($body, $headers);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        if ($status < 200 || $status >= 300) {
            $detail = \is_string($response) ? (json_decode($response, true)['detail'] ?? '') : '';

            return $this->fail(\sprintf('SYMTONY answered HTTP %d%s', $status, '' !== $detail ? ': '.$detail : ''));
        }
        $this->lastError = null;

        return true;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param list<string> $headers
     *
     * @return array{0: int, 1: string|false}
     */
    private function curl(string $body, array $headers): array
    {
        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_CONNECTTIMEOUT_MS => (int) min(1000, $this->timeout * 1000),
            \CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            \CURLOPT_NOSIGNAL => true,
        ]);
        $response = curl_exec($ch);
        if (false === $response) {
            $error = curl_error($ch);
            throw new \RuntimeException('' !== $error ? $error : 'request failed');
        }

        return [(int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE), \is_string($response) ? $response : ''];
    }

    /**
     * @param list<string> $headers
     *
     * @return array{0: int, 1: string|false}
     */
    private function stream(string $body, array $headers): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($this->endpoint, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (1 !== preg_match('/\s(\d{3})\s/', $statusLine.' ', $m)) {
            throw new \RuntimeException('no response from '.parse_url($this->endpoint, \PHP_URL_HOST));
        }

        return [(int) $m[1], $response];
    }

    private function fail(string $reason): bool
    {
        $this->lastError = $reason;
        if (null !== $this->onError) {
            try {
                ($this->onError)($reason);
            } catch (\Throwable) {
                // never propagate
            }
        }

        return false;
    }
}
