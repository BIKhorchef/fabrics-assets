# Configurator Performance Optimisation Plan

> **Goal**
> Cut the time-to-interactive of the *Chemise* configurator (and any other
> product with a heavy attribute layer like **Tissus** — 156 full
> images + 156 thumbnails) so that:
>
> 1. The product page itself is not blocked by the configurator JS.
> 2. The configurator opens in <500 ms even on a 4G mobile connection.
> 3. Switching to the *Tissus* step doesn't fire 150+ image requests in
>    one burst.
>
> **Scope of this document** — research + plan only. Each item lists the
> exact files to read or change, the expected gain, and the effort level.
> Nothing is implemented yet; we'll do the chosen items in a follow-up.

---

## 1. Baseline measurements (Chemise, product 6540)

```
Total choices:       217
Full-res images:     204
Thumbnails:          163
Heaviest layer:      Tissus (160 choices, 156 full images, 156 thumbs)
Cached config JS:    ~85 KB on disk (gzip ≈ 30 KB)
Raw JSON:            ~290 KB pre-gzip
image_loading_mode:  lazy  (default — already on)
```

What the plugin already does well:

* `image_loading_mode = lazy` — `assets/js/views/parts/viewer-layer.js:118` —
  inactive choices render with a 1×1 transparent GIF until the user
  clicks them.
* gzip on the cached JSON — `inc/ajax.php:152-159` and the cache file
  served with proper headers.
* Per-choice mouse-enter / focus preload — `parts/choice.js:21-22` and
  `parts/viewer-layer.js:175-183` — the canvas image of a hovered choice
  is fetched in the background so the click feels instant.

What still hurts:

| # | Bottleneck | Why it hurts | Where it lives |
|---|------------|--------------|----------------|
| 1 | **156 thumbnails fired at once** when the *Tissus* swatch list opens | Browser limits concurrency to 6 — the rest queue. On 4G this is 4-8 s of stalled UI. | `assets/js/views/parts/choice.js render()` calls `model.get_image('thumbnail')` for every choice |
| 2 | **PNG thumbnails** instead of WebP/AVIF | PNG ≈ 5-15 KB/each. WebP would be ≈ 30-50 % smaller. | Source images on the WP media library, served directly |
| 3 | **All 217 choice `<li>`s rendered upfront** | Even hidden tabs render their entire choice DOM. The browser parses + lays out everything before the configurator can be interactive. | `assets/js/views/parts/choices.js add_all()` — iterates the entire collection on init |
| 4 | **No virtualisation in swatch grids** | 156 swatch tiles is heavy DOM, especially on mobile. | Same as #3 |
| 5 | **Mouseenter preloads the FULL-RES image of a Tissus swatch** | A single hover can pull a 200-500 KB texture; users who scan many swatches end up downloading dozens of full images they never select. | `assets/js/views/parts/choice.js:21` `mouseenter -> preload_image` |
| 6 | **Configurator JS is enqueued on every product page** | Even users who don't open the configurator pay for parsing `product_configurator.min.js` + `configurator.min.js` (~250 KB combined). | `inc/frontend/frontend-woocommerce.php:393-394` |
| 7 | **Per-product config file is loaded synchronously** in the page `<head>` | Blocks DOMContentLoaded. | `inc/frontend/frontend-woocommerce.php:467` `wp_enqueue_script` of the cache file |
| 8 | **Tissus content is in the same JSON as everything else** | The 156-choice attribute layer is 70 % of the JSON. Steps the user might never reach are paid for upfront. | `inc/cache.php save_config_file()` writes everything in one file |

---

## 2. Optimisation menu — pick what to ship

I've grouped each item by **impact** (perceptual / measured speedup) and
**effort** so you can choose. *Numbers in parentheses are the bottleneck
ID from § 1.*

### Tier 1 — High impact, low effort (1-2 hours each)

#### 1.1 Add native `loading="lazy"` on swatch thumbnails (#1, #4)

The configurator currently uses raw `<img src="…">` for swatch
thumbnails. Adding `loading="lazy"` lets the browser skip thumbnails
that aren't in the viewport when the swatch list opens. For the *Tissus*
layer with its 5-row grid, this typically defers ≈ 80 % of the
thumbnails until the user actually scrolls. **No JS code, just one
attribute.**

