# Spart for WooCommerce

Spart payment gateway plugin for WooCommerce.

## Storefront messaging

Enable product and cart messages independently in **WooCommerce → Settings →
Payments → Spart**. Classic hooks and the `spart/product-messaging` and
`spart/cart-messaging` blocks share the same branded panels and information dialog.
The help button opens it; Escape or the close button returns focus without
selecting a payment method. The dialog scrolls on small screens and locks background scrolling.
All dialog text, including headings, uses its system sans-serif font rather than theme heading fonts.

Classic and Blocks checkout show only the bold shared-purchase headline and
SPART! wordmark beside WooCommerce's own selector. Legacy saved title/description
values no longer control checkout copy. Payment processing and loading effects are unchanged.

Italian storefront copy ships in `languages/spart-woocommerce-it_IT.po` and `.mo`;
other locales fall back to English. Standard gettext translations take precedence.
After editing the catalog, rebuild it with
`msgfmt --check -o languages/spart-woocommerce-it_IT.mo languages/spart-woocommerce-it_IT.po`.

## Optional checkout loading screen

In **WooCommerce → Settings → Payments → Spart**, the **Checkout loading screen**
section is on by default on fresh installs and upgrades without a saved preference.
An explicitly saved off setting stays off. The shared loading screen appears on
classic and Blocks checkout while Spart prepares payment.
The gateway must also be enabled. Cart, order-pay and order-received pages do not
load these assets; turning the toggle off preserves the existing checkout behavior.

Customize the six-digit hex backdrop color (default `#192a23`), integer opacity
(`0`–`100`, default `55`), and Media Library image. Raster images, including animated
GIF/WebP, are supported; SVG, nonimages and invalid attachments are rejected.
**Clear image** restores the built-in indicator (attachment ID `0`). Invalid color
or opacity values fall back to their defaults. Reduced-motion customers always
see the static built-in indicator instead of a custom image.

**Preview loading screen** uses unsaved values, even with the toggle off; close it
with **Close preview** or Escape. Saving uses WooCommerce's normal settings form.

Developer contract: `spart-checkout-loading` publishes `spartCheckoutLoadingConfig`
with `backdropColor`, `backdropOpacity`, `imageUrl`, `title` and `description`.
PHP translates display copy before localization. The classic adapter depends on
`jquery`, `wc-checkout` and the shared handle; Blocks adds only the shared dependency
when enabled. The admin adapter calls `spartCheckoutLoading.show()` with preview
and appearance overrides plus a translated `closeLabel`.

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
