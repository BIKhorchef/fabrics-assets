# Conditional Group Visibility — Implementation Plan

> **Goal**
> When a user picks a collar in **Etape 1 → Collar** (layer `11`), the
> **Etape 4 → Front plackets** (layer `1`) menu must show **only the matching
> "front-placket group"** for that collar (e.g. "English Collar" ⇒ only
> "Anglais" group is visible). All other groups in that layer must be
> hidden — header AND every child choice — without changing any image
> behaviour, prices, validation, or affecting other products that already
> rely on the existing hidden-image trick.
>
> **Source of data analysed** — `configurator-data--product-6349--2026-04-27T03_15_57.701Z.json`
>
> **Why it does not work today** — the existing `Conditional Logic` add-on
> bundled in this plugin (`inc/addons/conditional-logic.php` and
> `assets/js/addons/conditional-logic.js`) supports only the targets
> `Layer` and `Choice`. It explicitly **filters out group choices**
> (`is_group: true`) from both the rule trigger dropdown and the action
> target dropdown — so a group like *Anglais* / *Col Italien* literally
> cannot be selected from the admin. That is the *first* reason a working
> condition cannot be built today. The *second* reason is that even if
> you could pick a group as a target, the JS engine has no notion of
> "cascade hide the group + its children" — it would only flip `cshow`
> on the (invisible) group header and the visible child choices would
> still render in the side menu.

---

## 1. Data model recap (product 6349)

### Etape 1 → Collar layer (`layerId = 11`)

| Choice ID | Name (Etape 1) | Maps to Front-placket group ID | Group label (Etape 4) |
|-----------|-----------------------|-------------------------------|------------------------|
| 1         | English Collar        | 6                             | Anglais                |
| 2         | French Collar         | 13                            | Col Cutaway            |
| 4         | Italian Collar        | 12                            | Col Italien            |
| 3         | Button down Collar    | 27                            | Boutonné               |
| 6         | Hidden button Collar  | 32                            | Boutonné Caché         |
| 5         | Chinese Collar        | 41                            | Col Mao Mandarin       |
| 7         | Wingtip Collar        | 42                            | Col Cassé              |

(Verified by tracing each child choice's image URL — e.g. all children
of group `42` use `wingtip_collar_*.png`; all children of group `6` use
`english-point-collar-*.png`, etc.)

### Etape 4 → Front plackets layer (`layerId = 1`) structure

The choices array in this layer is a flat list, but each entry is one of:
* a **group header** (`is_group: true`, `parent: 0`) — not selectable
* a **child choice** (`parent: <group_id>`) — selectable, has an image

Example (Anglais group):

```
Choice _id=6  is_group=true  parent=0   — group header "Anglais"
Choice _id=1  parent=6                  — Gorge Classique
Choice _id=2  parent=6                  — Gorge Française
Choice _id=3  parent=6                  — Gorge Cachée
Choice _id=4  parent=6                  — Gorge Smoking
```

So "show only the Anglais group" means: keep `cshow=true` on choices with
`_id ∈ {6, 1, 2, 3, 4}` and set `cshow=false` on every other choice in
layer `1` (every other group header AND every other child).

---

## 2. Why the current Conditional Logic does not work

| # | Symptom in the admin / front-end | Root cause in code |
|---|-----------------------------------|--------------------|
| A | The group choices ("Anglais", "Col Italien", …) are **missing from the rule trigger dropdown**, so you cannot say *"IF Front-plackets > Anglais is selected"*. (This is the wrong direction anyway, but worth knowing.) | `assets/admin/js/views/conditions.js` line ~491: `if ( choice.get( 'is_group' ) ) return;` |
| B | The group choices are **missing from the action target dropdown** ("Choice" mode), so you cannot say *"Show / Hide Anglais"*. | `assets/admin/js/views/conditions.js` line ~593: same `is_group` skip |
| C | The action **target type dropdown only has `Layer` / `Choice`** — there is no concept of *Group* as a unit. Even with A/B fixed, hiding the group header alone does not hide its child choices. | `inc/addons/conditional-logic.php` template `tmpl-mkl-pc-condition-action-row` lines ~423-426 |
| D | The frontend engine `execute_action()` only switches on `target_type` of `layer` / `choice`. There is no cascade for groups. | `assets/js/addons/conditional-logic.js` `execute_action()` and `get_target_model()` |
| E | When `cshow=false` is set on a choice **after** initial render, the side-menu list item does not visually disappear. The viewer images already react to `cshow` (see `viewer-layer.js conditional_display()`), but `assets/js/views/parts/choice.js` only listens to `change:active` — not `change:cshow` — so the `<li>` stays in the DOM. | `assets/js/views/parts/choice.js` `initialize()` |
| F | If a user already selected (for example) "Col Italien → Gorge Cachée", and then switches the collar to "English Collar", that previously-active child stays `active=true` even though we hide its group. Result: a stale image keeps rendering on the canvas. | No deselect-on-hide logic in `execute_action` |

