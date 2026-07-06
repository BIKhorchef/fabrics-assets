# Option Selector Addon — Design & Implementation Plan

> Adds a new **Option / Tier selection step** to the Product Configurator that drives visibility of attribute groups and terms in downstream Attribute layers (e.g. Tissue), while staying invisible-to-the-canvas (control-only) and surfaced in summary, cart, checkout and order details.

---

## 1. Problem Statement

The configurator already supports two relevant layer types:

- **Attribute layer** — renders WooCommerce taxonomy terms (e.g. fabric/tissue groups: `FLASH LINING VOL 2`, `ROYCE VOL 1`, `ROYCE VOL 2`, `SOLEMNITY`).
- **Text overlay layer** — captures custom text from the customer.

We need a **new “Option / Tier” step** placed earlier in the flow:

```
Step 1 — Option Selector  →  [ Premium ] [ Business ]
                                              └─ [ Sub 1 ] [ Sub 2 ] [ Sub 3 ]
Step N — Tissue (Attribute layer)
         └─ groups/terms filtered by the choice made in Step 1
```

Selection examples:

- `Premium` ⇒ Tissue shows only `FLASH LINING VOL 2`, `ROYCE VOL 1`.
- `Business / Sub 1` ⇒ Tissue shows only the terms configured for Business Sub 1.
- `Business / Sub 2` ⇒ Tissue shows only the terms configured for Business Sub 2.
- etc.

The selected Option/Sub‑option must be **persisted everywhere** (Summary, Cart, Checkout, Order) and must work with **step‑based themes**. Existing products must keep working untouched.

---

## 2. Architecture Decision

### Considered options

| Option | Description | Verdict |
|---|---|---|
| **A. Extend Attribute layer** | Add a “filter‑rules” repeater inside the Attribute layer admin UI. | ❌ Conflates two concerns — attribute rendering and tier selection. No first‑class step UI. No nested sub‑options. Hard to surface in cart/order separately. |
| **B. Reuse the Conditional Logic addon** | Author rules manually for every term × option combination. | ❌ Conditional Logic operates at *choice* level (`show/hide` whole choices) and would need an exponential rule set. Admin UX is poor for this case. The plugin author of `CONDITIONAL-LOGIC-ADDON.md` confirms `show/hide` doesn’t target *terms inside an attribute layer’s groups*. |
| **C. New layer type `option_selector`** ✅ | First‑class layer with its own admin UI, nested sub‑options, and a `visibility_rules[]` map per option/sub‑option pointing at downstream Attribute layers. | ✅ Cleanest separation, matches the Attribute / Text‑overlay pattern, additive (no risk of breaking existing products), naturally fits step themes. |

**Decision → Option C.** A new addon `inc/addons/option-selector.php` registers a new layer `type = option_selector`. It is a *control* layer (does not render on the canvas), but it does render its own step UI (option buttons + sub‑option buttons) and emits filter events consumed by the Attribute layer’s view.

---

## 3. Data Model

### 3.1 Layer storage

