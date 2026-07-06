# Special Layer Types × Common Layer Options — Compatibility Audit Plan

> **Goal**
> Make sure the four "non-standard" layer types in this plugin
> (**Attribute**, **Option Selector**, **Text Overlay**, **Note**) honour
> the same set of layer-level / choice-level options that the built-in
> *Simple* and *Group* layers honour, and confirm the
> **Import / Export** round-trip carries every relevant field.
>
> This document is a **research + verification plan only** — it lists
> what to inspect, where to inspect it, and the manual test steps to
> walk through. **No code changes** are made by this document. Once you
> read it through and approve, we can fix any specific gaps it
> uncovers.
>
> **Scope of options (the seven the user listed):**
>
> 1. *Hide this layer in the cart / checkout / order* — `hide_in_cart`
> 2. *Hide this layer in the summary* — `hide_in_summary`
> 3. *Hide this layer in the menu* — `hide_in_configurator`
> 4. *Required* — `required` (and addon-specific `os_required`,
>    `to_required`, `note_required`)
> 5. *Use as group* — `is_group` (choice-level)
> 6. *Show group name in the cart / order* — `show_group_label_in_cart`
>    (choice-level, only when `is_group=true`)
> 7. *Content of this group is hidden by default, toggled when clicking
>    the title* — `choice_groups_toggle` (choice-level, only when
>    `is_group=true`)

---

## 1. Where these options live in the codebase

### 1.1 Layer-level options

Defined in [inc/admin/settings/layer.php](inc/admin/settings/layer.php).

| # | Setting key            | Definition (line)                                       | Default visibility condition (admin UI)                                                  |
|---|------------------------|----------------------------------------------------------|------------------------------------------------------------------------------------------|
| 1 | `hide_in_cart`         | `inc/admin/settings/layer.php:158`                      | `!data.not_a_choice && "summary" != data.type`                                           |
| 2 | `hide_in_summary`      | `inc/admin/settings/layer.php:166`                      | `!data.not_a_choice && "summary" != data.type`                                           |
| 3 | `hide_in_configurator` | `inc/admin/settings/layer.php:173`                      | `!data.not_a_choice && "summary" != data.type`                                           |
| 4 | `required`             | `inc/admin/settings/layer.php:201`                      | `!data.not_a_choice && ( "simple" == data.type \|\| "multiple" == data.type )` ⚠         |

> ⚠ **Important** — the built-in `required` checkbox is only shown
> when the layer type is `simple` or `multiple`. For `attribute`,
> `option-selector`, `text-overlay`, `note`, `form` the addons each
> add their **own** required field. See § 2.

### 1.2 Choice-level options

Defined in [inc/admin/settings/choice.php](inc/admin/settings/choice.php).

| # | Setting key                 | Definition (line)                          | Visibility condition (admin UI)                              |
|---|------------------------------|---------------------------------------------|---------------------------------------------------------------|
| 5 | `is_group`                   | `inc/admin/settings/choice.php:53`         | `!data.not_a_choice`                                          |
| 6 | `show_group_label_in_cart`   | `inc/admin/settings/choice.php:60`         | `!data.not_a_choice && data.is_group`                         |
| 7 | `choice_groups_toggle`       | `inc/admin/settings/choice.php:131`        | `!data.not_a_choice && data.is_group`                         |

`is_group` and friends are **per-choice**, not per-layer. They only
make sense when the layer's choice list contains group **header**
choices. So the question for the special layers is: *do their choice
lists support `is_group=true` choices at all?* (See § 4.)

### 1.3 Where each option is *enforced* (runtime)

