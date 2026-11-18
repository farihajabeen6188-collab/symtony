# Ingestion API

`POST /api/v1/events`: send one event, or `{"events": [ … ]}` with up to 500 events.

## Authentication

Connect an application in SYMTONY (**Applications → Connect application**) to get a token. It is shown
once. Send it on every request:

```
Authorization: Bearer sym_live_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
Content-Type: application/json
```

Optional: `X-Symtony-Source: <name>` labels where events came from (default `http`).

## Event

The full JSON Schema is [event-schema.json](event-schema.json). Only `timestamp` and a `message` (or an
exception message, or a `name`) are required.

```json
{
  "id": "evt_01HZX…",
  "timestamp": "2026-11-18T10:00:00.123456Z",
  "severity": "error",
  "type": "db_query",
  "service": "shop",
  "name": "UPDATE product",
  "message": "SQLSTATE[23000]: CHECK constraint failed: stock_non_negative",
  "release": "1.4.0",
  "trace_id": "4bf92f3577b34da6a3ce929d0e0e4736",
  "span_id": "00f067aa0ba902b7",
  "parent_span_id": "a3ce929d0e0e4736",
  "request_id": "req_c5b9271287d71b41",
  "duration_ms": 0.9,
  "exception": {
    "class": "Doctrine\\DBAL\\Exception\\DriverException",
    "message": "An exception occurred while executing a query …",
    "frames": [{ "class": "App\\Catalog\\ProductRepository", "function": "decrementStock", "file": "src/Catalog/ProductRepository.php", "line": 41, "in_app": true }]
  },
  "context": { "db": { "operation": "UPDATE", "table": "product", "statement": "UPDATE product SET stock = stock - ? WHERE sku = ?" } }
}
```

- `severity` accepts Monolog names or numeric levels (`level` is an alias).
- `type` defaults to `exception` when an exception is present, otherwise `log`.
- Re-sending the same `id` for the same application is ignored (idempotent).
- Timestamps more than 5 minutes in the future are rejected.

## Responses

`202 Accepted` with what happened:

```json
{ "accepted": 1, "duplicates": 0, "rejected": [], "redacted_values": 2, "event_ids": ["01a1…"] }
```

Invalid events in a batch are reported per index in `rejected`; valid ones are still stored. When nothing
was accepted (and nothing was a duplicate) the status is `422` with the same body.

Errors use RFC 9457 problem details (`application/problem+json`):

| Status | `type` | Meaning |
|---|---|---|
| 400 | `malformed_json` | The body is not valid JSON |
| 401 | `missing_token`, `invalid_token` | No token, or an unknown or revoked one |
| 413 | `payload_too_large`, `batch_too_large` | Body over 1 MB, or more than 500 events |
| 422 | `invalid_body`, `invalid_batch`, `empty_batch` | Not an event object or `{"events": [...]}` |
| 429 | `rate_limited` | Over the per-token limit (bursts of 200, refilling 100 per 10 s); see `Retry-After` |

## What happens next

Events are redacted (sensitive keys and values are replaced), normalized and stored, then grouped into
incidents in the background. Open **Event Explorer** to see them arrive, or **Incidents** for failures.
