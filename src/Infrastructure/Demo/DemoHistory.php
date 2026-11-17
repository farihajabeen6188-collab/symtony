<?php

declare(strict_types=1);

namespace App\Infrastructure\Demo;

/**
 * Deterministic history for the demo workspace, sent through the public ingestion API like any client.
 * Every event carries context.seeded = true and is shown with a "seeded" tag in the UI.
 *
 * Stories:
 *  - background: healthy storefront traffic over the last 24 h
 *  - pricing:    a resolved incident 21 days ago (stale price cache → wrong order totals)
 *  - redis:      a low-severity, flapping warning over the last 3 days
 *  - mailer:     timeouts 3 days ago (later resolved) that recur 5 hours ago (reopens the incident)
 */
final class DemoHistory
{
    private int $seq = 0;

    /** @return list<array<string, mixed>> */
    public function background(\DateTimeImmutable $now): array
    {
        mt_srand(4242);
        $events = [];
        $skus = ['SKU-1042', 'SKU-2210', 'SKU-3305'];
        for ($t = $now->getTimestamp() - 86400; $t < $now->getTimestamp() - 3600; $t += mt_rand(360, 840)) {
            $sku = $skus[mt_rand(0, 2)];
            $events = [...$events, ...$this->request($t + mt_rand(0, 999) / 1000, 'GET', '/products/'.$sku, 'product', 200, [
                ['db_query', 'SELECT product', 1.2 + mt_rand(0, 30) / 10, ['component' => 'database', 'db' => ['operation' => 'SELECT', 'table' => 'product', 'statement' => 'SELECT sku, name, price_cents, stock FROM product WHERE sku = ?']]],
            ])];
        }

        return $events;
    }

    /** @return list<array<string, mixed>> */
    public function pricingIncident(\DateTimeImmutable $now): array
    {
        mt_srand(2121);
        $events = [];
        $start = $now->getTimestamp() - 21 * 86400 - 7200;
        foreach ([0, 540, 1380, 2210, 3020, 4410] as $i => $offset) {
            $events = [...$events, ...$this->request($start + $offset + 0.231, 'POST', '/checkout', 'checkout', 500, [
                ['service_call', 'PricingService::priceFor', 2.4, ['component' => 'pricing']],
                ['cache', 'cache hit price_SKU-2210', 0.4, ['component' => 'cache', 'cache' => ['pool' => 'cache.app', 'key' => 'price_SKU-2210', 'hit' => true, 'age_seconds' => 900 + $i * 37]]],
                ['db_query', 'SELECT price_list', 1.9, ['component' => 'database', 'db' => ['operation' => 'SELECT', 'table' => 'price_list', 'statement' => 'SELECT amount_cents FROM price_list WHERE sku = ? AND valid_from <= ?']]],
            ], [
                'class' => 'App\Pricing\OrderTotalMismatch',
                'message' => \sprintf('Order total 17.10 EUR does not match price list total 19.00 EUR for SKU-2210 (order %d)', 81200 + $i),
                'frames' => [
                    ['class' => 'App\Pricing\PricingService', 'function' => 'assertTotal', 'file' => 'src/Pricing/PricingService.php', 'line' => 77, 'in_app' => true],
                    ['class' => 'App\Checkout\CheckoutService', 'function' => 'checkout', 'file' => 'src/Checkout/CheckoutService.php', 'line' => 41, 'in_app' => true],
                ],
            ], release: '1.2.0')];
        }

        return $events;
    }

    /** @return list<array<string, mixed>> */
    public function redisFlapping(\DateTimeImmutable $now): array
    {
        mt_srand(777);
        $events = [];
        foreach ([70, 61, 49, 47, 30, 22, 13, 9, 2] as $hoursAgo) {
            $t = $now->getTimestamp() - $hoursAgo * 3600 - mt_rand(0, 1800);
            $events[] = $this->event($t, 'log', 'warning', 'shop', 'Redis connection reset by peer, retrying (attempt 1 of 3)', 'cache retry', ['component' => 'cache', 'cache' => ['pool' => 'cache.app', 'backend' => 'redis']]);
        }

        return $events;
    }

    /** @return list<array<string, mixed>> */
    public function mailerTimeouts(\DateTimeImmutable $now, bool $recurrence): array
    {
        mt_srand($recurrence ? 9191 : 9090);
        $events = [];
        $offsets = $recurrence ? [5 * 3600, 5 * 3600 - 1260] : [74 * 3600, 73 * 3600, 72 * 3600 + 900];
        foreach ($offsets as $i => $ago) {
            $t = $now->getTimestamp() - $ago;
            $events[] = $this->event($t, 'exception', 'error', 'shop', 'Connection to "smtp.acme.test:587" timed out.', 'TransportException', [
                'component' => 'mailer', 'queue' => ['message' => 'Symfony\Component\Mailer\Messenger\SendEmailMessage', 'operation' => 'handle'],
            ], [
                'class' => 'Symfony\Component\Mailer\Exception\TransportException',
                'message' => 'Connection to "smtp.acme.test:587" timed out.',
                'frames' => [
                    ['class' => 'Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream', 'function' => 'initialize', 'file' => 'vendor/symfony/mailer/Transport/Smtp/Stream/SocketStream.php', 'line' => 154, 'in_app' => false],
                    ['class' => 'App\Notification\OrderConfirmationMailer', 'function' => 'send', 'file' => 'src/Notification/OrderConfirmationMailer.php', 'line' => 33, 'in_app' => true],
                ],
            ]);
        }

        return $events;
    }