Layers live in product meta `_mkl_product_configurator_layers` (JSON array, see [inc/base/layer.php](wp-content/plugins/product-configurator-for-woocommerce/inc/base/layer.php) and the addon loader [inc/addon-loader.php](wp-content/plugins/product-configurator-for-woocommerce/inc/addon-loader.php#L13)).

A new shape is added for the option‑selector layer:

```jsonc
{
  "_id": 9001,
  "type": "option_selector",
  "label": "Option",
  "name": "option",
  "order": 0,
  "required": true,
  "default_option": "opt_premium",

  "options": [
    {
      "id": "opt_premium",
      "label": "Premium",
      "description": "Premium tier",
      "image": { "id": 0, "url": "" },
      "price": 0,
      "sub_options": [],
      "visibility_rules": [
        {
          "target_layer_id": 5,            // the Tissue attribute layer
          "mode": "whitelist",             // "whitelist" | "blacklist"
          "scope": "term",                 // "term" | "group"
          "term_ids": [12, 14],            // when scope = term
          "groups": []                     // when scope = group (taxonomy slugs)
        }
      ]
    },
    {
      "id": "opt_business",
      "label": "Business",
      "sub_options": [
        {
          "id": "biz_sub1",
          "label": "Business — Standard",
          "image": { "id": 0, "url": "" },
          "price": 50,
          "visibility_rules": [
            { "target_layer_id": 5, "mode": "whitelist", "scope": "term", "term_ids": [16, 18, 20] }
          ]
        },
        { "id": "biz_sub2", "label": "Business — Plus",   "visibility_rules": [/*…*/] },
        { "id": "biz_sub3", "label": "Business — Pro",    "visibility_rules": [/*…*/] }
      ]
    }
  ]
}
```

**Why `whitelist` is the default `mode`:** the user’s requirement is *“only show…”* — express that directly. `blacklist` is offered for completeness (e.g. *“hide these specifically”*).

**Why `scope: "term" | "group"`:** the admin can either pick individual terms or pick whole groups (taxonomy slugs like `pa_fabric_collection`) so that adding a new term to an allowed group does not require editing every option.

### 3.2 Cart line item meta

A new cart‑item data key is added — independent of the existing `pc_attribute_selections` so we don’t collide:

```php
$cart_item_data['pc_option_selections'] = [
  [
    'layer_id'         => 9001,
    'layer_label'      => 'Option',
    'option_id'        => 'opt_business',
    'option_label'     => 'Business',
    'sub_option_id'    => 'biz_sub1',
    'sub_option_label' => 'Business — Standard',
    'price'            => 50.0
  ],
  // … one entry per option_selector layer on the product
];
```

### 3.3 Order item meta

On `woocommerce_checkout_create_order_line_item` we add a flat, human‑readable meta entry per option selector:

```
"Option" => "Business — Standard"
```

(internal id `_pc_option_<layer_id>` with the structured payload retained as hidden meta so it survives reorders).

---

## 4. Admin UI

### 4.1 Where it plugs in

- New layer type registered via filter `mkl_pc_layer_default_settings` (same hook the Attribute layer uses at [inc/addons/attribute-layer.php:24](wp-content/plugins/product-configurator-for-woocommerce/inc/addons/attribute-layer.php#L24)).
- Layer settings registered via the same admin “layer settings” pipeline; the existing repeater + media uploader components are reused.
- DB sanitization rules registered through `mkl_pc_db_fields` so the JSON shape above is whitelisted by [inc/db.php](wp-content/plugins/product-configurator-for-woocommerce/inc/db.php).

### 4.2 Layout

```
┌── Layer: Option (type = option_selector) ──────────────────────────┐
│  Label: [ Option            ]   Required: [x]                      │
│  Default option: ( Premium ▾ )                                     │
│                                                                    │
│  ── Options ────────────────────────────────────────────────────── │
│  ▸ Premium                                              [edit] [×] │
│      Image: [ upload ]    Price modifier: [ 0     ]                │
│      Visibility rules:                                             │
│        ┌──────────────────────────────────────────────────────┐   │
│        │ Target layer: ( Tissue ▾ )   Mode: ( Whitelist ▾ )    │   │
│        │ Scope: ( Specific terms ▾ )                           │   │
│        │ Allowed terms: [✓] FLASH LINING VOL 2                 │   │
│        │                [✓] ROYCE VOL 1                        │   │
│        │                [ ] ROYCE VOL 2                        │   │
│        │                [ ] SOLEMNITY                          │   │
│        └──────────────────────────────────────────────────────┘   │
│      [ + Add another rule ]                                        │
│                                                                    │
│  ▸ Business                                             [edit] [×] │
│      Sub‑options:                                                  │
│        ▸ Business — Standard          [visibility rules…]          │
│        ▸ Business — Plus              [visibility rules…]          │
│        ▸ Business — Pro               [visibility rules…]          │
│      [ + Add sub‑option ]                                          │
│                                                                    │
│  [ + Add option ]                                                  │
└────────────────────────────────────────────────────────────────────┘
```

### 4.3 Term picker AJAX

- The “Allowed terms” picker is populated by an AJAX call that re‑uses the **existing** handler `wp_ajax_mkl_pc_get_attribute_layer_choices` ([inc/addons/attribute-layer.php:49](wp-content/plugins/product-configurator-for-woocommerce/inc/addons/attribute-layer.php#L49)). When the admin selects a target layer, the picker fetches that layer’s groups + terms and renders a checkbox tree. No new endpoint required.

### 4.4 Validation

- An option must have either `visibility_rules[]` filled (whitelist or blacklist), or none (= no filtering, fall‑through).
- Option `id` must be unique within the layer; sub‑option `id` unique within its parent option. The save handler auto‑generates ids if missing (`opt_<slug>`).

---

## 5. Frontend Behavior

### 5.1 PHP data hand‑off

- The new addon hooks `mkl_product_configurator_get_front_end_data` (priority 20 — runs **after** Attribute layer’s priority 10) and:
  1. Adds a `content` entry for the option_selector layer (its own choices = options).
  2. Attaches a top‑level `visibility_map` to the configurator data:

     ```js
     window.mkl_pc_data.visibility_map = {
       "9001": {                       // option_selector layer id
         "opt_premium":          { "5": { mode: "whitelist", term_ids: [12,14] } },
         "opt_business.biz_sub1":{ "5": { mode: "whitelist", term_ids: [16,18,20] } },
         "opt_business.biz_sub2":{ "5": { mode: "whitelist", term_ids: [16,18] } },
         "opt_business.biz_sub3":{ "5": { mode: "whitelist", term_ids: [22] } }
       }
     };
     ```

- This is **PHP‑computed once**; no per‑click AJAX. All filtering happens client‑side from this static map.

### 5.2 Reactive filtering (client‑side)

Implemented in `assets/js/addons/option-selector.js`:

1. On option/sub‑option selection, the addon resolves the active key (e.g. `opt_business.biz_sub1`) and emits a custom event:
   ```js
   wp.hooks.doAction( 'mkl_pc/option_selector/changed', { layerId, optionKey, rules } );
   ```
2. A subscriber on each Attribute layer view iterates its `choices` and:
   - Adds CSS class `is-hidden-by-option-selector` to disallowed terms.
   - Sets `aria-disabled="true"` and removes them from the focusable/clickable set.
   - If the currently‑selected term becomes disallowed, the choice is **cleared** and the layer is reset to its first allowed term (so the canvas stays in a valid state).
3. **Step themes:** if an entire group becomes empty after filtering, the group header is hidden; the step still renders but with the reduced set. Step navigation already uses choice counts so this works for free.

### 5.3 Default selection

If `default_option` is set (e.g. `opt_premium`), the option_selector pre‑selects on first render so customers always land on a valid Tissue subset.

---

## 6. Cart / Checkout / Order Integration

### 6.1 Sanitization on add‑to‑cart

The addon hooks `woocommerce_add_cart_item_data` (priority 14, **before** the Attribute layer’s priority 15 at [attribute-layer.php:52](wp-content/plugins/product-configurator-for-woocommerce/inc/addons/attribute-layer.php#L52)).

For every option_selector layer it:

1. Reads the chosen `option_id` (and `sub_option_id` if present) from the POST payload.
2. Looks up the layer’s `visibility_rules` for that selection.
3. **Re‑validates** the incoming `pc_attribute_selections` against the rules. Any term not in the whitelist (or in the blacklist) is **stripped** before the Attribute layer’s own handler stores them. This is the server‑side guarantee that a tampered POST cannot save a hidden option to the cart.
4. Writes `pc_option_selections` (see §3.2).
5. If a required option_selector has no selection, fails the add‑to‑cart with a `wc_add_notice` (matches existing required‑field UX in [inc/frontend/cart.php](wp-content/plugins/product-configurator-for-woocommerce/inc/frontend/cart.php)).

### 6.2 Display

- `woocommerce_get_item_data` — adds a row per option_selector layer with the option label (and sub‑option label appended after `—` if present).
- Mini‑cart, cart, checkout, thank‑you page, emails — all flow from this filter, so they get the row for free.
- Optional thumbnail: if the option has an image, it’s appended to the same row using the existing helper that the Attribute layer uses for swatch thumbnails (line ~1212 in [attribute-layer.php](wp-content/plugins/product-configurator-for-woocommerce/inc/addons/attribute-layer.php)).

### 6.3 Order item meta

- `woocommerce_checkout_create_order_line_item` — for each option_selector layer:
  - `$item->add_meta_data( $layer_label, $option_label . ($sub ? ' — '.$sub : '') )` (visible to admin and customer).
  - `$item->add_meta_data( '_pc_option_'.$layer_id, $structured_payload, true )` (hidden, used for reorders).

### 6.4 Price

If `price` is set on an option/sub‑option, the addon hooks `woocommerce_before_calculate_totals` and adds the modifier to the cart item (mirrors the pattern used by the existing **Extra Price** addon at [inc/addons/extra-price.php](wp-content/plugins/product-configurator-for-woocommerce/inc/addons/extra-price.php)).

---

## 7. Step‑Based Theme Integration

The plugin ships several step themes (`inc/themes/h`, `inc/themes/lebolide`, `inc/themes/ben-theme-hockerty-ux`, …). Steps are derived from layer order + visibility, and each step renders a layer view.

The new addon plugs in without modifying any theme:

1. **Step generation** — because `option_selector` is a normal layer, it appears as a normal step in the step list.
2. **Step view** — the addon ships a small Backbone view (`MklPc.Views.OptionSelector`) registered against `type === 'option_selector'`. Themes that already render layer views by type pick it up automatically.
3. **Downstream filtering** — already handled in §5.2; the Attribute layer view is unchanged, the addon only mutates its already‑rendered choice list.
4. **No changes** to `assets/js/views/parts/steps.js` or any theme template.

---

## 8. Backward Compatibility

| Risk | Mitigation |
|---|---|
| Existing products without an option_selector layer | All hooks short‑circuit when no `option_selector` layer exists. Zero behavior change. |
| Existing Attribute layers | Untouched. Filtering is layered *on top* via JS event subscription; if the addon fails to load, the Attribute layer renders normally. |
| Existing cart sessions / orders | New cart key (`pc_option_selections`) does not collide with existing keys. Reading old orders is unaffected. |
| Conditional Logic addon | Independent rule engine. Both can run; the option selector’s reset‑on‑hide step runs *after* conditional logic so the final state is consistent. |
| Reorder / save‑your‑design | The hidden `_pc_option_*` meta is consumed by the existing reorder path (same pattern as the Attribute layer’s `_pc_attribute_*`). |
| Plugin update path | New addon file only; no schema migration; turning the addon off via `mkl_pc_addons` filter restores prior behavior immediately. |

---

## 9. Implementation Plan — File by File

### 9.1 New files

| File | Purpose |
|---|---|
| `inc/addons/option-selector.php` | Main addon class. Registers layer type, layer settings, DB fields, frontend data injection, cart sanitization, cart/order display, price modifier. |
| `assets/js/addons/option-selector.js` | Frontend Backbone view + filter engine + `wp.hooks` integration. |
| `assets/admin/js/addons/option-selector.js` | Admin UI: option/sub‑option repeaters, term picker (uses existing AJAX endpoint). |
| `assets/css/addons/option-selector.css` | Buttons, active state, sub‑option panel. |
| `assets/admin/css/addons/option-selector.css` | Admin UI styling. |

### 9.2 Edited files

| File | Change |
|---|---|
| `inc/addon-loader.php` (line 15‑25) | Add `'option-selector' => 'Option Selector'` to the `$addons` array so the loader requires `inc/addons/option-selector.php`. |
| `inc/db.php` | Whitelist the new layer fields (`options`, `default_option`, `required`, `visibility_rules`, …) in the sanitizer. Adds via the existing `mkl_pc_db_fields` filter — **no edit** strictly required if the addon registers via the filter; only edit if the central whitelist needs an explicit entry. |
| `readme.txt` / `changelog.txt` | New version entry: `Add: Option Selector layer with downstream attribute visibility rules`. |

### 9.3 Hook map

| Hook | Where | Why |
|---|---|---|
| `mkl_pc_layer_default_settings` | addon `__construct` | Register `option_selector` as a selectable layer type. |
| `mkl_pc_db_fields` | addon | Whitelist new fields for sanitization. |
| `mkl_product_configurator_get_front_end_data` (prio 20) | addon | Inject `content` entry + `visibility_map`. |
| `mkl_pc_get_configurator_data` (prio 5) | addon | Mirror for AJAX‑loaded configurator data. |
| `woocommerce_add_cart_item_data` (prio 14) | addon | Validate + persist `pc_option_selections`; strip disallowed attribute selections. |
| `woocommerce_get_item_data` | addon | Display row in cart/checkout. |
| `woocommerce_checkout_create_order_line_item` | addon | Write order item meta. |
| `woocommerce_before_calculate_totals` | addon | Apply optional price modifier. |
| `mkl_pc_choice_set_selected_choice__choice` ([inc/base/choice.php:108](wp-content/plugins/product-configurator-for-woocommerce/inc/base/choice.php#L108)) | addon (server‑side reorder safety net) | When rebuilding a cart item from saved data, drop disallowed terms. |

### 9.4 JS event contract

```js
// Fired by option-selector when the customer changes option/sub-option
wp.hooks.doAction( 'mkl_pc/option_selector/changed', {
  layerId,        // option_selector layer id
  optionKey,      // "opt_premium" or "opt_business.biz_sub1"
  rules           // { [targetLayerId]: { mode, scope, term_ids, groups } }
} );

// Subscribed by attribute-layer view
wp.hooks.addAction( 'mkl_pc/option_selector/changed', 'mkl-pc/attr', applyFilter );
```

---

## 10. Code Skeletons

### 10.1 `inc/addons/option-selector.php` (skeleton)

```php
<?php
namespace MKL\PC\Addons\OptionSelector;

use MKL\PC\Plugin;

defined( 'ABSPATH' ) || exit;

class Option_Selector {

    public function __construct() {
        // Register layer type
        add_filter( 'mkl_pc_layer_default_settings', [ $this, 'register_layer_type' ] );
        add_filter( 'mkl_pc_db_fields',              [ $this, 'register_db_fields' ] );

        // Frontend data
        add_filter( 'mkl_product_configurator_get_front_end_data', [ $this, 'inject_front_end_data' ], 20, 2 );
        add_filter( 'mkl_pc_get_configurator_data',                [ $this, 'inject_front_end_data' ], 5,  2 );

        // Assets
        add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_front' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );

        // Cart / order
        add_filter( 'woocommerce_add_cart_item_data',             [ $this, 'add_cart_item_data' ], 14, 3 );
        add_filter( 'woocommerce_get_item_data',                  [ $this, 'display_cart_item_data' ], 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item',[ $this, 'add_order_item_meta' ], 10, 4 );
        add_action( 'woocommerce_before_calculate_totals',        [ $this, 'apply_price_modifier' ], 20 );
    }

    public function register_layer_type( $settings ) {
        $settings['type']['choices']['option_selector'] = __( 'Option Selector', 'mkl_pc' );
        return $settings;
    }

    public function register_db_fields( $fields ) {
        $fields['option_selector'] = [
            'options'        => 'array',
            'default_option' => 'string',
            'required'       => 'bool',
        ];
        return $fields;
    }

    public function inject_front_end_data( $data, $product_id ) {
        $layers = mkl_pc()->content->get_layers( $product_id );
        $map    = [];
        foreach ( $layers as $layer ) {
            if ( ! isset( $layer['type'] ) || $layer['type'] !== 'option_selector' ) continue;

            // 1) Build content entry (option/sub-option choices)
            $data['content'][] = $this->build_content_entry( $layer );

            // 2) Build visibility map for this layer
            $map[ $layer['_id'] ] = $this->build_visibility_map( $layer );
        }
        $data['option_selector_visibility_map'] = $map;
        return $data;
    }

    private function build_visibility_map( $layer ) {
        $out = [];
        foreach ( (array) ($layer['options'] ?? []) as $opt ) {
            $out[ $opt['id'] ] = $this->normalize_rules( $opt['visibility_rules'] ?? [] );
            foreach ( (array) ($opt['sub_options'] ?? []) as $sub ) {
                $out[ $opt['id'] . '.' . $sub['id'] ] = $this->normalize_rules( $sub['visibility_rules'] ?? [] );
            }
        }
        return $out;
    }

    private function normalize_rules( $rules ) {
        $by_layer = [];
        foreach ( $rules as $r ) {
            $by_layer[ (int) $r['target_layer_id'] ] = [
                'mode'     => $r['mode']   ?? 'whitelist',
                'scope'    => $r['scope']  ?? 'term',
                'term_ids' => array_map( 'intval', (array) ( $r['term_ids'] ?? [] ) ),
                'groups'   => array_map( 'sanitize_title', (array) ( $r['groups'] ?? [] ) ),
            ];
        }
        return $by_layer;
    }

    public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        if ( empty( $_POST['pc_option_selections'] ) ) return $cart_item_data;

        $raw       = json_decode( wp_unslash( $_POST['pc_option_selections'] ), true );
        $selections = $this->sanitize_selections( $raw, $product_id );
        if ( empty( $selections ) ) return $cart_item_data;

        $cart_item_data['pc_option_selections'] = $selections;

        // Server-side enforcement: strip disallowed attribute selections
        $cart_item_data = $this->strip_disallowed_attribute_selections( $cart_item_data, $selections, $product_id );
        return $cart_item_data;
    }

    public function display_cart_item_data( $item_data, $cart_item ) {
        if ( empty( $cart_item['pc_option_selections'] ) ) return $item_data;
        foreach ( $cart_item['pc_option_selections'] as $sel ) {
            $value = $sel['option_label']
                . ( ! empty( $sel['sub_option_label'] ) ? ' — ' . $sel['sub_option_label'] : '' );
            $item_data[] = [
                'key'   => $sel['layer_label'],
                'value' => wp_kses_post( $value ),
            ];
        }
        return $item_data;
    }

    public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( empty( $values['pc_option_selections'] ) ) return;
        foreach ( $values['pc_option_selections'] as $sel ) {
            $value = $sel['option_label']
                . ( ! empty( $sel['sub_option_label'] ) ? ' — ' . $sel['sub_option_label'] : '' );
            $item->add_meta_data( $sel['layer_label'], $value );
            $item->add_meta_data( '_pc_option_' . $sel['layer_id'], $sel, true );
        }
    }

    // build_content_entry(), sanitize_selections(), strip_disallowed_attribute_selections(),
    // apply_price_modifier(), enqueue_front(), enqueue_admin() … omitted for brevity.
}

new Option_Selector();
```

### 10.2 `assets/js/addons/option-selector.js` (skeleton)

```js
(function ($, hooks) {
  'use strict';
  if (!window.mkl_pc_data) return;

  var visibilityMap = window.mkl_pc_data.option_selector_visibility_map || {};

  function activeKey(layerId, model) {
    var opt = model.get('selected_option');
    var sub = model.get('selected_sub_option');
    return sub ? opt + '.' + sub : opt;
  }

  function applyFilter(payload) {
    var rules = payload.rules || {};
    Object.keys(rules).forEach(function (targetLayerId) {
      var rule = rules[targetLayerId];
      var layerView = MklPc.layers && MklPc.layers[targetLayerId];
      if (!layerView) return;

      layerView.choices.each(function (choice) {
        var allowed = isAllowed(choice, rule);
        choice.set('hidden_by_option_selector', !allowed);
      });

      if (layerView.selectedChoice && layerView.selectedChoice.get('hidden_by_option_selector')) {
        layerView.selectFirstAllowed();
      }
      layerView.render();
    });
  }

  function isAllowed(choice, rule) {
    if (rule.scope === 'group') {
      var inGroup = rule.groups.indexOf(choice.get('group')) !== -1;
      return rule.mode === 'whitelist' ? inGroup : !inGroup;
    }
    var termId = parseInt(choice.get('term_id'), 10);
    var inList = rule.term_ids.indexOf(termId) !== -1;
    return rule.mode === 'whitelist' ? inList : !inList;
  }

  hooks.addAction('mkl_pc/option_selector/changed', 'mkl-pc/option-selector', applyFilter);

  // Backbone view for the option_selector layer
  MklPc.Views.OptionSelector = Backbone.View.extend({
    events: { 'click [data-option-id]': 'onPick', 'click [data-sub-id]': 'onPickSub' },
    onPick: function (e) {
      var id = $(e.currentTarget).data('option-id');
      this.model.set({ selected_option: id, selected_sub_option: null });
      this.fire();
    },
    onPickSub: function (e) {
      var id = $(e.currentTarget).data('sub-id');
      this.model.set('selected_sub_option', id);
      this.fire();
    },
    fire: function () {
      var key = activeKey(this.model.id, this.model);
      hooks.doAction('mkl_pc/option_selector/changed', {
        layerId: this.model.id,
        optionKey: key,
        rules: (visibilityMap[this.model.id] || {})[key] || {},
      });
    },
  });
})(jQuery, wp.hooks);
```

---

## 11. Test Plan

| # | Scenario | Expected |
|---|---|---|
| 1 | Product with no option_selector layer | Identical behavior to current plugin (regression baseline). |
| 2 | Premium selected → reach Tissue step | Only `FLASH LINING VOL 2`, `ROYCE VOL 1` are visible/clickable. |
| 3 | Switch from Premium to Business → Sub 1 | Tissue immediately re‑filters; if previously‑selected term is now hidden, layer auto‑selects first allowed term. |
| 4 | Tamper POST `pc_attribute_selections` with a hidden term | Server strips it; cart shows only allowed selection; notice if the layer had no valid term. |
| 5 | Required option_selector left empty | Add‑to‑cart fails with notice. |
| 6 | Cart / mini‑cart / checkout / thank‑you / order email | Each shows `Option: Business — Standard` row. |
| 7 | Order edit screen | Admin sees the same row; reorder reproduces selection from `_pc_option_<layer_id>` hidden meta. |
| 8 | Step theme (`h`, `lebolide`, `ben-theme-hockerty-ux`) | Option selector renders as its own step; downstream Tissue step reflects filtering with no theme changes. |
| 9 | Disable addon via `mkl_pc_addons` filter | Frontend falls back to “no filtering”; existing products keep working. |
| 10 | Conditional Logic addon active on the same product | Both engines apply; final visible state = `(allowed by option_selector) AND (not hidden by conditional logic)`. |

---

## 12. Rollout

1. Implement addon in a feature branch. Ship behind the loader so existing installs are unaffected until enabled.
2. Smoke‑test against the supplied product (Tissue groups: `FLASH LINING VOL 2`, `ROYCE VOL 1`, `ROYCE VOL 2`, `SOLEMNITY`).
3. Document in `CONDITIONAL-LOGIC-ADDON.md`’s sibling spot — this file.
4. Add changelog line in `changelog.txt` and bump version in `woocommerce-mkl-product-configurator.php`.

---

## 13. Open Questions (resolve before coding)

1. **Multi‑target rules per option** — should one option be allowed to filter *several* downstream Attribute layers (e.g. Tissue *and* Lining)? Current model already supports this via `visibility_rules[]`; UI must expose it.
2. **Sub‑option inheritance** — should sub‑option rules *replace* or *intersect* with parent option rules? Spec says “only show selected attributes configured for this sub‑option”, so the model uses **replace** by default. Confirm.
3. **Default selection vs required** — if `required = true` and no `default_option`, do we force the customer to choose before any other layer renders? Recommended: yes (hide downstream steps until chosen). Confirm.
4. **Pricing display** — show the option price as a “+€X” badge on the option button? Reuse the Extra Price addon pattern.
