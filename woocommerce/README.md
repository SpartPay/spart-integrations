# Spart for WooCommerce

Spart payment gateway plugin for WooCommerce.

## Local development

This plugin depends on the PHP SDK (`spart/sdk`), which lives in the **public
[`spartpay/spart-sdks`](https://github.com/spartpay/spart-sdks)** repository and
is consumed here through a Composer [path repository](https://getcomposer.org/doc/05-repositories.md#path)
(`../../spart-sdks/php` in `composer.json`).

Composer resolves that path relative to the **workspace parent**, so
`spartpay/spart-sdks` must be checked out as a **sibling** of this repository:

```text
<workspace>/
├── integrations/     # this repository (spartpay/integrations)
└── spart-sdks/       # public SDK repo (spartpay/spart-sdks)
```

Bootstrap it once before running `composer install`:

```bash
# from the parent directory that contains your `integrations/` checkout
git clone https://github.com/spartpay/spart-sdks.git
```

Then, from `woocommerce`:

```bash
composer install
```

If the sibling checkout is missing, `composer install` fails with a misleading
"Could not find package spart/sdk" error — the package exists, the sibling
*checkout* does not.

> CI checks out `spartpay/spart-sdks` automatically (pinned to a specific commit
> SHA). See [`.github/workflows/README.md`](../.github/workflows/README.md).

## Building a dev zip

`tools/build-dev-zip.sh` packages a smoke-test zip that vendors the SDK source
into the plugin (it does **not** rely on the path repo at runtime). It resolves
the SDK from the sibling checkout, or from an explicit `SDK_SRC=…/php` override.

## Diagnosing checkout latency

To capture one checkout trace:

1. In **WooCommerce → Settings → Payments → Spart**, enable **Verbose logging**.
2. Reproduce one slow checkout.
3. Open **WooCommerce → Status → Logs** and select the `spart` log.
4. Filter by the checkout's `correlation_id`.

The key events are:

- `spart_checkout_started`: time WooCommerce spent before the Spart gateway
  (`request_before_gateway_ms`);
- `spart_checkout_profile`: request construction, client creation, intent HTTP,
  order-save, and session-total timings;
- `spart_api_request_completed`: HTTP round-trip, outcome/status, and the
  backend's `api_trace_id`;
- `spart_checkout_succeeded`: on successful checkout only, gateway-total and
  whole-request timings.

For failed checkouts, use `spart_checkout_profile.session_total_ms`.

If `http_round_trip_ms` dominates, open `api_trace_id` in Application Insights
and compare the backend trace duration. Similar durations point to backend
execution; a large gap points to DNS, TLS, proxy, hosting, or network latency
between WordPress and Spart.

Disable **Verbose logging** after collecting the sample. This immediately stops
the INFO timing/trace events; warning and error logs remain enabled.
