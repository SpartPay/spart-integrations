# stub-spart

Minimal PHP-built-in-server replica of the Spart Intents API for integration testing.

The real Spart API is the source of truth; this stub mirrors its **wire envelope** so the WooCommerce plugin can be exercised end-to-end inside `@wordpress/env` without depending on `dotnet run` or a network round-trip. Real-API E2E coverage lives in PR6 (`wc-plugin-webhook-e2e`).

## Run locally

```bash
cd woocommerce/tests/integration/stub-spart
php -S 0.0.0.0:8080 router.php
```

In CI / @wordpress/env: started by `tests/integration/docker-compose.override.yml` as a sidecar named `stub-spart` on the `@wordpress/env` Docker network. WP can reach it as `http://stub-spart:8080`.

## Wire contract

`POST /api/intents` returns the SDK's `Result<T>` envelope:

```json
{
  "isSuccessful": true,
  "value": { "intentShortId": "stubabc", "checkoutUrl": "http://stub-spart:8080/checkout/stubabc" },
  "error": null,
  "errorDetails": []
}
```

Failure envelope drops `value` and populates `error` plus optional `errorDetails`.

## Environment

The stub-spart sidecar accepts the following environment variables:

| Variable         | Default              | Purpose |
|------------------|----------------------|---------|
| `WC_TARGET_URL`  | `http://tests-wordpress` | Base URL for webhooks stub-spart posts back to WordPress. Accessible on wp-env's tests network; override via `STUB_SPART_WC_TARGET_URL` when running outside standard wp-env layout. |

## Test control plane

| Endpoint                | Body                       | Effect                              |
|-------------------------|----------------------------|-------------------------------------|
| `POST /__stub/scenario` | `{"scenario":"happy","signing_secret":"whsec_..."}` | Switch active scenario; `signing_secret` is optional and persists across calls when omitted |
| `POST /__stub/reset`           | —                          | Clear state and recorded requests   |
| `POST /__stub/deliver-webhook` | `{"event_type":"order.completed","payload":{...},"attempt":1}` | Sign + POST a webhook envelope to `${WC_TARGET_URL}/wp-json/spart/v1/webhook`; record the delivery; return `{status, body, headers_sent, delivery_id}`. Uses signing_secret persisted by `/__stub/scenario` unless overridden in body. |
| `GET  /__stub/recorded`        | —                          | Dump recorded requests as JSON      |
| `GET  /__stub/health`   | —                          | Liveness check (`{"ok":true}`)      |

State (active scenario, recorded requests) lives in `/tmp/stub-spart-state.json` so it survives across the per-request lifecycle of the PHP built-in server.

## Scenarios

| Scenario    | Status | Behaviour                                                                  |
|-------------|--------|----------------------------------------------------------------------------|
| `happy`     | 201    | Success envelope (default)                                                 |
| `replay`    | 200    | Success envelope — simulates idempotent replay                             |
| `error_400` | 400    | Validation failure envelope                                                |
| `error_401` | 401    | Auth failure envelope                                                      |
| `error_500` | 500    | Server failure envelope                                                    |
| `timeout`   | —      | Sleeps 35s then returns success — forces the 30s WpHttpClient timeout      |
| `malformed` | 200    | Returns invalid JSON — exercises the SDK's parse-failure path              |

## Files

- `router.php` — entry point; route table.
- `scenarios.php` — `POST /api/intents` dispatch.
- `helpers.php` — JSON I/O helpers and envelope factories.
- `State.php` — JSON-file-backed singleton for scenario / recorded requests.