* **Files**: `assets/js/views/parts/choice.js render()`, the
  `mkl-pc-thumbnail` template — wherever `<img>` is emitted.
* **Risk**: very low — lazy is a hint, not a guarantee.
* **Estimated gain**: 1.5-3 s on the *Tissus* step on cold load.

#### 1.2 Disable full-res preload on hover for heavy layers (#5)

The mouseenter / focus handler unconditionally calls `preload_image` →
fetches the full-resolution image of the hovered choice. For *Tissus*
(156 swatches × ~300 KB each), a user who scans the grid can pull
30-40 MB they never actually select.

* **Files**: `assets/js/views/parts/choice.js:21-22, 163-170`,
  `parts/viewer-layer.js:175-183`.
* **Approach**: opt-in per layer via a layer setting
  `disable_hover_preload` (boolean checkbox in the *Layer settings →
  Display* admin section). Default off, enable for *Tissus* layers.
  Or: disable hover preload globally when `attribute_swatch_size <=
  small` (heuristic — if you've configured many small swatches, you
  probably have many of them).
* **Risk**: low — image still loads on click, just slightly less
  instant. The current behaviour stays the default.
* **Estimated gain**: 5-30 MB of saved bandwidth per session;
  perceptible UI smoothness improvement.

#### 1.3 Async-load the cached config file (#7)

Today the per-product config file is enqueued synchronously and parsed
before any other configurator script runs. Switching its `<script>` tag
to `defer` (or `async` with the right ordering) lets the page render
before the JSON is parsed.

* **Files**: `inc/frontend/frontend-woocommerce.php:143, 467` (where
  `wp_enqueue_script( 'mkl_pc/js/fe_data_…' )` is called).
* **Approach**: register the script with `'in_footer' => true` AND add
  a `script_loader_tag` filter to inject `defer`. Watch out: dependent
  configurator scripts must declare the data script as a dependency or
  read the data inside `DOMContentLoaded`.
* **Risk**: moderate — script ordering matters. Test that the
  configurator still opens correctly when the data file loads after
  the configurator JS.
* **Estimated gain**: removes 30-100 ms from First Contentful Paint
  on slow connections.

#### 1.4 Smaller default swatch thumbnails (#1, #2)

Tissus thumbnails are 150×150 PNG. CSS already shrinks them to
60-80 px in the swatch grid. Generating a 100×100 (or 80×80) thumbnail
size and using **that** for swatch display would cut bytes per request
roughly 4×.

* **Files**:
  - WordPress: `add_image_size( 'mkl_pc_swatch', 100, 100, true )` in
    a new init hook in `inc/plugin.php` or a small helper in
    `inc/images.php`.
  - Plugin: where the configurator picks the thumbnail URL —
    `inc/base/choice.php` `get_image()` — change the requested size
    from `'thumbnail'` to `'mkl_pc_swatch'` (or expose a setting).
* **Risk**: low — needs a one-off "Regenerate Thumbnails" run after
  the new size is registered.
* **Estimated gain**: ≈ 50-70 % bytes saved per swatch × 156 swatches
  on Tissus.

#### 1.5 Convert thumbnails to WebP on upload (#2)

WebP is 25-35 % smaller than equivalent PNG/JPEG. Modern browsers all
support it; WP since 6.0 generates WebP automatically when the
`big_image_size_threshold` setting is on. For older WP installs we'd
add a small helper, or use a plugin like ShortPixel / Imagify already
on the site.

* **Files**: configuration only — `inc/plugin.php` could call
  `add_filter( 'wp_image_editors', … )` to ensure GD/Imagick prefers
  WebP. Or document the recommended image-optimisation plugin.
* **Risk**: low.
* **Estimated gain**: 25-35 % bandwidth reduction on every choice
  thumbnail and full-res image.

### Tier 2 — Bigger gains, moderate effort (1-2 days)

#### 2.1 Virtualise the Tissus swatch grid (#3, #4)

156 swatch `<li>`s × `<img>` × tippy tooltip × event handlers is heavy
DOM. With virtualisation we render only the rows currently scrollable
into the swatch list (≈ 18-30 tiles), and recycle DOM nodes as the user
scrolls.

