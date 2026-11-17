# symtony/symfony-bundle

Sends what your Symfony app does — requests, controllers, Doctrine queries and transactions, outgoing HTTP calls,
cache reads, Messenger messages, exceptions and logs — to [SYMTONY](../../README.md) as one linked trace per request,
so failures can be reconstructed and investigated.

It never slows down or breaks your app: events are buffered and delivered **after the response is sent**,
with a 2-second timeout, and delivery errors are logged once to your own log (channel `symtony`) and swallowed.

## Install

Not on Packagist yet. From a clone of SYMTONY:

```bash
composer config repositories.symtony path /path/to/symtony/packages/symtony-bundle
composer require symtony/symfony-bundle:@dev
```

```yaml
# config/packages/symtony.yaml
symtony:
    endpoint: '%env(SYMTONY_ENDPOINT)%'   # https://your-symtony/api/v1/events
    token: '%env(SYMTONY_TOKEN)%'         # sym_… (keep it in .env.local)
    service: 'shop'
    release: '%env(default::APP_RELEASE)%'
```

Forward warnings and errors from Monolog (linked to the current span):

```yaml
monolog:
    handlers:
        symtony: { type: service, id: symtony.monolog_handler, channels: ['!event', '!symtony'] }
```

Mark deploys from your pipeline: `php bin/console symtony:release 1.4.0 --commit=$GIT_SHA`.

## What is captured

| Integration | Events | Notes |
|---|---|---|
| HttpKernel | `http_request` span, `controller` span, `http_response`, `exception` | Path only (no query string). Honors W3C `traceparent` and `X-Request-Id`; returns `X-Request-Id`. |
| Doctrine DBAL | `db_query` spans, `transaction` BEGIN/COMMIT/ROLLBACK | SQL is sanitized (literals → `?`); bound parameters are never captured. |
| HttpClient | `external_http` spans | Propagates `traceparent`; 5xx and transport errors mark the span failed. |
| Cache (`CacheInterface`) | `cache` spans with hit/miss and `age_seconds` | PSR-6 users of `cache.app` are untouched. |
| Messenger | `queue` dispatch/handle spans | Trace continues in the worker via `TraceStamp`. Add `symtony.messenger_middleware` to your bus. |
| Monolog | `log` / `exception` events | Exceptions already captured are not duplicated. |

Custom instrumentation:

```php
public function __construct(private Symtony\Tracer $tracer) {}

$this->tracer->span('InventoryService::reserve', fn () => $this->reserve($sku, $qty), context: ['inventory' => ['sku' => $sku]]);
```

Route options: `defaults: { _symtony_service: payment-api }` reports a route as another logical service;
`defaults: { _symtony_ignore: true }` records nothing for that request.

## Without Symfony (any Monolog app)

```php
$logger->pushHandler(new Symtony\Bridge\Monolog\SymtonyHandler(
    endpoint: 'https://your-symtony/api/v1/events',
    token: getenv('SYMTONY_TOKEN'),
    service: 'billing',
));
```

## Configuration reference

`enabled`, `endpoint`, `token`, `service`, `release`, `environment`, `timeout` (seconds, default 2),
`transport` (service id of a custom `TransportInterface`, e.g. an in-memory recorder in tests),
`capture.{http,doctrine,http_client,messenger,cache}` (all `true` by default).
