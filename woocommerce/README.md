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