* **Files**: `assets/js/views/parts/choices.js add_all() / add_one()`,
  CSS for the swatch list. Hook on the `colors` display-mode (the
  swatch UI).
* **Approach**: small custom virtual scroller (≈ 150 LOC of JS) keyed
  on the `<ul.layer_choices>` container. Or pull in a tiny library
  like `virtual-scroll-list` (≈ 5 KB).
* **Risk**: moderate — must keep the existing keyboard navigation,
  conditional-logic `cshow` toggling, and Backbone change events
  working.
* **Estimated gain**: 100-300 ms scripting time reclaimed on opening
  the *Tissus* step on a mid-range phone.

#### 2.2 IntersectionObserver-driven thumbnail load (#1, #4)

Even with `loading="lazy"`, browsers can be conservative about which
images they consider "in viewport" inside a custom-scrolling
container. An IntersectionObserver on each swatch tile gives precise
control and lets us also pause loads when the configurator is hidden
behind another step.

* **Files**: `assets/js/views/parts/choice.js render()` — emit the
  `<img>` with `data-src` instead of `src`, then attach an observer.
* **Risk**: low.
* **Estimated gain**: works *with* tier-1 lazy attribute and sums up
  another 0.5-1 s on slow connections.

#### 2.3 Split heavy attribute layers into a separate AJAX-loaded payload (#8)

The Tissus layer is 70 % of the cached JSON. Customers who never reach
*Étape 7* still pay for it. Solution: emit the heavy attribute layer's
choices as a SEPARATE chunk loaded only when the user reaches that step
or hovers over its menu entry.

* **Files**:
  - PHP: `inc/cache.php save_config_file()` — emit a stub
    `{ layerId: X, choices: [], _lazy: true }` and a sibling URL to
    the full payload. Add a new AJAX endpoint
    `mkl_pc_get_layer_content` that returns just one layer's content.
  - JS: `assets/js/product_configurator.js` — when a layer with
    `_lazy:true` is activated, fetch its content over AJAX and merge
    into `PC.fe.contents.content`.
* **Risk**: higher — the conditional-logic engine, attribute filtering,
  and option-selector visibility map all read from `PC.fe.content`.
  Ensure they tolerate a layer's choices loading after `PC.fe.start`.
* **Estimated gain**: cached JS file drops from 85 KB → ≈ 30 KB. Initial
  parse time drops accordingly. Tissus step takes 100-300 ms longer to
  open the *first* time but every other step is faster.

#### 2.4 Defer the configurator JS itself until the user opens it (#6)

Today `product_configurator.min.js` (≈ 80 KB) and
`configurator.min.js` (≈ 50 KB) plus their dependencies (Backbone, wp-
hooks, accounting, tippy …) load on every product page even before the
"Customize" button is clicked.