    /**
     * One request trace: root http_request span, controller span, child spans, optional exception, http_response.
     *
     * @param list<array{0: string, 1: string, 2: float, 3: array<string, mixed>}> $children
     * @param array<string, mixed>|null                                          $exception
     *
     * @return list<array<string, mixed>>
     */
    private function request(float $start, string $method, string $path, string $route, int $status, array $children, ?array $exception = null, string $release = '1.3.2'): array
    {
        $trace = bin2hex(pack('N4', mt_rand(), mt_rand(), mt_rand(), ++$this->seq));
        $request = 'req_'.substr($trace, 0, 16);
        $root = $this->spanId();
        $controller = $this->spanId();
        $cursor = $start + 0.004;
        $total = 0.0;
        $events = [];

        foreach ($children as [$type, $name, $duration, $context]) {
            $cursor += 0.002;
            $events[] = $this->base($cursor, $type, $type === 'exception' ? 'error' : 'info', 'shop', $name, $name, $context, $trace, $request, $this->spanId(), $controller, $duration, $release);
            $cursor += $duration / 1000;
            $total += $duration + 2;
        }
        $failed = $status >= 500;
        if (null !== $exception) {
            $cursor += 0.001;
            $events[] = $this->base($cursor, 'exception', 'error', 'shop', $exception['message'], substr($exception['class'], (int) strrpos($exception['class'], '\\') + 1), ['component' => 'exception', 'http' => ['status' => $status]], $trace, $request, $this->spanId(), $controller, null, $release) + ['exception' => $exception];
        }
        $controllerMs = $total + 6;
        $rootMs = $controllerMs + 9;
        array_unshift($events,
            $this->base($start, 'http_request', $failed ? 'error' : 'info', 'shop', $method.' '.$path, $method.' '.$path, ['component' => 'http', 'http' => ['method' => $method, 'path' => $path, 'route' => $route, 'status' => $status]] + ($failed ? ['status' => 'error'] : []), $trace, $request, $root, null, $rootMs, $release),
            $this->base($start + 0.003, 'controller', $failed ? 'error' : 'info', 'shop', 'App\Controller\\'.ucfirst($route).'Controller::__invoke', 'App\Controller\\'.ucfirst($route).'Controller::__invoke', ['component' => 'controller'], $trace, $request, $controller, $root, $controllerMs, $release),
        );
        $events[] = $this->base($start + $rootMs / 1000, 'http_response', $failed ? 'error' : 'info', 'shop', \sprintf('%d %s %s', $status, $method, $path), 'HTTP '.$status, ['component' => 'http', 'http' => ['status' => $status, 'method' => $method, 'path' => $path]], $trace, $request, $this->spanId(), $root, null, $release);

        return $events;
    }

    /**
     * @param array<string, mixed>      $context
     * @param array<string, mixed>|null $exception
     *
     * @return array<string, mixed>
     */
    private function event(float $t, string $type, string $severity, string $service, string $message, string $name, array $context, ?array $exception = null): array
    {
        $trace = bin2hex(pack('N4', mt_rand(), mt_rand(), mt_rand(), ++$this->seq));
        $event = $this->base($t, $type, $severity, $service, $message, $name, $context, $trace, null, $this->spanId(), null, null, '1.3.2');
        if (null !== $exception) {
            $event['exception'] = $exception;
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function base(float $t, string $type, string $severity, string $service, string $message, string $name, array $context, string $trace, ?string $request, string $span, ?string $parent, ?float $duration, string $release): array
    {
        return array_filter([
            'id' => 'seed-'.substr(hash('sha256', $trace.$span), 0, 24),
            'timestamp' => \DateTimeImmutable::createFromFormat('U.u', number_format($t, 6, '.', ''))->format('Y-m-d\TH:i:s.u\Z'),
            'type' => $type,
            'severity' => $severity,
            'service' => $service,
            'name' => $name,
            'message' => $message,
            'release' => $release,
            'trace_id' => $trace,
            'request_id' => $request,
            'span_id' => $span,
            'parent_span_id' => $parent,
            'duration_ms' => null !== $duration ? round($duration, 3) : null,
            'context' => $context + ['seeded' => true],
        ], static fn ($v): bool => null !== $v);
    }

    private function spanId(): string
    {
        return bin2hex(pack('N2', mt_rand(), ++$this->seq));
    }
}
