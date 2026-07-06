# Changelog

All notable changes to the **BIGHOUSEMARKETING Edition** of *Product Configurator for WooCommerce* are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project uses [Semantic Versioning](https://semver.org/) with a fork suffix
(e.g. `1.5.10-bh.1`) where the base version mirrors the upstream Marc Lacroix
release we forked from.

> Upstream: [Product Configurator for WooCommerce](http://wc-product-configurator.com) © 2015 Marc Lacroix — GPL v3.0
> Fork & customisation: [BEN BIGHOUSEMARKETING](https://www.bighousemarketing.lu/)

---

## [Unreleased]

Reserved for changes that are committed to `main` but not yet tagged.

---

## [1.5.10-bh.1] — 2026-04-29

The first BIGHOUSEMARKETING release. Cuts the plugin off from
wordpress.org auto-updates, ships the conditional-logic group cascade
feature for product 6349, and rebrands the plugin header.

### Added

- **Update suppression** — added `Update URI` header and a defensive
  `site_transient_update_plugins` filter so the WP admin never offers
  an upstream Marc Lacroix update that would overwrite the fork. See
  [woocommerce-mkl-product-configurator.php](woocommerce-mkl-product-configurator.php).
- **Conditional Logic — `target_type=group` cascade** — a new "Choice
  group" target option in the *Conditional settings* admin lets one
  rule show/hide an entire group header and every direct child choice
  in one shot. Implemented end-to-end:
  - Admin template: [inc/addons/conditional-logic.php](inc/addons/conditional-logic.php)
    `tmpl-mkl-pc-condition-action-row` template.
  - Admin dropdown population: [assets/admin/js/views/conditions.js](assets/admin/js/views/conditions.js)
    `populate_target_elements()` group branch.
  - Frontend engine cascade: [assets/js/addons/conditional-logic.js](assets/js/addons/conditional-logic.js)
    `execute_action` / `apply_action_to_model` / `get_group_members`.
  - Choice item visibility: [assets/js/views/configurator.js](assets/js/views/configurator.js)
    + [assets/js/views/configurator.min.js](assets/js/views/configurator.min.js)
    `change:cshow` listener and `toggle_cshow` handler.
  - CSS rule: [assets/css/configurator-common.css](assets/css/configurator-common.css)
    `.layer_choices .choice.is-conditionally-hidden { display: none !important; }`.
- **Per-product example** — for product 6349 (Costume), seven
  reversible conditions (one per collar style) hide every Front-placket
  group except the matching one. Saved in
  [configurator-data--product-6349--with-conditions.json](configurator-data--product-6349--with-conditions.json).
- **Documentation** —
  [CONDITIONAL-GROUP-VISIBILITY-PLAN.md](CONDITIONAL-GROUP-VISIBILITY-PLAN.md)
  (full implementation rationale + admin walkthrough) and
  [SPECIAL-LAYERS-COMPATIBILITY-PLAN.md](SPECIAL-LAYERS-COMPATIBILITY-PLAN.md)
  (audit of how `attribute`, `option-selector`, `text-overlay`, `note`
  layer types interact with the seven common layer-level options +
  export / import audit).

### Changed

- **Plugin header** rebranded to *"Product Configurator —
  BIGHOUSEMARKETING Edition"* with `Author: BEN BIGHOUSEMARKETING` and
  `Author URI: https://www.bighousemarketing.lu/`. Original Marc
  Lacroix attribution preserved (required by GPL v3.0). See
  [woocommerce-mkl-product-configurator.php](woocommerce-mkl-product-configurator.php).
- **Conditional Logic engine init** — switched the auto-init trigger
  from the never-fired `mkl-pc-configurator-loaded` /
  `PC.fe.configurator.started` events to the canonical `PC.fe.start`
  WP hook fired by `views/configurator.js`. The engine now also
  reads `conditions` from the per-product `PC.productData.prod_<id>`
  bag (where the WP cache file actually puts them) instead of
  `PC.fe.config.conditions` (which is the global `PC_config.config`
  bag). [assets/js/addons/conditional-logic.js](assets/js/addons/conditional-logic.js).
- **Conditional Logic engine API** — every internal lookup of
  `PC.fe.content.get(layer_id).get('choices')` was replaced with the
  proper `PC.fe.getLayerContent(layer_id)` accessor so rule
  evaluation, group resolution, target lookup, and `reset_layer` no
  longer fail silently with `undefined`.
- **`MKL_PC_VERSION`** constant bumped to `1.5.10-bh.1`.

### Fixed

- **Hidden choices not removed from the side menu** — `parts/choice.js`
  (and the `views.choice` view re-defined in `views/configurator.js` /
  `.min.js`) now listen for `change:cshow` on the choice model and
  toggle the `is-conditionally-hidden` class on the `<li>`. Previously
  flipping `cshow=false` on a choice only affected the canvas image,
  not the side-menu item.
- **Active children kept rendering after their group was hidden** — the
  conditional-logic engine now auto-deselects any active child of a
  group that's being hidden, so the canvas image disappears with the
  group instead of leaving a stale layered image visible.
- **Group choices missing from admin dropdowns** — `is_group=true`
  choices were filtered out of both the rule trigger element dropdown
  and the action target dropdown in `assets/admin/js/views/conditions.js`,
  making it impossible to author a condition that targeted a group.
  Groups now appear under their own "Choice group" target type.

### Documentation (no functional change)

- Added [CONDITIONAL-GROUP-VISIBILITY-PLAN.md](CONDITIONAL-GROUP-VISIBILITY-PLAN.md)
  — implementation plan and admin recipe for the seven product-6349
  conditions.
- Added [SPECIAL-LAYERS-COMPATIBILITY-PLAN.md](SPECIAL-LAYERS-COMPATIBILITY-PLAN.md)
  — audit of `Attribute`, `Option Selector`, `Text Overlay`, `Note`
  layer types against the seven common options + export/import
  audit. Includes a manual test plan.

---

## Pre-fork baseline

The fork was created from the *Product Configurator for WooCommerce —
Premium Edition* internal build (upstream version 1.5.10) which
already integrated the eight premium addons (Extra Price, Conditional
Logic, Save Your Design, Multiple Choice, Stock Management, Form
Fields, Advanced Description, Text Overlay) plus the Note Layer,
Attribute Layer, and Option Selector layer types.

For the historical pre-fork integration log see
[ADDON-CHANGELOG.md](ADDON-CHANGELOG.md) and
[IMPLEMENTATION-SUMMARY.md](IMPLEMENTATION-SUMMARY.md).

For the original upstream changelog (Marc Lacroix releases)
see [changelog.txt](changelog.txt).
