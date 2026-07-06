# fabrics-assets

Asset host and code repository for the **BIGHOUSEMARKETING** WooCommerce fabric
store. It serves two purposes:

1. **Public image host** — fabric swatch/preview images delivered over the
   jsDelivr CDN and consumed by WooCommerce attribute imports.
2. **Plugin source** — the two custom WooCommerce plugins that power the store's
   product configurator and supplier workflow.

---

## Repository structure

```
fabrics-assets/
├── README.md
│
├── plugins/                              # Custom WooCommerce plugins (PHP)
│   ├── product-configurator-for-woocommerce/
│   └── supplier-production-dashboard/
│
├── chemise-premium/                      # CDN image assets
│   ├── royce-vol-1/
│   └── royce-vol-2/
├── chemise-business/
│   ├── stretch-line/
│   ├── cotton-blend/
│   └── solemnity/
├── costume/
│   ├── massimo-vol-1/
│   ├── massimo-vol-2/
│   ├── massimo-vol-3-jacketing/
│   └── roberto-bellini-x-series/
└── jsons configs/                        # Import/config JSON
```

---

## Plugins

### 1. Product Configurator — BIGHOUSEMARKETING Edition

`plugins/product-configurator-for-woocommerce/`

Lets customers configure and customize products through a live, layer-based
preview. Customised and extended by BEN BIGHOUSEMARKETING from the original free
release by Marc Lacroix, with added addons (conditional logic, option selector,
text overlay, attribute layers, and profile packs).

| | |
|---|---|
| **Version** | 1.5.10-bh.1 |
| **Requires PHP** | 7.4+ |
| **WooCommerce** | requires 8, tested up to 10 |
| **Text Domain** | `product-configurator-for-woocommerce` |
| **License** | GPL-3.0 |

Key folders:

- `inc/` — core PHP (frontend, admin, addons, profile packs)
- `assets/` — CSS/JS for the configurator views and addons
- `languages/` — translations (`.pot` / `.po` / `.mo`)
- `vendor/` — Composer dependencies (shipped so the plugin installs from a ZIP
  without running Composer)
- `docs/`, `*.md` — addon and optimization design notes

### 2. Supplier Production Dashboard

`plugins/supplier-production-dashboard/`

A private production dashboard for suppliers. Surfaces sanitized WooCommerce
order data (what to produce) **without exposing any customer PII**.

| | |
|---|---|
| **Version** | 1.1.0 |
| **Requires PHP** | 8.1+ |
| **WordPress** | requires 6.4 |
| **WooCommerce** | requires 8.0, tested up to 9.0 |
| **Text Domain** | `supplier-production-dashboard` |
| **License** | GPL-2.0-or-later |

Key folders:

- `includes/` — core plugin logic
- `admin/` — admin-side screens
- `supplier/` — the supplier-facing dashboard
- `templates/` — render templates
- `uninstall.php` — cleanup on removal

### Installing a plugin

Copy the plugin folder into your site's `wp-content/plugins/` directory and
activate it from **WP Admin → Plugins**, or zip the folder and upload it via
**Plugins → Add New → Upload Plugin**.

---

## CDN image host

Fabric images are served through jsDelivr using this URL pattern:

```
https://cdn.jsdelivr.net/gh/BIKhorchef/fabrics-assets@master/<category>/<collection>/<code>.webp
```

### Cache busting

jsDelivr caches `@master` for ~12 h. To force a refresh after replacing an
image, request the file with a new query string (`?v=2`) or pin to a tagged
release instead of the branch.

---

## License

The plugins are licensed under the GNU General Public License (v3.0 for the
Product Configurator, v2.0-or-later for the Supplier Production Dashboard).
Fabric image assets are © BIGHOUSEMARKETING.