| Setting                  | Enforced in                                                                      |
|--------------------------|-----------------------------------------------------------------------------------|
| `hide_in_cart`           | `inc/frontend/cart.php:164`, `inc/frontend/order.php:111`/`:185` (skip layer in cart/order item meta) |
| `hide_in_summary`        | `assets/js/views/parts/summary.js:32-33` (skips layer when rendering the summary panel) |
| `hide_in_configurator`   | `assets/js/views/parts/layers-list-item.js` `hide_in_configurator()` method (toggles `.hide_in_configurator` CSS class on the menu item). Also tested in `inc/compatibility/assets/js/elementor-pro-configurator-field.js:40-41` |
| `required` (built-in)    | `assets/js/views/parts/save-data.js` (validation before "Add to cart") |
| `os_required`            | `inc/addons/option-selector.php:663-712` (server-side validation in `add_cart_item_data`) |
| `to_required`            | `inc/addons/text-overlay.php:265` (admin field) — runtime validation in front-end JS |
| `note_required`          | `inc/addons/note-layer.php:290-365` (server-side validation), front-end JS for required mark |
| `is_group` /`show_group_label_in_cart` | `inc/base/product.php:19` (cart/order line label rendering); also affects `parts/choice.js` rendering |
| `choice_groups_toggle`   | `assets/js/views/parts/choice.js:182-184` `toggle_group()` + CSS class `show-group-content`; default state read in front-end via `PC.fe.config.choice_groups_toggle` global setting |

---

## 2. The four special layer types

Layer types are registered through the `mkl_pc_layer_default_settings`
PHP filter. Each addon also adds its **own** layer or choice settings.
Below: registration entry point + storage shape + which of the seven
options it inherits or replaces.

### 2.1 Attribute layer (`type: "attribute"`)

* Source: [inc/addons/attribute-layer.php](inc/addons/attribute-layer.php)
* Choices are **dynamically generated** from the WooCommerce product
  attributes selected in `attribute_taxonomies` / `attribute_taxonomy`
  layer settings. Choice IDs use offsets ≥ 850000.
* Adds layer settings: `attribute_taxonomies`, `attribute_taxonomy`,
  `attribute_display_style`, `attribute_swatch_size`,
  `attribute_show_label`. **Does NOT** add a `required` field of its
  own — see § 5.1.
* Inherits the seven layer-level options because nothing rewrites
  their conditions for `type=="attribute"`.

### 2.2 Option Selector layer (`type: "option-selector"`)

* Source: [inc/addons/option-selector.php](inc/addons/option-selector.php)
* Doesn't use a traditional choices array — options live in
  `os_options` JSON on the layer itself. Choices are auto-injected
  on-the-fly with `is_group=true` headers and child choices
  (option-selector.php:373-427). Choice IDs use offset
  `CHOICE_ID_OFFSET = 700000`.
* Adds its **own** layer-level `os_required` checkbox
  (`inc/addons/option-selector.php:130-137`) under a dedicated
  *"Option Selector Settings"* section. The built-in `required` field
  doesn't show because the condition is `simple|multiple` only.
* `hide_in_cart` / `hide_in_summary` / `hide_in_configurator` should
  inherit by default (their condition is just `summary != type`).

### 2.3 Text Overlay layer (`type: "text-overlay"`)

* Source: [inc/addons/text-overlay.php](inc/addons/text-overlay.php)
* One choice per layer (the text input itself). Layer-level settings
  carry colours, fonts, position options. Choice-level settings carry
  default text, placeholder, max chars, regex pattern, and a
  per-choice `to_required` checkbox
  (`inc/addons/text-overlay.php:265-271`).
* `hide_in_cart` / `hide_in_summary` / `hide_in_configurator` inherit.
* `is_group` is technically *available* at choice level, but turning
  on a group inside a text-overlay layer makes no UX sense (a text
  overlay choice is a single text input).

### 2.4 Note layer (`type: "note"`)

* Source: [inc/addons/note-layer.php](inc/addons/note-layer.php)
* One textarea per choice. Choice-level fields:
  `note_field_label`, `note_placeholder`, `note_max_chars`,
  `note_required` (`inc/addons/note-layer.php:128-164`).
* The addon auto-injects a single default choice if the admin saved
  the layer with no choices, so the textarea always renders.
* `hide_in_cart` / `hide_in_summary` / `hide_in_configurator` inherit
  layer-level. `is_group` is technically available but, like
  text-overlay, of no practical use for textareas.

---

## 3. Compatibility matrix — what should work today

Legend: ✅ inherits the standard behaviour | ⚠ uses an addon-specific
field instead | 🔍 needs verification | ❌ not applicable to that
layer type