Items A, B, C, D, E, F are the entire reason the user reports *"the
existing implementation of conditional logic is not working"*.

---

## 3. Design — minimal, additive, backward-compatible

We add a third value to the existing `target_type` enumeration:

```
target_type ∈ { "layer", "choice", "group" }
                                  ^^^^^^^ NEW
```

A group target means: *"the group header **plus** every child choice
whose `parent === <group_id>`, all in the same layer"*. The frontend
engine cascades `show` / `hide` / `disable` / `enable` over that whole
set in a single action. Reversible conditions automatically get the
opposite cascade for free.

We also fix the four side issues (A, B, E, F) so the new target works
end-to-end.

### Why this design (and not alternatives)

* **Why not "expand groups inline" so the admin saves N hide-choice
  actions?** — That bloats the saved JSON, is brittle when the user
  reorders a group, and breaks the moment a child is added in the
  admin. A symbolic `group` target stays correct as the data evolves.
* **Why not introduce a new dedicated "Show only group" action type?**
  — It would duplicate `show` semantics for one specific shape. Keeping
  the action vocabulary unchanged and only enriching the **target**
  axis is the smaller, more orthogonal change.
* **Why does this not affect the existing "hidden-image" trick used on
  the other product?** — Conditions are stored per-product in the
  `_mkl_product_configurator_conditions` meta (see
  `add_conditions_to_init_data()` / `add_conditions_to_frontend_data()`
  in `inc/addons/conditional-logic.php`). Each product evaluates only
  its own conditions. The new `target_type=group` is opt-in: if a
  product has zero conditions using it, nothing changes. The
  hidden-image mechanism on the other product stays untouched because
  this feature only flips `cshow`; it never touches image URLs, image
  visibility logic, layer parent/child relationships, or anything else
  on the layer model.

---

## 4. Code changes — file-by-file

> All paths are relative to
> `wp-content/plugins/product-configurator-for-woocommerce/`.

### 4.1 `inc/addons/conditional-logic.php`

**Change 1 — extend the action target dropdown template** (line ~423-426 in
`tmpl-mkl-pc-condition-action-row`):

```diff
 <select class="action-target-type" data-setting="target_type">
     <option value="layer"  <# if ( data.target_type === 'layer' )  { #>selected<# } #>><?php _e( 'Layer',  'product-configurator-for-woocommerce' ); ?></option>
     <option value="choice" <# if ( data.target_type === 'choice' ) { #>selected<# } #>><?php _e( 'Choice', 'product-configurator-for-woocommerce' ); ?></option>
+    <option value="group"  <# if ( data.target_type === 'group' )  { #>selected<# } #>><?php _e( 'Choice group', 'product-configurator-for-woocommerce' ); ?></option>
 </select>
```

**Change 2 — register `target_type=group` as a sanitised value**: nothing
to do — `target_type` is already declared with `'sanitize' =>
'sanitize_key'` (line ~165), and `sanitize_key('group')` returns
`'group'`. No DB schema change.

> **Backward-compat** — Conditions saved before this patch keep working
> because their `target_type` is still `layer` or `choice`. The new
> value is only reachable from a freshly-edited condition.

### 4.2 `assets/admin/js/views/conditions.js`

**Change 3 — let groups appear in the rule's *trigger element* dropdown**
(`populate_trigger_element`, around line 491). Today:

```js
choices.each( function( choice ) {
    if ( choice.get( 'is_group' ) ) return;       // <— blocks groups
    var label = choice.get( 'admin_label' ) || ...
    ...
});
```

Replace with:

```js
choices.each( function( choice ) {
    var label = choice.get( 'admin_label' ) || choice.get( 'name' ) || 'Choice ' + choice.id;
    if ( choice.get( 'is_group' ) ) {
        label = '[Group] ' + label;               // visual hint, opt-in
    }
    var selected = ( this.rule.trigger_element == choice.id ) ? ' selected' : '';
    $select.append( '<option value="' + choice.id + '"' + selected + '>' + _.escape( label ) + '</option>' );
}, this );
```

> *Optional but the user did not ask for it.* For the user's specific
> scenario the trigger is the **Collar layer** (which has no groups), so
> this change is not strictly required. Leave it in only if the user
> wants groups to be usable as triggers later.

**Change 4 — populate the action target dropdown when `target_type=group`**
(`populate_target_elements`, around line 569). Today only `layer` and
`choice` cases exist. Add a third branch and tweak the `choice` branch
so the existing skip on groups is removed only inside the `choice`
branch (so users can still target a group child as a single choice):

```js
populate_target_elements: function() {
    var $select = this.$( '.action-target-element' );
    $select.empty();
    $select.append( '<option value="">' + '--- Select ---' + '</option>' );

    var layers = PC.app.admin.layers;

    if ( this.action.target_type === 'layer' ) {
        if ( ! layers ) return;
        layers.each( function( layer ) {
            var label    = layer.get( 'admin_label' ) || layer.get( 'name' ) || 'Layer ' + layer.id;
            var selected = ( this.action.target_element_id == layer.id ) ? ' selected' : '';
            $select.append( '<option value="' + layer.id + '"' + selected + '>' + _.escape( label ) + '</option>' );
        }, this );

    } else if ( this.action.target_type === 'choice' ) {
        if ( ! layers ) return;
        layers.each( function( layer ) {
            var layer_label = layer.get( 'admin_label' ) || layer.get( 'name' ) || 'Layer ' + layer.id;
            var choices     = PC.app.get_layer_content( layer.id );
            if ( choices && choices.length ) {
                $select.append( '<optgroup label="' + _.escape( layer_label ) + '">' );
                choices.each( function( choice ) {
                    if ( choice.get( 'is_group' ) ) return;       // groups handled by the new branch
                    var label    = choice.get( 'admin_label' ) || choice.get( 'name' ) || 'Choice ' + choice.id;
                    var selected = ( this.action.target_element_id == choice.id ) ? ' selected' : '';
                    $select.append( '<option value="' + choice.id + '"' + selected + '>' + _.escape( label ) + '</option>' );
                }, this );
                $select.append( '</optgroup>' );
            }
        }, this );

    } else if ( this.action.target_type === 'group' ) {           // NEW
        if ( ! layers ) return;
        layers.each( function( layer ) {
            var layer_label = layer.get( 'admin_label' ) || layer.get( 'name' ) || 'Layer ' + layer.id;
            var choices     = PC.app.get_layer_content( layer.id );
            if ( ! choices || ! choices.length ) return;
            // Filter to only group headers in this layer
            var groups = choices.filter( function( c ) { return c.get( 'is_group' ); } );
            if ( ! groups.length ) return;
            $select.append( '<optgroup label="' + _.escape( layer_label ) + '">' );
            _.each( groups, function( g ) {
                var label    = g.get( 'admin_label' ) || g.get( 'name' ) || 'Group ' + g.id;
                var selected = ( this.action.target_element_id == g.id ) ? ' selected' : '';
                $select.append( '<option value="' + g.id + '"' + selected + '>' + _.escape( label ) + '</option>' );
            }, this );
            $select.append( '</optgroup>' );
        }, this );
    }
},
```

**Change 5 — when the target type changes, repopulate the element list.**
The existing change-handler (`on_target_type_change`, currently in the
file just below) already calls `populate_target_elements()`, so no
further work is needed here. Re-verify after the patch by switching the
dropdown between `Layer` / `Choice` / `Choice group` and checking the
third dropdown updates accordingly.

### 4.3 `assets/js/addons/conditional-logic.js` (frontend engine)

**Change 6 — add cascading group support in `execute_action`**.
Currently the `switch` only handles `layer` / `choice` targets via the
single `model` returned by `get_target_model()`. Refactor the function
so the show/hide/disable/enable family branches into a helper:

```js
execute_action: function( action_type, target_type, target_id ) {
    target_id = parseInt( target_id, 10 );
    if ( ! target_id ) return;

    if ( target_type === 'group' ) {
        // Cascade over the group header + every direct child in the same layer
        var members = this.get_group_members( target_id );
        if ( ! members.length ) return;
        for ( var i = 0; i < members.length; i++ ) {
            this.apply_action_to_model( action_type, 'choice', members[ i ] );
        }
        // For 'hide' or 'disable', also drop any active child so the
        // image stops rendering and the layer becomes consistent again.
        if ( action_type === 'hide' || action_type === 'disable' ) {
            for ( var j = 1; j < members.length; j++ ) {     // skip header
                if ( members[ j ].get( 'active' ) ) {
                    members[ j ].set( 'active', false );
                }
            }
        }
        return;
    }

    var model = this.get_target_model( target_type, target_id );
    if ( ! model ) return;
    this.apply_action_to_model( action_type, target_type, model );

    // For non-group hides, also auto-deselect a now-hidden choice so
    // the canvas does not keep showing its image.
    if ( target_type === 'choice' && action_type === 'hide' && model.get( 'active' ) ) {
        model.set( 'active', false );
    }
},

apply_action_to_model: function( action_type, target_type, model ) {
    switch ( action_type ) {
        case 'show':         if ( model.get( 'cshow' ) === false ) model.set( 'cshow', true );  break;
        case 'hide':         model.set( 'cshow', false );                                       break;
        case 'select':
            if ( target_type === 'choice' && model.collection ) model.collection.selectChoice( model.id );
            else if ( target_type === 'layer' )                model.set( 'active', true );
            break;
        case 'deselect':     if ( target_type === 'choice' )    model.set( 'active', false );  break;
        case 'disable':      model.set( 'disabled', true );                                    break;
        case 'enable':       model.set( 'disabled', false );                                   break;
        case 'reset_layer':  if ( target_type === 'layer' )     this.reset_layer( model.id );  break;
        case 'show_in_menu': if ( target_type === 'layer' )     model.set( 'hide_in_configurator', false ); break;
        case 'hide_in_menu': if ( target_type === 'layer' )     model.set( 'hide_in_configurator', true );  break;
    }
},

/**
 * Resolve a group_id (a choice with is_group=true) to its full member
 * set: [ headerModel, ...childModels ] in the same layer.
 */
get_group_members: function( group_choice_id ) {
    var found = [];
    if ( ! PC.fe || ! PC.fe.content ) return found;
    PC.fe.content.each( function( layer_content ) {
        var choices = layer_content.get( 'choices' );
        if ( ! choices ) return;
        var header = choices.get( group_choice_id );
        if ( ! header || ! header.get( 'is_group' ) ) return;
        found.push( header );
        choices.each( function( c ) {
            if ( c.get( 'parent' ) == group_choice_id ) found.push( c );
        } );
    } );
    return found;
},
```

**Change 7 — make sure `condition_references_element()` still triggers
re-evaluation when the user clicks a collar choice.** It already does,
because the rule's `trigger_parent_id` is the *layer* id of the Collar
layer. No change needed.

### 4.4 `assets/js/views/parts/choice.js`

**Change 8 — the side-menu `<li>` must react to `change:cshow`.** Today
the choice view only listens to `change:active`. Add one line so the
list item visually appears/disappears when the engine flips `cshow`:

```diff
 initialize: function( options ) {
     this.options = options || {};
     this.listenTo( this.model, 'change:active', this.activate );
+    this.listenTo( this.model, 'change:cshow', this.toggle_cshow );
     wp.hooks.doAction( 'PC.fe.choice.init', this );
     ...
 },
```

…and add the handler near the bottom of the view:

```js
toggle_cshow: function() {
    var hidden = this.model.get( 'cshow' ) === false;
    this.$el.toggleClass( 'is-conditionally-hidden', hidden );
    this.$el.attr( 'aria-hidden', hidden ? 'true' : 'false' );
}
```

…and add the matching CSS rule (one line) in
`assets/css/general.css` (or the existing addon stylesheet
`assets/admin/css/conditional-logic.css` — pick the frontend bundle):

```css
.layer_choices .choice.is-conditionally-hidden { display: none !important; }
```

Why a class flag instead of `this.$el.hide()`? Because themes may apply
display: flex / grid on `.choice` and `display: none` set inline could
fight specificity. A class rule is simpler to override per-theme later.

> **Important** — none of the image-rendering views need to change. The
> existing `viewer-layer.js conditional_display()` already toggles the
> `<img>` based on `cshow + active`. As long as we deselect children
> when their group is hidden (Change 6), the canvas stays consistent.