* **Files**: `inc/frontend/frontend-woocommerce.php load_scripts()`.
* **Approach**:
  - Register (don't enqueue) the configurator scripts.
  - Inline a tiny ~2 KB bootstrap that on first click of `.pc_open` (or
    whatever your theme uses) calls `wp.enqueue.add()` (or hand-rolled
    `<script>` injection) to load the bundle, then triggers the
    configurator open.
  - Le Bolide theme integration: hook `mkl_pc/themes/lebolide` to
    insert the bootstrap before the customise CTA.
* **Risk**: medium — interactions like "Add to cart" via the form will
  fail if the bundle hasn't loaded yet. Audit every entry point.
* **Estimated gain**: 100-300 KB removed from the initial product
  page payload; Lighthouse mobile score +5-10 points.

### Tier 3 — Bigger architecture change (2-5 days)

#### 3.1 CDN with on-the-fly resize for choice images

Pipe the configurator's image URLs through an image CDN
(Cloudflare Polish, Imagekit, Cloudinary, BunnyCDN Optimizer) so each
swatch is auto-resized + WebP-converted at the edge based on a query
string like `?w=80&q=75&f=webp`.

* **Files**: a single helper in `inc/images.php` (or a filter at
  `mkl_pc/choice_image_url`) that rewrites the URL.
* **Risk**: depends on the CDN. Generally low if you already have
  Cloudflare on `fantinolux.com`.
* **Estimated gain**: same domain, smaller bytes, server-side cache.

#### 3.2 Preconnect / preload the busiest swatch domain

When the page loads, hint the browser to open a connection to the
image CDN ahead of time:

```html
<link rel="preconnect" href="https://fantinolux.com" crossorigin>
```

* **Files**: `inc/frontend/frontend-woocommerce.php load_scripts()` —
  hook on `wp_head` to print the preconnect.
* **Risk**: zero.
* **Estimated gain**: 50-150 ms saved on the first thumbnail batch.

#### 3.3 Service-worker cache for the cached config file + thumbnails

For repeat visitors (e.g. customers iterating on a configuration over
several sessions), a service worker can cache the config JSON and the
thumbnails so that re-opening the configurator is instant.

* **Files**: new asset `assets/js/sw-configurator.js`; bootstrap in
  `frontend-woocommerce.php`.
* **Risk**: medium — service workers add ops complexity. Use only if
  product turnover is low.
* **Estimated gain**: 80-95 % of the configurator becomes a
  zero-network experience on the second visit.

---

## 3. Recommended ship order for *Chemise* (your call)

For the **Chemise / Le Bolide** product specifically, I'd ship in this
order — each step independent of the next so we can measure between
them:

1. **§ 1.1** native `loading="lazy"` on `<img>` tags — *30 min*.
2. **§ 1.4** smaller dedicated swatch thumbnail size (80×80 or 100×100)
   + Regenerate Thumbnails — *1 h + ~10 min one-off run*.
3. **§ 3.2** preconnect hint — *5 min*.
4. **§ 1.5** WebP conversion via existing image-optim plugin (or new
   one) — *15 min if a plugin is already installed*.
5. **§ 1.2** disable hover preload on heavy layers — *1 h*.
6. **Measure with Lighthouse / WebPageTest** — confirm we're hitting
   the targets in § 0 before tackling tier 2.
7. If Lighthouse mobile score is still <80 on Tissus, tackle **§ 2.1**
   (virtualise) and **§ 2.3** (AJAX-load heavy layers).
8. **§ 2.4** (defer configurator JS) is the highest-impact item for the
   product-page itself (not the configurator) — useful for SEO /
   Core Web Vitals and worth doing once the configurator-internal
   work is stable.

---

## 4. Files I'll touch when we proceed

Each section below is the smallest set of files needed for that item.

| Item | Files |
|------|-------|
| § 1.1 | `assets/js/views/parts/choice.js`, `assets/js/views/configurator.js` (the bundled `<img>`-emitting choice render) |
| § 1.2 | `assets/js/views/parts/choice.js`, `inc/admin/settings/layer.php` (new checkbox), `inc/db.php` (new field key) |
| § 1.3 | `inc/frontend/frontend-woocommerce.php` (script enqueue + tag filter) |
| § 1.4 | `inc/plugin.php` or new `inc/admin/image-sizes.php`, `inc/base/choice.php`, README note |
| § 1.5 | server-side / plugin recommendation only |
| § 2.1 | `assets/js/views/parts/choices.js`, `assets/css/configurator-common.css`, possibly a small `assets/js/utils/virtual-scroll.js` |
| § 2.2 | `assets/js/views/parts/choice.js`, `assets/js/views/parts/choices.js` |
| § 2.3 | `inc/cache.php`, new `inc/ajax.php` endpoint, `assets/js/product_configurator.js` |
| § 2.4 | `inc/frontend/frontend-woocommerce.php`, theme integration shim |
| § 3.1 | `inc/images.php`, `inc/base/choice.php`, settings page |
| § 3.2 | `inc/frontend/frontend-woocommerce.php` |
| § 3.3 | new `assets/js/sw-configurator.js`, registration in `frontend-woocommerce.php` |

---

## 5. Out of scope of this plan

* Reducing the *number* of fabric choices in the Tissus layer — that's
  a merchandising decision, not a code one.
* Replacing the entire image-store backend (S3 / Cloudfront) — only
  worth considering after § 3.1 has been measured.
* Migrating the product page to a JS framework — overkill for this fork.

---

## 6. After you've picked

Reply with the item numbers you want shipped first (e.g. *"§ 1.1, 1.2,
1.4, 3.2"*) and I'll implement them in one PR with a single commit per
item, plus a `Performance` section added to `CHANGELOG.md` and a
before/after Lighthouse screenshot in the PR body.
