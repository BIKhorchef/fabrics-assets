# Product Configurator for WooCommerce — BIGHOUSEMARKETING Edition

A maintained fork of [Product Configurator for WooCommerce](http://wc-product-configurator.com)
by Marc Lacroix, customised and extended by [BEN BIGHOUSEMARKETING](https://www.bighousemarketing.lu/)
for use on `fantinolux.com` and other BIGHOUSEMARKETING client sites.

This fork is **not** distributed via the WordPress.org plugin directory and
will **not** offer auto-updates from upstream. Updates are pulled from this
repository (see [Updating](#updating) below).

> **Upstream:** © 2015 Marc Lacroix — GPL v3.0
> **Modifications:** © BEN BIGHOUSEMARKETING — same GPL v3.0

---

## What's different from upstream

The headline differences compared to the upstream Marc Lacroix release:

| Area | Upstream | This fork |
|------|----------|-----------|
| Premium addons (Extra Price, Conditional Logic, Save Your Design, Multiple Choice, Stock Management, Form Fields, Advanced Description, Text Overlay) | Sold separately | Bundled and integrated |
| Conditional Logic action target | `Layer` / `Choice` only | Adds **`Choice group`** — a single rule cascades show/hide over a group header + every direct child choice |
| Conditional Logic engine init hook | `mkl-pc-configurator-loaded` / `PC.fe.configurator.started` (never fired) | Hooked on `PC.fe.start` (the real event) |
| Conditional Logic engine reads conditions from | `PC.fe.config.conditions` (wrong location) | `PC.productData.prod_<id>.conditions` |
| Hidden choices in the side-menu list | Stay rendered | `change:cshow` listener removes them via the `is-conditionally-hidden` CSS class |
| Hidden group's active child | Keeps rendering its image on the canvas | Auto-deselected so the canvas stays consistent |
| Plugin author / branding | Marc Lacroix | BEN BIGHOUSEMARKETING (with original attribution preserved per GPL) |
| WordPress.org auto-updates | Active | Disabled via `Update URI` header + `site_transient_update_plugins` filter |

Full machine-level diff: see [CHANGELOG.md](CHANGELOG.md).

---

## Installation

1. Download the latest release ZIP from the [Releases](#) page (or
   `git clone` this repo).
2. Upload the folder to `wp-content/plugins/product-configurator-for-woocommerce/`.
   The folder name must stay `product-configurator-for-woocommerce` —
   lots of internal slugs and asset paths depend on it.
3. WordPress Admin → **Plugins** → activate
   *Product Configurator — BIGHOUSEMARKETING Edition*.
4. Activate WooCommerce first if you haven't (the plugin won't init
   without it).

### Requirements

* PHP **7.4+**
* WordPress **6.0+** (tested on 6.7)
* WooCommerce **8.0+** (tested up to 10)

---

## Configuration walkthroughs

* **Conditional Logic — Group cascade visibility** —
  full walkthrough with admin screenshots, the seven product-6349
  conditions, and the test plan:
  [CONDITIONAL-GROUP-VISIBILITY-PLAN.md](CONDITIONAL-GROUP-VISIBILITY-PLAN.md).

* **Special layer types × common options** — compatibility audit and
  manual test plan covering Attribute, Option Selector, Text Overlay,
  Note layer types against `hide_in_cart` / `hide_in_summary` /
  `hide_in_configurator` / `required` / `is_group` /
  `show_group_label_in_cart` / `choice_groups_toggle`, plus the
  Export / Import round-trip:
  [SPECIAL-LAYERS-COMPATIBILITY-PLAN.md](SPECIAL-LAYERS-COMPATIBILITY-PLAN.md).

* **Premium addons reference** —
  [PREMIUM-ADDONS-ENABLED.md](PREMIUM-ADDONS-ENABLED.md),
  [OPTION-SELECTOR-ADDON.md](OPTION-SELECTOR-ADDON.md),
  [CONDITIONAL-LOGIC-ADDON.md](CONDITIONAL-LOGIC-ADDON.md),
  [QUICK-START-GUIDE.md](QUICK-START-GUIDE.md).

---

## Updating

Because this fork is cut off from wordpress.org, updating is a manual
or git-based operation.

### Option A — `git pull` in place (recommended for staging / dev)

```bash
cd wp-content/plugins/product-configurator-for-woocommerce/
git pull origin main
```

WordPress will detect the new file mtime and reload the cached JS /
CSS thanks to the `filemtime()` query strings the plugin attaches to
its enqueues. No DB migration is required for routine updates.

### Option B — replace the folder (safer for prod)

1. Download the release ZIP from the [Releases](#) page.
2. Take a backup of the current `product-configurator-for-woocommerce/`
   folder.
3. Replace the folder contents with the new ZIP. Keep the folder name.
4. Hard-refresh the front-end (Ctrl+F5) and the WP admin once.

### Verifying no upstream update banner appears

The plugin sets `Update URI: https://www.bighousemarketing.lu/plugins/product-configurator-bh/`
in its header and registers a `site_transient_update_plugins` filter
that strips this plugin from the update list. In **Plugins → All**
the plugin row should never show *"Update available"* — even if Marc
Lacroix releases a new upstream version.

If you ever want to **re-enable** upstream update checks (for example
to compare against an upstream fix), comment out the `Update URI` line
and the `mkl_pc_bh_block_wp_org_update` filter in
`woocommerce-mkl-product-configurator.php`, then visit *Dashboard →
Updates* in WP admin.

---

## Repository layout

```
.
├── woocommerce-mkl-product-configurator.php   # Plugin entry point + headers + update-block filter
├── inc/
│   ├── plugin.php                             # Plugin singleton; loads addons + base classes
│   ├── addon-loader.php                       # Loads the eight bundled addons
│   ├── addons/                                # Bundled premium addons
│   │   ├── conditional-logic.php              # ✱ Updated: target_type=group support
│   │   ├── extra-price.php
│   │   ├── form-builder.php
│   │   ├── multiple-choice.php
│   │   ├── note-layer.php
│   │   ├── option-selector.php
│   │   ├── save-your-design.php
│   │   ├── stock-management.php
│   │   └── text-overlay.php
│   ├── admin/                                 # Admin-side controllers + settings + views
│   ├── frontend/                              # Frontend cart / order / shop integration
│   ├── base/                                  # Layer / choice / angle / product base classes
│   ├── compatibility/                         # Theme & plugin compat shims
│   ├── themes/                                # Built-in configurator themes
│   ├── templates/                             # Frontend HTML templates
│   ├── cache.php                              # Per-product config-file cache
│   ├── db.php                                 # Sanitisation + meta storage
│   └── ...
├── assets/
│   ├── admin/                                 # Admin JS / CSS / images / templates
│   │   └── js/views/conditions.js             # ✱ Updated: group target dropdown
│   ├── js/
│   │   ├── addons/conditional-logic.js        # ✱ Updated: group cascade engine
│   │   └── views/configurator.{js,min.js}     # ✱ Updated: change:cshow listener
│   └── css/configurator-common.css            # ✱ Updated: is-conditionally-hidden rule
├── languages/
├── vendor/                                    # Composer deps (if any)
├── CHANGELOG.md                               # ✱ Fork-specific changelog (Keep a Changelog format)
├── README.md                                  # ✱ This file
├── CONDITIONAL-GROUP-VISIBILITY-PLAN.md       # ✱ Implementation plan / admin recipe
├── SPECIAL-LAYERS-COMPATIBILITY-PLAN.md       # ✱ Compatibility audit
├── ADDON-CHANGELOG.md                         # Pre-fork addon integration log
├── IMPLEMENTATION-SUMMARY.md                  # Pre-fork integration summary
├── changelog.txt                              # Upstream Marc Lacroix changelog
├── readme.txt                                 # WP-standard readme (upstream)
└── configurator-data--product-6349--with-conditions.json   # Importable seven-condition setup for product 6349
```

`✱` marks files that the BIGHOUSEMARKETING fork added or changed
relative to upstream.

---

## Versioning policy

We track the **upstream version** in `Version:` plus a fork suffix
`-bh.<n>`:

* `1.5.10-bh.1` — first BIGHOUSEMARKETING release.
* `1.5.10-bh.2` — bug fix release on the same upstream base.
* `1.5.11-bh.1` — first release after rebasing onto upstream `1.5.11`.

Bumps are applied in **two places** at the same time:

1. The `Version:` line in [woocommerce-mkl-product-configurator.php](woocommerce-mkl-product-configurator.php).
2. The `MKL_PC_VERSION` PHP constant in the same file.

Every release also gets:

* A new entry at the top of [CHANGELOG.md](CHANGELOG.md) (move
  `## [Unreleased]` content into the new version section, dated).
* A git tag `vX.Y.Z-bh.N` (annotated, signed if you have a key set up).
* A GitHub release with the `dist` ZIP attached.

---

## Building a release ZIP

The plugin is plain PHP / JS — there's no build step required for
the release artefact. To produce the ZIP that gets uploaded to a WP
site:

```bash
cd wp-content/plugins/
zip -r product-configurator-for-woocommerce-1.5.10-bh.1.zip \
    product-configurator-for-woocommerce \
    -x "product-configurator-for-woocommerce/.git/*" \
    -x "product-configurator-for-woocommerce/.github/*" \
    -x "product-configurator-for-woocommerce/node_modules/*" \
    -x "product-configurator-for-woocommerce/maps/*" \
    -x "product-configurator-for-woocommerce/configurator-data--*.json" \
    -x "product-configurator-for-woocommerce/tmpclaude-*" \
    -x "product-configurator-for-woocommerce/.DS_Store"
```

Adjust the version suffix to match the tag you're cutting.

---

## License

This fork is distributed under the **GNU General Public License
v3.0** — the same license as the upstream Marc Lacroix release. See
the `License` and `License URI` headers in
[woocommerce-mkl-product-configurator.php](woocommerce-mkl-product-configurator.php),
or the GPL text at <https://www.gnu.org/licenses/gpl-3.0.html>.

The original copyright notice (`© 2015 mklacroix`) has been preserved
in the plugin header per § 4 of the GPL. Modifications carry an
additional `© BEN BIGHOUSEMARKETING` notice.

---

## Support

Issues, feature requests, and questions: open a ticket on the
[issue tracker](#) of this repository, or contact
<contact@bighousemarketing.lu>.