| Option ↓ \\ Layer type →       | simple | group | attribute | option-selector | text-overlay | note |
|--------------------------------|:------:|:-----:|:---------:|:---------------:|:------------:|:----:|
| `hide_in_cart`                 | ✅     | ✅    | 🔍        | 🔍              | 🔍           | 🔍   |
| `hide_in_summary`              | ✅     | ✅    | 🔍        | 🔍              | 🔍           | 🔍   |
| `hide_in_configurator` (menu)  | ✅     | ✅    | 🔍        | 🔍              | 🔍           | 🔍   |
| `required` (built-in)          | ✅     | ❌    | ❌→⚠ 5.1  | ⚠ `os_required` | ⚠ `to_required` (per-choice) | ⚠ `note_required` (per-choice) |
| `is_group` (choice)            | ✅     | ❌    | 🔍 5.2    | ❌ (auto-built) | ❌ (single)  | ❌   |
| `show_group_label_in_cart`     | ✅     | ✅    | 🔍 5.2    | ✅ (set automatically by addon) | ❌ | ❌ |
| `choice_groups_toggle`         | ✅     | ❌    | 🔍 5.2    | ✅ (set automatically by addon) | ❌ | ❌ |

The 🔍 cells are where this audit needs eyes-on confirmation. The
⚠ cells are where the option name is *different* — admin docs should
point users to the right field per layer type.

---

## 4. Specific gaps the audit needs to verify

### 4.1 `hide_in_cart` for non-standard layers

The cart / order code paths (`inc/frontend/cart.php:164`,
`inc/frontend/order.php:111`/`:185`) check
`$selected_choice->get_layer( 'hide_in_cart' )`. They run on every
saved choice that the front-end emitted in `pc_configurator_data`. As
long as each addon emits one or more choices in
`pc_configurator_data` and `parse_choices()` (in `save-data.js`)
processes them like a normal layer, `hide_in_cart` should silently
work. Verify:

* **Attribute**: pick a term, add to cart with `hide_in_cart=1` on
  the layer. Confirm the term name does **not** appear in the cart
  line / order item meta. (Test 4.1.A in § 7.)
* **Option Selector**: option-selector contributes its own cart-meta
  via `add_cart_item_data` (priority 14). Inspect whether the
  `hide_in_cart` check is honoured for the option label specifically.
  (Test 4.1.B.)
* **Text Overlay**: text-overlay contributes via `add_cart_item_data`
  priority 20 and `display_cart_item_data`. The text contributions
  may be assembled outside the `parse_choices()` loop — inspect
  whether `hide_in_cart` short-circuits them. (Test 4.1.C.)
* **Note**: note-layer mirrors form-builder via `add_cart_item_data`
  priority 14. Verify `hide_in_cart` works the same as for
  text-overlay. (Test 4.1.D.)

### 4.2 `hide_in_summary`