---

## 5. Admin configuration — exact steps for product 6349

After the code changes above are deployed, open product 6349 in the WP
admin (Products → Edit → Configurator), go to the **Conditional
settings** tab on the left sidebar, and create **seven** conditions.
Each one follows the same pattern.

### Common pattern

```
Condition name:    "Show only <Group> when <Collar> is selected"
Enabled:           ✓
Make reversible:   ✓        ← the magic that hides this group when the collar isn't selected
Always check:      ☐
Comparison:        all

IF [all] OF THE FOLLOWING CONDITIONS ARE MET:
  Layer = "Collar"   →   <Collar choice>     →   selected

THEN PERFORM THE FOLLOWING ACTIONS:
  Show   →  Choice group  →  <Front-placket group>
```

### The seven conditions to create

| # | Condition name                                  | Trigger choice (layer 11)            | Action target group (layer 1) |
|---|--------------------------------------------------|---------------------------------------|--------------------------------|
| 1 | Show only Anglais when English Collar selected   | Collar → **English Collar** (id 1)    | Anglais (id 6)                 |
| 2 | Show only Cutaway when French Collar selected    | Collar → **French Collar** (id 2)     | Col Cutaway (id 13)            |
| 3 | Show only Italien when Italian Collar selected   | Collar → **Italian Collar** (id 4)    | Col Italien (id 12)            |
| 4 | Show only Boutonné when Button-down selected     | Collar → **Button down Collar** (id 3)| Boutonné (id 27)               |
| 5 | Show only Boutonné Caché when Hidden btn selected| Collar → **Hidden button Collar** (id 6)| Boutonné Caché (id 32)        |
| 6 | Show only Mao when Chinese Collar selected       | Collar → **Chinese Collar** (id 5)    | Col Mao Mandarin (id 41)       |
| 7 | Show only Cassé when Wingtip Collar selected     | Collar → **Wingtip Collar** (id 7)    | Col Cassé (id 42)              |

> **Why reversible works here without conflicts** — the upstream warning
> ("avoid reversible if multiple conditions touch the same elements")
> exists because reversible auto-flips its action; if two conditions
> both flip the same target, they fight. In this scenario each
> condition acts on a **different** target group (Anglais vs Italien
> vs …), so they cannot fight. Net behaviour: when collar X is
> selected, condition X's `Show <group X>` fires; the other six
> conditions evaluate FALSE, and reversible cleanly hides their
> respective groups.

### Save flow

1. Click **Save** in the top-right of the Conditional settings panel.
2. Reload the product page once on the front-end so the
   `mkl_product_configurator_get_front_end_data` filter re-runs and the
   `conditions` array is delivered to `PC.fe.config.conditions`.
3. Hard-refresh once (Ctrl+F5) to clear the JS cache for
   `assets/js/addons/conditional-logic.js` and
   `assets/js/views/parts/choice.js`. The PHP enqueue uses
   `filemtime()` for cache-busting on those two files, so on subsequent
   deploys the cache will bust automatically.

---

## 6. Reusing this on the second product

Conditions are stored in the post-meta key
`_mkl_product_configurator_conditions` (see
`inc/addons/conditional-logic.php → add_conditions_to_init_data()`),
**per product**. Each product evaluates only its own conditions.
Therefore:

* **Set-up on product B** — repeat the seven conditions inside that
  product's own *Conditional settings* tab. There is no global
  registry to share between products, so nothing to coordinate.
* **No conflict with the hidden-image trick** — the new
  `target_type=group` only changes `cshow` and (for `hide`) `active`
  on choice models. It never touches:
  - `images[].image.url`
  - `images[].angleId`
  - layer `parent` / `image_order`
  - any `_mkl_product_configurator_*` meta key other than `conditions`
  …so anything the other product is doing with hidden images,
  duplicate layers, or image stacking is unaffected.
* **If both products want identical behaviour** — duplicating the
  conditions JSON between products is the fastest way:
  1. On product 6349, after saving, open the JSON export
     (Configurator → Actions → Export, or read
     `_mkl_product_configurator_conditions` directly from the DB).
  2. Paste it into the same field of product B (matching the layer /
     choice IDs of product B — IDs are *per-product* so they will
     differ, see § 7).

---

## 7. Pitfall: layer & choice IDs are per-product

