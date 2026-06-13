# Changelog — Spart for WooCommerce

All notable changes to the `spart-woocommerce` plugin are documented in
this file. Format follows [Keep a Changelog](https://keepachangelog.com/),
versions follow [Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- **Live per-payee payment status on the order details page.** The **Spart
  payees** meta box now reflects each payee's *current* payment status as
  webhook events arrive, instead of freezing the status captured at
  `order.created`. Status is derived from the per-part authorize/capture/
  release timestamps and only ever advances, so out-of-order or replayed
  deliveries can never downgrade a payee's status. Each `payment.authorized`
  and the new `order.payment_part_released` event patches just the affected
  payee. Statuses are shown using a merchant-friendly collapsed vocabulary —
  **Pending** (grey), **Paid** (green), **Canceled** (amber) — rather than the
  raw internal status string. The payee name and email are shown as provided
  by the Spart server, which owns any redaction policy.

- **Friendly Spart fee label.** The `SPART_PAYEE_FEE` fee key is now displayed
  as **Spart! Fee** in the payees meta box.

- **Destroy-on-failure for the WooCommerce gateway.** When a Spart checkout
  attempt fails (network, validation, auth, server, or timeout), the plugin
  now destroys the pending WooCommerce order that was created moments earlier
  as part of the checkout POST. Managed stock is restored and any applied
  coupons have their `usage_count` decremented. The shopper is returned to
  the checkout page with the same friendly error notice as before — the
  merchant's order list no longer accumulates orphan "pending payment" rows
  from failed Spart attempts.
- **Always-on WARNING/ERROR logging.** Failed Spart checkouts are now
  always recorded in `wc-logs/spart-*.log` (visible from **WooCommerce →
  Status → Logs**) as WARNING/ERROR lines, regardless of the verbose-logging
  setting, so support can reconstruct any failed checkout. Each line carries
  a stable `correlation_id`, the `order_id`, and a `failure_code` token
  identifying the failure kind (e.g. `timeout`, `server_error`,
  `auth_failed`, `validation`, `missing_api_key`). INFO/DEBUG trace lines
  around each attempt remain gated on the Verbose logging setting. See the
  **Changed** section below for the full `failure_code` vocabulary.
- **"API endpoint" diagnostic label** in **WooCommerce → Settings → Payments → Spart**, always visible. Shows the currently-resolved Spart API base URL and its source. The source is one of: `Live default`, `Sandbox default`, `WP_SPART_BASE_URL constant`, or `Live default (unrecognised env: <value>)` when the saved environment is neither `live` nor `sandbox`. Read-only; no input field, no override behaviour change. (Previously gated on `WP_DEBUG === true`; the gate has been removed so merchants can confirm at a glance which Spart API their site is talking to without having to flip a debug constant.)
- **Plain-permalink-aware webhook URL.** The webhook receiver URL surfaced in **WooCommerce → Settings → Payments → Spart** (the `webhook_url` field) and in the post-upgrade migration notice now adapts to the site's permalink structure. Sites using **Settings → Permalinks → Plain** see the `?rest_route=/spart/v1/webhook` form; sites with pretty permalinks continue to see `/wp-json/spart/v1/webhook`. Previously both call sites hand-rolled `home_url('/wp-json/spart/v1/webhook')`, which silently produced a 404-at-the-web-server URL on hosts that don't run mod_rewrite (a common configuration on shared/free hosting and the default in wp-env's test container). The fix delegates URL construction to WordPress's built-in `rest_url()`, which inspects `permalink_structure` and picks the correct form. No merchant action is required: on next page load the gateway settings will redisplay the correct URL for the site, and `enforce_schema_invariants()` will overwrite any stale stored value on the next settings save.
- Integration test `tests/integration/Settings/GatewaySettingsSaveTest.php` covering the admin POST → save → reload round-trip. Closes the test gap that allowed the persistence regression to ship.
- **`spart_wc_order_destroyed` action hook.** Fires immediately after a
  successful `dispose()` delete and before the best-effort coupon-release
  and stock-restore cleanup. Signature: `do_action( 'spart_wc_order_destroyed',
  int $order_id, string $failure_code, string $correlation_id )`. Subscribe
  to emit destruction metrics, forward to an audit log, or correlate with
  failure dashboards. (Existing `spart_wc_order_disposing` hook continues to
  fire at the start of `dispose()`.)
- **One-shot upgrade notice for the destroy-on-failure behavior change.** On
  the first admin page load after upgrading to this release, existing
  merchants see a dismissible `notice-info` admin notice explaining that
  failed Spart checkouts now destroy their pending orders and pointing them
  at the WooCommerce log viewer (`spart-*` sources) for the failure trace.
  Fresh installs do not see the notice — the destroy behavior is the
  documented default they signed up for.
- **Merchant-configurable checkout window.** A new **Default checkout window
  (minutes)** setting on **WooCommerce → Settings → Payments → Spart** lets
  merchants choose how long a Spart checkout stays valid before it expires.
  Minimum 5 minutes; default 7 days (10080 minutes). Below-minimum values
  entered in the settings form are clamped back to the default on save.
  Replaces the previous hardcoded 15-minute checkout window.
- **Cart messaging now announces via `aria-live="polite"`.** Assistive
  tech now hears the Spart messaging when the cart mutates (quantity
  changes, coupon application, shipping recalc) without interrupting
  the shopper's current focus. The product-page messaging remains a
  static surface — no aria-live attribute is emitted there.

### Changed

- **Friendlier checkout-window setting.** The single "Default checkout window
  (minutes)" box in **WooCommerce → Settings → Payments → Spart** is replaced
  by a single **Max order duration** row holding three compact **Days / Hours /
  Minutes** number inputs side by side, each with its own label, plus a help
  tooltip explaining the setting. The combined window is validated on save to
  be between **5 minutes and 7 days**; an out-of-range combination is rejected
  with an admin error and the previously-saved window is kept. Existing
  installs are seeded automatically from their stored value (shown decomposed
  into days/hours/minutes) and keep working until the next save. The duration
  sent to Spart (`default_order_duration_minutes`) is unchanged — it is now
  derived from the three fields — and the checkout builder defensively clamps
  it to the same 5-minute…7-day range.
- **"Debug logging" setting renamed to "Verbose logging".** WARNING and
  ERROR messages are always written now; this setting only gates the
  additional INFO and DEBUG checkout trace lines. The stored option key
  stays `debug_logging` so existing merchant configurations keep working.
- **Checkout log `event` keys aligned with the spec.** The successful
  delete line now uses `event=spart_order_deleted` (was
  `spart_wc_order_disposed`), and three new INFO lines are emitted during
  disposal: `spart_order_disposing`, `spart_coupons_released`,
  `spart_stock_restored`. The redundant `spart_checkout_failed_summary`
  WARNING is gone — `CheckoutSession` already emits the canonical
  `spart_checkout_failed` line. External dashboards / log queries that
  filter on the old `spart_wc_order_disposed` event need updating.
- **`failure_code` log values are now stable lowercase tokens.** Each
  failed-checkout log line still carries a `failure_code` field, but the
  value is now a snake_case token (e.g. `timeout`, `auth_failed`,
  `server_error`, `validation`, `missing_api_key`) rather than the
  originating PHP exception's short class name. The new tokens survive
  SDK refactors and read better in log dashboards. External alerting
  that pattern-matches on `failure_code=SpartTimeoutException` etc. must
  be updated to the lowercase tokens — see
  `Spart\WooCommerce\Checkout\FailureCode` for the closed list.
- **Defense-in-depth: disposer skips non-pending orders.** If the order
  passed to the disposer has already moved off `pending` status (a
  parallel admin or webhook action between checkout submit and disposer
  invocation), the disposer bails without touching it and writes an
  INFO `spart_disposal_skipped` log line including the current status.
  Prevents the disposer from undoing other code paths' work.
- **Delete-first disposal ordering.** The disposer now deletes the
  pending order BEFORE releasing coupons or restoring stock. If the
  delete fails, coupons and stock allocations are left untouched so the
  still-pending order remains consistent for merchant retry — instead of
  the previous order where coupons + stock were reversed before delete,
  which (on the rare delete-failure path) left a pending order with
  already-released coupons and already-reverted stock. The
  `spart_order_deleted` log line now fires immediately after the
  successful delete (before the cleanup steps); `spart_coupons_released`
  and `spart_stock_restored` follow as best-effort post-delete events.
- **Idempotent disposer via `_spart_disposer_ran` meta marker.** Before
  the destructive sequence the disposer now persists a
  `_spart_disposer_ran=1` post meta and saves the order. On the success
  path `delete(true)` wipes the row so the marker has no lasting effect.
  On a delete-failure or mid-sequence throw, the marker survives in the
  still-pending order and any future invocation on the same order short-
  circuits with an INFO `spart_disposal_skipped` log (`reason=already_ran`).
  Closes the double-dispose hazard in which a retry path (form re-submit,
  request retry, programmatic recall) could double-emit logs or
  re-release coupons / re-restore stock if the previous run had
  partially succeeded before failing.
- API key and webhook secret fields now render a fixed-shape sentinel (`XXXX••••••••XXXX`) into the input's `value` attribute when a secret is stored, instead of a blank input. The browser still displays this as bullets — because the field is `type=password` — but the sentinel makes the field non-empty so a save without retyping is treated as "keep the stored secret" rather than "clear it". Submitting an explicit empty string still clears the stored value. Edits to the visible mask are rejected with a clear admin notice so the rendered bullets cannot accidentally overwrite the stored secret.
- **Messaging copy paths consolidated through a shared renderer.** The
  cart and product messaging surfaces now share a single
  `MessagingRenderer::render()` static so the BEM markup
  (`.spart-messaging`, `.spart-messaging--{ctx}`, `.spart-messaging__line`)
  stays consistent across surfaces. The block editor previews now read
  their text from `wp_localize_script` so translator-supplied per-locale
  .mo overrides land in the editor exactly as they land on the
  storefront.
- **Internal cleanup: option keys, toggle keys, asset handles, block
  names, and symbolic copy codes now live on a single
  `Spart\WooCommerce\Constants` class.** No merchant-visible behaviour
  change. The Spart gettext filter also runs at `PHP_INT_MAX - 100` so
  other plugins' gettext filters running at reasonable priorities have
  already executed by the time we see the translated value, and emits a
  one-shot per-code WARNING log line when a third party overrides a
  `SPART_*` symbolic code (helps translators verify per-locale overrides
  are landing).
- **Admin notice when WooCommerce is below 8.0.** Spart messaging blocks
  rely on `register_block_type` with `render_callback`, which is only
  reliably honoured by the WC Blocks subsystem in WC 8.0+. Sites on
  older WC versions now see a dismissible admin notice naming both the
  installed and required versions.
- **Messaging block editor payload deferred to admin-only context, and
  shared renderer hardens HTML attribute escaping.** Panel-code-review
  follow-ups: `MessagingRenderer::render()` now wraps the `$context`
  and `$aria_live` parameters with `esc_attr()` (defense-in-depth — all
  current call sites pass safe literals, but the public-static utility
  is now fail-safe against future dynamic callers); and the
  `wp_localize_script` call for the block editor preview payload moved
  from the `init` hook to `enqueue_block_editor_assets`, so the four
  `__()` lookups inside `MessagingEditorPayload::build()` no longer run
  on every front-end pageview, REST request, AJAX call, or cron tick.

### Security

- **Disposer error log redacts API key fragments.** When the destructive disposal sequence throws (e.g. a `wc_increase_stock_levels` or `wc_release_coupons_for_order` exception that internally surfaces a transport-layer error containing the merchant's Spart API key), the catch block now routes the exception through `Spart\WooCommerce\Logging\ErrorSanitizer` instead of emitting the raw `$e->getMessage()`. The key — if present in the message — is replaced with `<redacted>` and the message is truncated to 500 characters. The disposer constructor now takes a `\Closure` of shape `(): string` returning the currently-configured API key, invoked on each disposal failure so a rotated key takes effect without rebuilding the disposer.
- **Coupon release suppressed for shopper-controllable failure codes.** When a checkout fails with `failure_code` of `free_order`, `validation`, `missing_api_key`, or `malformed` (i.e. a category a shopper can deliberately trigger by manipulating cart state — `malformed` was added in the panel-review pass after auditing `CheckoutSession`'s `\InvalidArgumentException` catch arm and confirming the SDK's model constructors receive shopper-influenceable inputs), the disposer now skips `wc_release_coupons_for_order()` and instead emits a `spart_coupons_release_skipped` INFO log line with `reason=shopper_controllable_failure_code`. Closes a coupon-abuse vector in which a malicious shopper could infinitely re-apply a single-use 100%-off coupon by repeatedly engineering one of these failures (each disposal would otherwise decrement the coupon's `usage_count` back to allow re-application). Stock restoration and order deletion still run for these codes — only the coupon release is gated, because stock-hold is a temporary inventory concern with no monetary abuse vector. Trade-off accepted: a merchant who ships their site with a missing API key will permanently consume legitimate shoppers' single-use coupons until they fix the configuration, but the alternative (per-shopper coupon abuse on a correctly-configured merchant) is the worse failure mode in real impact.
- **Hostile/buggy action subscribers cannot suppress disposer cleanup.** The two public action hooks fired during disposal (`spart_wc_order_disposing` at entry and `spart_wc_order_destroyed` immediately after a successful delete) are now invoked through an internal `safe_do_action()` helper that catches any `\Throwable` raised by a subscriber, sanitises it via `ErrorSanitizer`, and logs it at ERROR with `event=spart_disposal_failed` and the offending `hook=` key in the context. Without this guard, a third-party plugin throwing from `spart_wc_order_destroyed` would skip the subsequent coupon release and stock restore — leaking coupon `usage_count` and managed stock for a row that has already been deleted, the exact inverse of the feature's purpose.
- **Admin upgrade notice no longer rendered to users who cannot dismiss it.** `DestroyOrdersUpgradeNotice::render()` now short-circuits before emitting HTML when the current user lacks `manage_woocommerce`, and `handle_dismiss()` checks `manage_woocommerce` instead of `manage_options`. WooCommerce shop managers (the WC primary admin role) have `manage_woocommerce` but not `manage_options`, so the previous check trapped them: they could see the notice but every dismiss click returned `wp_die(403)`, leaving the notice stuck on every admin page load. Low-privilege users visiting `/wp-admin/profile.php` no longer see a merchant-targeted notice either — the prior dismiss link was already non-functional for them (`check_admin_referer()` would reject a session-bound nonce minted for the wrong user) but the visual clutter was confusing. (`WebhookUrlMigrationNotice` carries the same pre-existing `manage_options` issue and is deliberately left as-is — out of scope for this branch.)
- **Webhook log lines carry the original checkout `correlation_id`.** On successful intent creation, `CheckoutSession` now stamps the request-scoped `correlation_id` onto the order as `_spart_correlation_id` post meta (immediately after the `spart_intent_created` INFO log, before returning). When a Spart webhook later arrives for the order (`intent.created`, an unexpected `webhook.test`, or an unknown future event type), `Webhooks\OrderSync` reads the meta back and includes `correlation_id` in the corresponding `webhook.intent.created` / `webhook.ordersync.unexpected_test_event` / `webhook.ordersync.unknown_event_type` log line. Missing meta is tolerated silently (e.g. an `intent.created` webhook racing ahead of the order->save, or an out-of-band order edit that wiped the meta); the log line just omits the `correlation_id` key in that case. This unblocks correlation between checkout-time failures (logged synchronously by the gateway) and asynchronous webhook events that operate on the same order — without requiring an SDK-side metadata round-trip.
- **"Verbose logging" toggle takes effect immediately.** `LevelFilteredLogger` now reads the verbose state via an injected callable on every INFO/DEBUG emit instead of capturing a bool at construction. The Plugin-level logger singleton can therefore stay alive across the entire request (and across the static-lifetime span between cache resets), with each emit reflecting the current `woocommerce_spart_settings.debug_logging` option. Previously, flipping the admin toggle had no effect on a pre-built singleton — the merchant either had to wait for the next request boundary or rebuild the logger manually.
- **`OrderDisposer` is `final` again, with `OrderDisposerInterface` as the test seam.** Extracted `Spart\WooCommerce\Checkout\OrderDisposerInterface` (single method `dispose()`); `OrderDisposer` now `implements` it and is declared `final`. `Plugin::order_disposer()` is typed against the interface, and a new `Plugin::set_order_disposer_for_tests(?OrderDisposerInterface $disposer)` test-only seam replaces the previous reflection-based `inject_disposer()` helper. The previous arrangement (concrete class, not-final, swapped via Mockery + reflection in tests) was a workaround that violated the "production code is closed for subclassing" invariant; the interface seam restores that invariant while keeping the test ergonomic.
- Environment field is now double-sanitised: `Field::sanitize()` clamps any submitted value through the disabled-select guard, and the `enforce_schema_invariants` filter (formerly `inject_webhook_url`) applies `Schema::sanitize()` before injecting the read-only webhook URL. The legacy `validate_environment_field()` shim — which relied solely on a one-line equality check and was not covered by tests — has been retired.

### Fixed

- Settings page now correctly persists edits to all gateway fields. The previous `process_admin_options()` override read the WC form as a single nested array (`$_POST['woocommerce_spart_settings']`), but WooCommerce posts each field as a flat top-level key (`woocommerce_spart_<field_id>`). The override has been removed; the gateway now delegates to WooCommerce's parent implementation and exposes a `validate_password_field()` override that preserves stored secrets across saves.

## 0.5.0 — 2026-05-15

### Added
- Server-side rendered Spart promotional messaging on WooCommerce single-product pages, displayed immediately after the price.
- Server-side rendered Spart promotional messaging on the WooCommerce cart page, displayed before the cart totals.
- Two independent admin toggles in WooCommerce → Settings → Payments → Spart: "Show Spart messaging on product pages" and "Show Spart messaging on cart page". Both default to off.
- Two new Gutenberg blocks (`spart/product-messaging`, `spart/cart-messaging`) using the same render callbacks, enabling block-theme placement.
- Shared `assets/css/spart.css` stylesheet enqueued on front-end pages where messaging is displayed.

### Changed
- Setting field count increased from 8 to 10.

## 0.4.0 — 2026-05-15

### Added

- **WooCommerce Cart/Checkout Blocks support:** the Spart gateway now
  registers with WC Blocks' payment-method registry via a thin
  `SpartBlocksSupport` integration class, so customers on Block-based
  checkouts see a "Pay with Spart" radio option (label, description and
  shipped logo) alongside their other payment methods. The Place Order
  click reuses the existing `process_payment` redirect flow, so all
  PR2 API/error/timeout coverage applies to Blocks too.
- **Customer return-from-Spart messaging:** on the `/checkout/order-received/`
  page (rendered by both classic and Block checkout), a new
  `ThankYouRenderer` hooks `woocommerce_thankyou_spart` and renders a
  translatable "Your payment is being processed by Spart…" placeholder
  while the order is still `pending`/`on-hold`. Once the webhook flips
  the order to `processing`/`completed`/`failed`, the renderer gets out
  of WC's way and lets the standard thank-you template take over.
- **Blocks payment-method registration script:** a 30-line plain-IIFE
  `assets/js/blocks-checkout.js` is shipped verbatim (no
  `@wordpress/scripts` build pipeline) and registered with WP via
  `wp_register_script` + `wp_set_script_translations` against the
  `spart-woocommerce` text domain. The `Schema::defaults()` helper
  ensures Blocks sees the same merchant title/description fallbacks
  that the classic gateway gets via WC's lazy `get_option()` defaulting,
  even when `woocommerce_spart_settings` is partially populated by
  WP-CLI/migration.
- **Logo asset:** placeholder Spart wordmark SVG ships at
  `assets/images/spart-logo.svg`. Merchant brand asset swaps in later
  at the same path.

### Changed

- **Default `description` field copy** updated from PR2's placeholder
  `"Split your purchase into installments with Spart."` to
  `"Split the payment with your friends!"`. Merchants who saved the
  previous default keep their stored value; the change only affects
  the form default for fresh installs.
- `Plugin::VERSION` and the `spart-woocommerce.php` plugin header
  bumped from `0.3.0` to `0.4.0`.

## 0.3.0 — 2026-05-14

### Added

- **Webhook receiver:** the `POST /wp-json/spart/v1/webhook` endpoint
  is now live. It verifies the `X-Spart-Signature` HMAC header,
  resolves the WC order from the envelope `sessionId`, and dispatches
  to the appropriate `OrderSync` handler for `order.completed`,
  `order.canceled`, `order.expired`, `payment.authorized`,
  `intent.created`, and `webhook.test` event types.
- **Idempotent delivery:** every accepted delivery is recorded in a
  new `{prefix}spart_webhook_deliveries` table. Replays of the same
  `delivery_id` short-circuit to `200 {deduped: true}` without
  re-mutating the order; on first-attempt failure, the row stays in
  `received` state so a retry transparently re-enters the dispatch
  path and bumps `attempt_count`.
- **Daily housekeeping:** a `spart_webhook_cleanup` WP-Cron event
  (registered via `wp_schedule_event` at activation, cleared at
  deactivation) deletes dedupe rows older than 30 days.
- **Webhook URL migration notice:** PR2's gateway shipped with a
  legacy `wc-api/spart_webhook` placeholder URL that was never wired.
  This release surfaces a one-time admin notice on plugin upgrade
  pointing merchants to the new `/wp-json/spart/v1/webhook` URL so
  they can update their Spart dashboard configuration; the notice
  dismisses itself after acknowledgement.

### Changed

- `Plugin::VERSION` and the `spart-woocommerce.php` plugin header
  bumped from `0.2.0` to `0.3.0`.

### Schema

- New table `{prefix}spart_webhook_deliveries` (created via
  `dbDelta` on activation; uses the `received_at` index for the
  daily cleanup). No changes to existing tables.

## 0.2.0

- WooCommerce gateway scaffolding: registers `spart` payment gateway,
  composes Spart `sessionId` per checkout, and handles checkout
  redirect to Spart's hosted payment page.

## 0.1.0

- Initial plugin scaffolding: PSR-4 autoload, activation/deactivation
  lifecycle, settings page, and HPOS compatibility declaration.