[`assets/js/views/parts/summary.js:32-33`](assets/js/views/parts/summary.js#L32-L33)
short-circuits any layer with `hide_in_summary=true` before
rendering. Since `summary.js` iterates every layer model regardless
of type, it should work uniformly across the four addons. **One
catch**: the option-selector and note addons may add custom rendering
hooks downstream of summary; if any of them re-inject content
**after** the summary was rendered, their items won't be filtered.

* Verify by toggling `hide_in_summary` on each layer type and
  reloading the front-end.
* Files to read if there's a regression: `parts/summary.js`,
  `inc/addons/option-selector.php` (search for `summary`),
  `inc/addons/text-overlay.php` (`display_cart_item_data` is for
  cart, not summary, so should be fine), `inc/addons/note-layer.php`.

### 4.3 `hide_in_configurator` (menu)

Toggled via [`assets/js/views/parts/layers-list-item.js`](assets/js/views/parts/layers-list-item.js)
`hide_in_configurator()` which adds the CSS class
`hide_in_configurator` on the layer's `<li>`. The CSS rule lives in
`assets/css/configurator-common.css` (search for
`.hide_in_configurator`). All four addons render their layer's
sidebar item via the same `layers_list_item` view, so this should
just work.

* Verify the CSS rule is `display: none` (or equivalent). If a theme
  override visually shows a hidden layer, the bug is theme-side, not
  plugin-side.

### 4.4 `required` for non-standard layers

The built-in `required` checkbox is gated to `simple` / `multiple`
only ([inc/admin/settings/layer.php:204](inc/admin/settings/layer.php#L204)).
Each addon supplies its own:

| Addon            | Field          | Level        | Where validated                                    |
|------------------|----------------|--------------|----------------------------------------------------|
| Option Selector  | `os_required`  | layer        | `inc/addons/option-selector.php:663-712` (server) |
| Text Overlay     | `to_required`  | per choice   | front-end JS in `assets/js/addons/text-overlay-frontend.js` (likely) |
| Note             | `note_required`| per choice   | server: `inc/addons/note-layer.php:290-365`       |
| Attribute        | **none**       | —            | **None — gap; see § 5.1**                          |

### 4.5 `is_group` / `show_group_label_in_cart` / `choice_groups_toggle` for special layers

The semantics:

* **Group choices only make sense in `simple` or `multiple` layers**
  whose choice list is hand-curated by the admin. Group choices in
  the choice array are how the conditional-logic group target
  (`target_type=group`) was implemented in
  [CONDITIONAL-GROUP-VISIBILITY-PLAN.md](CONDITIONAL-GROUP-VISIBILITY-PLAN.md).
* **Option Selector** has its **own** internal grouping (option +
  sub-options) and the addon writes
  `is_group=true, show_group_label_in_cart=true,
  choice_groups_toggle=enabled` programmatically when it auto-builds
  its choice array (`inc/addons/option-selector.php:373-427`). So the
  three checkboxes are **already on**, by design — admins shouldn't
  edit them.
* **Text Overlay** and **Note** have one or a flat list of choices
  with no grouping concept. `is_group=true` on a text-overlay /
  note choice would create a group header that has nothing
  meaningful to render.
* **Attribute** auto-builds choices (one per term). The addon may or
  may not insert a group header per `attribute_taxonomies` entry —
  to be confirmed by reading
  `inc/addons/attribute-layer.php` `_build_choices_*` /
  `add_attribute_content_data` methods. See § 5.2.

### 4.6 Conditional Logic group target (`target_type=group`) on special layers

A condition with `target_type=group` only does something when the
target choice id resolves to a choice with `is_group=true` (see
`get_group_members` in
[`assets/js/addons/conditional-logic.js`](assets/js/addons/conditional-logic.js)).
That means:

* For **simple/multiple** layers with hand-built groups → works
  (already shipped, see Front Plackets in product 6349).
* For **attribute** layers — works **iff** the attribute layer
  exposes its taxonomy headers as `is_group=true` choices in the
  choices array. Verify in § 5.2.
* For **option-selector** — group target makes no sense because the
  visibility filtering is the addon's own job (its visibility rules,
  not conditional logic). Don't try to combine the two on the same
  layer.
* For **text-overlay** / **note** — N/A.

---

## 5. Concrete gaps to investigate

### 5.1 Attribute layer has no `required` flag

The attribute layer addon does not add an `os_required`-equivalent.
That means a merchant currently cannot say *"the customer must pick
a swatch in this layer"* for an attribute layer. The built-in
`required` field is hidden because the condition is
`simple|multiple` only.

* **What to read first**:
  [inc/addons/attribute-layer.php:94-160](inc/addons/attribute-layer.php#L94-L160)
  (`add_layer_settings`).
* **Likely fix when we get to it**: relax the built-in condition in
  `inc/admin/settings/layer.php:204` to also allow `attribute`, OR
  add an `attribute_required` flag in the addon's
  `add_layer_settings`. Pick whichever the user prefers — the second
  is cleaner because it keeps the attribute behaviour wholly inside
  the addon.

### 5.2 Attribute layer + `is_group` / `target_type=group`

When an admin selects multiple attributes in `attribute_taxonomies`,
each becomes its own group within the same layer. Question: is the
group HEADER injected as a choice with `is_group=true`?

* **What to read first**:
  [inc/addons/attribute-layer.php](inc/addons/attribute-layer.php)
  — search for `is_group` in the file. Also read
  `add_attribute_content_data` / `maybe_add_attribute_data` /
  any internal `_build_choices` helpers.
* **What to verify**:
  1. Does the choice array for an attribute layer with two
     taxonomies contain two `is_group=true` entries (one per
     taxonomy)?
  2. If yes — the new `target_type=group` action will already work on
     attribute layers (you can hide an entire taxonomy group based
     on a choice elsewhere).
  3. If no — adding it is a small change, identical pattern to
     option-selector's auto-built groups
     (`inc/addons/option-selector.php:373-427`).

### 5.3 Text-overlay & note "groups"

Edge case: an admin marks a text-overlay or note choice as
`is_group=true`. Today, nothing prevents them from doing so in the
admin UI (the checkbox visibility condition is just
`!data.not_a_choice`). The result on the front-end is undefined
— probably a broken render where the textarea/text-input is shown
as a group header.

* **What to read first**:
  [inc/admin/settings/choice.php:53-66](inc/admin/settings/choice.php#L53-L66).
* **Likely fix**: add `&& "text-overlay" != data.layer_type && "note" != data.layer_type` to the visibility conditions for
  `is_group`, `show_group_label_in_cart`, `choice_groups_toggle`.
  Low-priority unless the user actually tries to enable it.

### 5.4 Conditional Logic admin: group dropdown shows EVERY group

The `populate_target_elements()` group branch
([assets/admin/js/views/conditions.js:601-619](assets/admin/js/views/conditions.js#L601-L619))
lists `is_group=true` choices from **all** layers regardless of
layer type. After § 5.2 / § 5.3 are sorted, an attribute layer
might produce multiple group headers — that's fine, they'll show
up in the dropdown by their attribute name. Not a bug, just a
note.

---

## 6. Export / Import audit

### 6.1 What `Export` writes

[`assets/admin/js/views/import.js:94-126`](assets/admin/js/views/import.js#L94-L126)
serialises:

```
{ layers: [...], angles: [...], content: [...], conditions: [...] }
```

Each entry inside `layers` and `content` is a Backbone model attribute
bag. Therefore the addon-specific fields ride along automatically
**as long as they are stored on the layer or choice model**:

| Addon            | Layer-level fields exported                                                                                                                | Choice-level fields exported                                |
|------------------|---------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------|
| Attribute        | `attribute_taxonomies`, `attribute_taxonomy`, `attribute_display_style`, `attribute_swatch_size`, `attribute_show_label`                   | (choices auto-built — exported as part of `content`)         |
| Option Selector  | `os_options`, `os_default_option`, `os_required`, `os_display_style`                                                                        | (choices auto-built — exported as part of `content`)         |
| Text Overlay     | `to_text_type`, `to_colors`, `to_colors_label`, `to_fonts`, `to_fonts_label`, `to_font_display`, `to_text_case`, `to_positions_label`, `to_position_options` | `to_default_text`, `to_placeholder`, `to_required`, `to_max_chars`, `to_input_pattern`, `to_positions` |
| Note             | (no layer-level fields)                                                                                                                     | `note_field_label`, `note_placeholder`, `note_max_chars`, `note_required`, `_note_user_text` |
| Conditional Logic| —                                                                                                                                            | —  (top-level `conditions` array)                            |
| Conditional Logic| `sync_id` (per-layer)                                                                                                                       | —                                                            |

### 6.2 What `Import` does NOT cover

Things outside the four collections above are **not** in the export
file:

* **Uploaded font files** for the Text Overlay addon (lives in WP
  uploads / fonts library, not in the configurator data). After
  import to a different site, the font *references* in
  `to_fonts` will exist but the font files won't — text will fall
  back to system fonts until the admin re-uploads via the *Fonts
  library* admin tab.
* **Saved Designs** (`save-your-design` addon's `wp_mkl_pc_saved_designs` table). Designs are user-scoped and not part of
  per-product config.
* **Attribute taxonomies themselves**. If the importing site doesn't
  have the same `pa_*` taxonomies / terms, the attribute layer will
  render an empty list. Same as the upstream plugin's behaviour.
* **Stock-management linked products** (separate addon storage).

### 6.3 Import validations to add (optional)

When importing, the importer should warn (not error) if:

* A layer has `type: "attribute"` and the importing site doesn't
  have the referenced `attribute_taxonomy`.
* A layer has `type: "text-overlay"` and any of its `to_fonts`
  references a font ID that doesn't exist in the local fonts library.
* A condition's `target_element_id` references a layer or group that
  isn't in the imported `layers` / `content`.

These are nice-to-have. Skip until/unless someone actually trips
over them.

---

## 7. Manual test plan

Use product 6349 (which already has `simple`, `group`, `attribute`,
`option-selector`, `text-overlay`, `note`, `summary` layers per
`grep "type"`) plus its imported conditions. Run through these:

### 7.1 `hide_in_cart` per layer type

For each non-summary layer, toggle `hide_in_cart` ON in the admin
*Layer settings → Display* section, save, reload front-end, configure
the product, add to cart, open the cart page:

| Layer type      | Expected when `hide_in_cart=1`                                                          |
|-----------------|------------------------------------------------------------------------------------------|
| simple          | Selected option does not appear in the cart-line item details                            |
| group           | Group block does not appear                                                              |
| attribute       | Selected attribute term does not appear                                                  |
| option-selector | Selected option label does not appear                                                    |
| text-overlay    | Customer-entered text does not appear                                                    |
| note            | Customer-entered note does not appear                                                    |

If any row shows the suppressed item in the cart, see § 4.1.

### 7.2 `hide_in_summary` per layer type

Toggle `hide_in_summary=1` on the layer, reload, open the
configurator, navigate to the *Summary* step. The layer's choices
should be absent from the summary view but the layer should still be
visible in the menu and active in the configurator.

### 7.3 `hide_in_configurator` per layer type

Toggle `hide_in_configurator=1`, reload, open the configurator. The
layer's `<li>` in the side menu should be `display:none` (CSS
`.hide_in_configurator`). The layer is still active for cart /
summary / pricing — only the menu item is hidden.

### 7.4 `required` per layer type

| Layer type      | Setting to enable    | Expected                                                        |
|-----------------|----------------------|-----------------------------------------------------------------|
| simple          | `required`           | Add to cart blocked with "X is required" if no choice picked    |
| multiple        | `required`           | Same                                                            |
| attribute       | **(currently no built-in equivalent — see § 5.1)**            | Document as a gap                                               |
| option-selector | `os_required`        | Add to cart blocked with "Please select an option for X"        |
| text-overlay    | `to_required` (choice) | Add to cart blocked / front-end form-validation prevents submit |
| note            | `note_required` (choice) | Add to cart blocked with "Please fill in X"                     |

### 7.5 `is_group` family — only meaningful where applicable

* `simple` layer: build a layer with mixed groups + child choices,
  toggle `show_group_label_in_cart` and verify the cart line uses
  `Group - Child` format.
* `simple` layer: toggle `choice_groups_toggle` and verify the
  group's children are collapsed by default and expand on click.
* `option-selector` layer: confirm cart shows option label and
  sub-option label per the addon's own auto-build (no admin
  intervention needed).
* `text-overlay` / `note`: leave `is_group` off — § 5.3.
* `attribute` (after § 5.2 verification): if multiple taxonomies are
  set, the cart line should show `<Taxonomy name> - <Term name>`
  when `show_group_label_in_cart` is on.

### 7.6 Conditional Logic on special layer types

* **Layer hide on attribute** — write a condition: *"IF Collar =
  English Collar selected → Hide Layer 'Tissus' (attribute layer)"*.
  Reload, pick English Collar, and verify the entire Tissus step
  disappears from the menu (and from the summary, and from the
  cart).
* **Layer hide on option-selector** — toggle the *Gamme* layer
  (`type: option-selector`) hidden via a condition. Verify:
  - The layer's menu item disappears.
  - Any attribute-layer visibility rules driven by the
    option-selector keep working when the layer is shown again.
* **Choice hide on text-overlay** — write a condition that hides the
  monogram choice based on a collar selection. Verify the textarea
  vanishes from Etape 6 and the monogram doesn't render in the
  preview.
* **Choice hide on note** — same pattern.
* **Group hide on attribute** (depends on § 5.2 outcome) — only test
  this AFTER confirming attribute layer emits group headers.

### 7.7 Export → Import round-trip

1. On product 6349 click *Import / Export → Export* in the
   configurator admin sidebar. Save the JSON.
2. Create a fresh draft product, open its configurator, click
   *Import from file* → choose the saved JSON → *Import* → *Save*.
3. Open the front-end of the new product and verify:

   | Check                                                                                  | Expected                              |
   |----------------------------------------------------------------------------------------|---------------------------------------|
   | Same layers, same step names, same order                                                | ✓                                     |
   | Attribute layer renders the same swatches (assumes same taxonomies exist on the site)   | ✓                                     |
   | Option-selector renders the same buttons / cards                                        | ✓                                     |
   | Text-overlay renders the same fonts (assumes fonts are locally installed)               | ⚠ font files may need re-upload       |
   | Note renders the same textareas with the right labels                                   | ✓                                     |
   | All 7 conditions present in *Conditional settings*                                      | ✓                                     |
   | Picking English Collar still hides the other Front-placket groups                       | ✓                                     |
   | `hide_in_cart` / `hide_in_summary` / `hide_in_configurator` flags survived              | ✓                                     |
   | `is_group`, `show_group_label_in_cart`, `choice_groups_toggle` survived on each choice  | ✓                                     |

   Anything that fails this list is a real bug to fix in the
   addon's `add_db_fields()` (sanitisation) or in the importer's
   `process_import()` (`assets/admin/js/views/import.js:352-385`).

### 7.8 Quick "do nothing" round-trip test

A useful *no-change* sanity check: export a product, immediately
re-import the same JSON to the same product (overwrite), and confirm
nothing about the configurator changed. If anything *does* change
(missing fields, broken rules, lost settings), that field is being
dropped during sanitise/escape and must be added to the addon's
`add_db_fields()`.

---

## 8. Files to read (and possibly change) per gap

These are read-only in this audit. We only modify them if § 7 turns
up a concrete failure for that file's responsibility.

| Concern                          | File(s) to inspect                                                           |
|----------------------------------|--------------------------------------------------------------------------------|
| Layer-level admin defaults       | [inc/admin/settings/layer.php](inc/admin/settings/layer.php)                  |
| Choice-level admin defaults      | [inc/admin/settings/choice.php](inc/admin/settings/choice.php)                |
| Cart enforcement of `hide_in_cart`| [inc/frontend/cart.php](inc/frontend/cart.php), [inc/frontend/order.php](inc/frontend/order.php) |
| Summary panel enforcement        | [assets/js/views/parts/summary.js](assets/js/views/parts/summary.js)          |
| Menu visibility                  | [assets/js/views/parts/layers-list-item.js](assets/js/views/parts/layers-list-item.js) |
| Built-in `required` validation   | [assets/js/views/parts/save-data.js](assets/js/views/parts/save-data.js)      |
| Attribute layer registration     | [inc/addons/attribute-layer.php](inc/addons/attribute-layer.php), [assets/js/attribute-layer.js](assets/js/attribute-layer.js) |
| Option Selector registration     | [inc/addons/option-selector.php](inc/addons/option-selector.php), [assets/js/addons/option-selector.js](assets/js/addons/option-selector.js) |
| Text Overlay registration        | [inc/addons/text-overlay.php](inc/addons/text-overlay.php), [assets/js/addons/text-overlay-frontend.js](assets/js/addons/text-overlay-frontend.js) |
| Note layer registration          | [inc/addons/note-layer.php](inc/addons/note-layer.php), [assets/js/addons/note.js](assets/js/addons/note.js) |
| Import / Export front controller | [assets/admin/js/views/import.js](assets/admin/js/views/import.js)            |
| DB sanitise/escape pipeline       | [inc/db.php](inc/db.php) (`get_fields`, `_sanitize_or_escape`)                 |

---

## 9. Out of scope

This audit specifically does **not** cover:

* Fixing any bug found — that's a follow-up after the user reads
  this plan and approves direction.
* Refactoring the seven options' admin UI (e.g. moving them to a
  more discoverable location).
* Adding *new* options to any of the four addons.
* Touching the conditional-logic engine — that work is documented in
  [CONDITIONAL-GROUP-VISIBILITY-PLAN.md](CONDITIONAL-GROUP-VISIBILITY-PLAN.md)
  and is already shipped.

---

## 10. Suggested order of attack (after approval)

1. § 7.7 the export/import round-trip on a copy of product 6349
   first — that one is the most likely to surface bugs and the
   easiest to fix when it does.
2. § 7.1 *hide_in_cart* per layer type — same reason.
3. § 7.4 *required* per layer type — gap in attribute layer is the
   only known one.
4. § 7.6 conditional logic on special layer types — depends on § 5.2
   outcome.
5. Cosmetic gaps (§ 5.3) only if a real-world problem report comes
   in.