The IDs in the table above (`6` = Anglais, `11` = Collar layer, …) are
the IDs **inside product 6349's own configurator JSON**. On product B
the same logical "Anglais" group will have a different `_id`. Always
re-pick the choices/groups from the dropdowns in product B's admin
panel — never copy the raw `target_element_id` numbers across products.

If you do copy raw JSON between products (per § 6), remap every
`trigger_parent_id`, `trigger_element`, and `target_element_id` to the
new product's IDs first.

---

## 8. Test plan (manual, in the front-end)

After deploying the code changes and saving the seven conditions on
product 6349, walk through the following on a clean browser session
(incognito, or after Ctrl+F5):

1. **Initial load** — open the configurator, no collar selected.
   Etape 4 should still display all groups (unchanged from today). The
   reversible conditions evaluate FALSE for all collars, so all groups
   are *hidden* — except expected default. *Adjust default selection in
   layer 11 (Collar) so a collar is picked on load.*
2. **Pick "English Collar" in Etape 1** — go to Etape 4 and confirm:
   - "Anglais" group header is visible
   - "Anglais" children (Gorge Classique/Française/Cachée/Smoking) are
     visible and selectable
   - All 6 other groups (Italien, Cutaway, Boutonné, Boutonné Caché,
     Mao, Cassé) are gone from the menu (header *and* children)
   - The viewer image still shows the English Collar shirt with no
     stale placket image left over
3. **Switch to "Italian Collar"** — Etape 4 now shows only "Col
   Italien" group; previously-selected "Anglais → Gorge Classique" must
   no longer be active (deselect-on-hide logic, Change 6).
4. **Repeat for each remaining collar** (French, Button down, Hidden
   button, Chinese, Wingtip).
5. **Edit-from-cart** — add the product to cart with English Collar +
   Anglais → Gorge Smoking, then click *Edit* on the cart line. The
   configurator should re-open with English Collar pre-selected and
   only the Anglais group visible. The previously-picked Gorge Smoking
   stays active. (This validates that conditions evaluate on the
   `data_loaded` hook, which the engine already wires up at the bottom
   of `conditional-logic.js init()`.)
6. **Side-menu visual check** — pick a collar, then in the side
   navigation expand Etape 4 → Front plackets. The hidden groups must
   not show even momentarily. (This validates Change 8 —
   `change:cshow` listener on the `<li>`.)
7. **Price / cart serialisation** — confirm the order line in the cart
   shows the correct front-placket name and that there is **no** ghost
   line from a previously-active hidden child. (Validates the
   deselect-on-hide path.)
8. **Other product (product B)** — load the second product's
   front-end before adding any conditions on it. Confirm nothing has
   changed: the existing hidden-image setup still behaves exactly as
   before (the new code path is dormant when `conditions: []`).

---

## 9. Rollback plan

Every change is additive and gated behind `target_type === 'group'`. To
roll back:

1. Revert the four files listed in § 4 (PHP template, admin JS, frontend
   engine JS, choice.js + one CSS line).
2. Existing conditions saved with `target_type=group` will be loaded but
   silently ignored by the old engine (the `switch` falls through to no
   action) — **they will not break the configurator**, they just stop
   working until the patch returns.

No DB migration, no schema change, no removal of existing behaviour.

---

## 10. Files to change — checklist

- [ ] `inc/addons/conditional-logic.php` — add `<option value="group">`
      in `tmpl-mkl-pc-condition-action-row`.
- [ ] `assets/admin/js/views/conditions.js` — extend
      `populate_target_elements()` with the `group` branch (Change 4);
      optional: include groups in `populate_trigger_element()`
      (Change 3).
- [ ] `assets/js/addons/conditional-logic.js` — split
      `execute_action()` into `execute_action` + `apply_action_to_model`
      and add `get_group_members()` (Change 6); add deselect-on-hide
      for plain choice targets.
- [ ] `assets/js/views/parts/choice.js` — bind `change:cshow`,
      add `toggle_cshow()` handler (Change 8).
- [ ] `assets/css/general.css` (or the addon's frontend stylesheet) —
      one rule: `.layer_choices .choice.is-conditionally-hidden { display: none !important; }`.

No changes to `db.php`, no changes to PHP sanitisation
(`add_db_fields()` already sanitises `target_type` via `sanitize_key`),
no changes to image-rendering pipelines.
