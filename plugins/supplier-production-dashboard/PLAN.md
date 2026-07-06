# Supplier Production Dashboard - MVP Technical Plan

---

## 1. Plugin Purpose

**Supplier Production Dashboard** is a private, internal WordPress/WooCommerce plugin that gives a clothing supplier a sanitized, read-only view of WooCommerce orders — stripped of all customer PII (personally identifiable information) — along with editable production-tracking fields.

**Problem it solves:** The site owner sells custom suits and shirts via WooCommerce + Product Configurator. A third-party supplier (tailor/manufacturer) needs to know *what* to produce and track production progress, but must **never** see who the customer is, where they live, or how they paid. Today, the owner would have to manually copy order details into spreadsheets or emails. This plugin eliminates that process and keeps a single source of truth inside WordPress.

**What it is NOT:**
- Not a marketplace or multi-vendor plugin.
- Not a shipping/fulfillment plugin.
- Not a storefront feature — it is purely a back-office tool.

---

## 2. Scope of MVP

### In scope

| Feature | Notes |
|---|---|
| Custom WordPress role `supplier` | Minimal capabilities — no access to native WP admin screens |
| Supplier Dashboard page | Custom admin page showing sanitized order list |
| Order detail view | Expandable/modal view per order with product + customization details |
| Internal production status per order | Editable by supplier |
| Supplier notes per order | Editable by supplier |
| Admin notes per order (read-only for supplier) | Written by admin, visible to supplier |
| Admin settings page | Field mapping configuration, status management |
| Field mapping system | Translate raw WooCommerce/Product Configurator meta keys to human-readable labels |
| PII firewall | Programmatic enforcement — not just UI hiding |
| HPOS compatibility | Use `wc_get_orders()` / WooCommerce CRUD API exclusively |

### Out of scope for MVP

- Email/Slack notifications to supplier on new orders.
- File attachments or image uploads per order.
- Bulk status update UI.
- Export/print functionality.
- Multi-supplier support (assigning orders to different suppliers).
- REST API for external integrations.
- Audit log of supplier actions.
- Localization/i18n (English only for MVP).

---

## 3. What the Supplier Can Do

1. **View** a paginated, filterable list of WooCommerce orders.
2. **See** per order: order number, order date, WooCommerce status, line items (product name, SKU, quantity), customization/configuration options, variation details.
3. **Update** the internal production status of an order (e.g., Pending → Cutting → Sewing → QC → Ready).
4. **Add/edit** supplier notes on an order.
5. **Read** admin notes left by the site owner.
6. **Filter** orders by WooCommerce status, production status, and date range.
7. **Search** orders by order number, product name, or SKU.

---

## 4. What the Supplier Cannot Do

| Restriction | Enforcement mechanism |
|---|---|
| View customer name (billing or shipping) | Data never queried or included in response |
| View customer email, phone, address | Data never queried or included in response |
| View payment method or transaction details | Data never queried or included in response |
| View customer account or user profile | Capability removed; admin menu items hidden |
| Modify WooCommerce order status | No capability; UI does not expose the control |
| Modify order line items, prices, or totals | No capability; UI does not expose the control |
| Edit product data or WooCommerce settings | No capability; menu items hidden |
| Access native WooCommerce orders screen (`edit.php?post_type=shop_order`) | Capability denied + admin menu removed + direct URL access blocked |
| Access any other wp-admin page except the supplier dashboard | Redirect enforced on `admin_init` |
| Delete or cancel orders | No capability |
| View or create customer notes (visible on frontend) | Data excluded from queries |

**Key principle:** Security is enforced at the *data layer*, not just the UI layer. Even if the supplier somehow navigates to a WooCommerce URL, they will either be redirected or see an empty/forbidden page.

---

## 5. Security / Privacy Architecture

### 5.1 Defense in depth — three layers

```
Layer 1: Capability gate
    └─ The `supplier` role lacks all WooCommerce/WordPress capabilities except custom ones.

Layer 2: Menu/URL enforcement
    └─ All wp-admin menu items except the plugin's pages are removed for suppliers.
    └─ Direct URL access to any unauthorized admin page triggers a redirect to the dashboard.

Layer 3: Data sanitization
    └─ The plugin's order-retrieval service NEVER fetches billing/shipping fields.
    └─ Even if a developer accidentally echoes `$order->get_billing_first_name()`, the
       supplier role's restricted capabilities prevent access to the native order screens
       where that function would be called.
    └─ The plugin constructs its own DTO (data transfer object) containing ONLY safe fields.
```

### 5.2 PII fields that must NEVER appear in supplier context

- `billing_first_name`, `billing_last_name`, `billing_company`, `billing_address_1`, `billing_address_2`, `billing_city`, `billing_state`, `billing_postcode`, `billing_country`, `billing_email`, `billing_phone`
- `shipping_first_name`, `shipping_last_name`, `shipping_company`, `shipping_address_1`, `shipping_address_2`, `shipping_city`, `shipping_state`, `shipping_postcode`, `shipping_country`, `shipping_phone`
- `customer_ip_address`, `customer_user_agent`
- `payment_method`, `payment_method_title`, `transaction_id`
- `customer_id`, `customer_note`
- Any order note of type `customer` (only internal notes authored by admin may be shown)

### 5.3 Nonce and permission checks

- Every AJAX/form handler must verify `current_user_can('spd_view_dashboard')` before returning data.
- Every write action must verify `current_user_can('spd_update_production')`.
- All forms use WordPress nonces (via `wp_nonce_field` / `check_ajax_referer`).

### 5.4 Direct SQL prohibition

- The plugin must **never** use `$wpdb` for reading order data. This avoids accidentally selecting PII columns.
- All order reads go through a single service class that calls `wc_get_orders()` and returns sanitized DTOs.

---

## 6. Role and Capability Model

### 6.1 Custom role: `supplier`

Created on plugin activation. Removed on plugin uninstall (not deactivation — to avoid locking out active supplier users on accidental deactivate).

**Base WordPress capabilities granted:**

| Capability | Why |
|---|---|
| `read` | Required to access wp-admin at all |

**Custom capabilities granted:**

| Capability | Purpose |
|---|---|
| `spd_view_dashboard` | Can see the supplier dashboard and order list |
| `spd_view_order_detail` | Can view individual order details (sanitized) |
| `spd_update_production` | Can change production status and supplier notes |

**No other capabilities.** The role inherits nothing from `subscriber`, `contributor`, etc. It is built from scratch with `add_role()`.

### 6.2 Admin capabilities

Administrators automatically receive all `spd_*` capabilities so they can view/test the supplier dashboard. Added via `$admin_role->add_cap()` on activation.

### 6.3 Why a custom role instead of a custom capability on an existing role?

- Using a custom role makes it impossible to accidentally inherit WooCommerce capabilities (`edit_shop_orders`, `view_woocommerce_reports`, etc.) that come with roles like `shop_manager`.
- It is the safest approach for a privacy-sensitive context.
- If multi-supplier is added later, each supplier user account gets this role.

---

## 7. Admin Pages and Supplier Pages

### 7.1 Pages overview

| Page | Slug | Accessible by | Parent menu |
|---|---|---|---|
| Supplier Dashboard (order list) | `spd-dashboard` | `supplier`, `administrator` | Top-level menu item for suppliers; submenu under SPD settings for admins |
| Order Detail View | `spd-order-detail` (with `?order_id=X`) | `supplier`, `administrator` | Not a menu item — navigated to from dashboard |
| SPD Settings | `spd-settings` | `administrator` | Top-level menu "SPD Settings" |
| Field Mapping | `spd-field-mapping` | `administrator` | Submenu under SPD Settings |
| Production Statuses | `spd-statuses` | `administrator` | Submenu under SPD Settings |

### 7.2 Menu behavior by role

**For `supplier` role:**
- All default wp-admin menu items are removed (`remove_menu_page` on `admin_menu` at priority 999).
- Only the "Supplier Dashboard" top-level menu item is shown.
- The admin bar is simplified (only show "Dashboard" link pointing to `spd-dashboard`, logout, and username).

**For `administrator` role:**
- Normal wp-admin menu is untouched.
- An "SPD Settings" top-level menu is added with submenus.
- A "Supplier Dashboard" link is available for preview/testing.

### 7.3 URL guard

On `admin_init`, if `current_user_has_role('supplier')` and the current `$_GET['page']` is not one of the `spd-*` slugs, redirect to `admin_url('admin.php?page=spd-dashboard')`.

This catches:
- Direct URL to `edit.php?post_type=shop_order`
- Direct URL to `profile.php`
- Direct URL to `index.php` (default WP dashboard)
- Any other wp-admin page

---

## 8. Order Retrieval Strategy

### 8.1 API choice: `wc_get_orders()` (CRUD API)

**Why not direct SQL?**
- WooCommerce is migrating order storage from `wp_posts` / `wp_postmeta` to High-Performance Order Storage (HPOS) using custom tables (`wp_wc_orders`, `wp_wc_orders_meta`).
- `wc_get_orders()` abstracts over both storage backends.
- Direct SQL would break on HPOS-enabled sites and would risk selecting PII columns.

**Why not the REST API?**
- The REST API is designed for external/frontend consumption. Using it internally adds unnecessary HTTP overhead.
- `wc_get_orders()` is the recommended approach for plugins running inside WordPress.

### 8.2 Query pattern

```
wc_get_orders( [
    'limit'    => $per_page,       // Paginated — default 20
    'page'     => $current_page,
    'status'   => $status_filter,  // Optional: array of wc statuses
    'orderby'  => 'date',
    'order'    => 'DESC',
    'return'   => 'objects',       // Full WC_Order objects for data extraction
] );
```

### 8.3 Sanitization pipeline

```
WC_Order (full object, contains PII)
    │
    ▼
OrderSanitizer::to_supplier_dto( $order )
    │  - Extracts ONLY: id, number, date_created, status, line items
    │  - For each line item: name, SKU, quantity, meta (filtered through FieldMapper)
    │  - Attaches: production status, supplier notes, admin notes (from plugin's own storage)
    │  - Returns: SPD_Order_DTO object
    ▼
SPD_Order_DTO (safe — no PII, ready for template rendering)
```

**The `WC_Order` object never reaches the template layer.** This is the critical security boundary.

### 8.4 Search

- By order number: `wc_get_orders( ['search' => $order_number] )` — WooCommerce handles this.
- By product name/SKU: Requires a custom query modifier via `woocommerce_order_query_args` filter, or a two-step approach (find product IDs first, then find orders containing those items). The two-step approach is recommended for MVP simplicity and HPOS compatibility.

---

## 9. How Order Item Customization Data Should Be Read

### 9.1 The challenge

Product Configurator for WooCommerce stores customization options as **order item meta**. The exact meta keys depend on:
- The configurator plugin version.
- The product configuration setup (layers, groups, options).
- Whether the data was saved as individual meta entries or a serialized array.

Common patterns observed in Product Configurator:
- Individual meta keys like `_tmcp_*`, `_vpc_*`, or human-readable keys like `Fabric`, `Lining Color`.
- A single serialized meta key containing all configuration data (e.g., `_vpc_configuration`).
- WooCommerce built-in variation attributes stored as `pa_*` meta keys.

### 9.2 Reading strategy

For each `WC_Order_Item_Product` in the order:

1. **Get all visible meta:** `$item->get_formatted_meta_data('')` returns meta entries excluding internal (prefixed with `_`) keys. This handles standard variation attributes and any human-readable custom meta.

2. **Get hidden meta by known keys:** For Product Configurator data stored with `_` prefix, read specific keys using `$item->get_meta('_vpc_configuration')` (or equivalent). The exact keys are defined in the **field mapping settings**.

3. **Pass through FieldMapper:** The FieldMapper translates raw keys and values into supplier-friendly labels using the admin-configured mapping table.

### 9.3 FieldMapper pipeline

```
Raw item meta: { "_vpc_config_fabric": "ITAL-450-NVY", "pa_size": "42R" }
                              │
                              ▼
FieldMapper applies mapping rules:
    "_vpc_config_fabric"  →  label: "Fabric", value transform: lookup "ITAL-450-NVY" → "Italian Wool 450g Navy"
    "pa_size"             →  label: "Size", value kept as-is: "42R"
                              │
                              ▼
Supplier sees: Fabric: Italian Wool 450g Navy | Size: 42R
```

### 9.4 Fallback behavior

- If a meta key has no mapping defined, display it as-is (raw key : raw value) with a visual indicator that it's unmapped.
- The admin can see unmapped keys on the settings page and create mappings for them.
- This ensures no information is silently dropped — the supplier always sees all customization data, even if labels are not yet configured.

---

## 10. Data Model

### 10.1 Entities

| Entity | Description | Storage |
|---|---|---|
| Production Status (per order) | The internal production stage of an order | Order meta (WooCommerce order meta API) |
| Supplier Notes (per order) | Free-text notes written by the supplier | Order meta |
| Admin Notes (per order) | Free-text notes written by the admin for the supplier | Order meta |
| Production Status Definitions | The list of available statuses and their order | WordPress options table |
| Field Mapping Rules | How to translate raw meta keys to labels | WordPress options table |
| Plugin Settings | General plugin configuration | WordPress options table |

### 10.2 Meta keys used

| Meta key | Stored on | Written by | Read by |
|---|---|---|---|
| `_spd_production_status` | WC Order | Supplier (via plugin), Admin | Supplier, Admin |
| `_spd_supplier_notes` | WC Order | Supplier (via plugin) | Supplier, Admin |
| `_spd_admin_notes` | WC Order | Admin (via plugin) | Supplier, Admin |
| `_spd_production_status_updated_at` | WC Order | Plugin (automatic) | Supplier, Admin |

### 10.3 Options keys used

| Option key | Content |
|---|---|
| `spd_production_statuses` | Serialized array of status definitions: `[ ['slug' => 'pending', 'label' => 'Pending', 'color' => '#999'], ... ]` |
| `spd_field_mappings` | Serialized array of mapping rules: `[ ['meta_key' => '_vpc_config_fabric', 'label' => 'Fabric', 'value_map' => [...]], ... ]` |
| `spd_settings` | General settings: default status, orders per page, etc. |
| `spd_version` | Plugin version — used for upgrade routines |

---

## 11. Storage Strategy: Custom Tables vs. Post Meta vs. Options

### 11.1 Decision matrix

| Data | Custom table? | Order meta? | Options? | Chosen | Rationale |
|---|---|---|---|---|---|
| Production status per order | Possible but overkill | **Yes** | No | **Order meta** | One value per order. WooCommerce meta API handles HPOS transparently. No need for complex queries across production statuses (simple `meta_query` with `wc_get_orders` suffices). |
| Supplier notes per order | Possible | **Yes** | No | **Order meta** | Same reasoning as above. |
| Admin notes per order | Possible | **Yes** | No | **Order meta** | Same reasoning. Distinct from WooCommerce's own order notes system to avoid PII leakage (WC order notes can contain customer-facing data). |
| Production status definitions | No | No | **Yes** | **Options** | Small, site-wide configuration data. Rarely changes. Autoloaded. |
| Field mappings | No | No | **Yes** | **Options** | Configuration data. Could grow to ~50–100 entries but still well within options table suitability. |
| Plugin settings | No | No | **Yes** | **Options** | Standard WP practice for plugin settings. |

### 11.2 Why NOT custom tables for MVP?

- Custom tables add complexity: custom install/upgrade routines, manual `$wpdb` queries (which we want to avoid), no integration with WooCommerce's query API.
- The data model is simple — a few meta values per order.
- If performance becomes an issue at scale (thousands of orders with frequent filtering by production status), a custom table can be introduced in a future version with a migration path.

### 11.3 Why NOT use WooCommerce's built-in order notes?

- WC order notes are typed as `customer` (visible on frontend) or internal. But they contain HTML, may include PII references, and are displayed on the native order edit screen.
- Using a separate meta field (`_spd_supplier_notes`, `_spd_admin_notes`) keeps the plugin's data isolated and avoids any chance of PII leaking through note content authored by other plugins or the WooCommerce system itself.

---

## 12. Internal Production Workflow Statuses

### 12.1 Default statuses (configurable by admin)

| Slug | Label | Color | Description |
|---|---|---|---|
| `pending` | Pending | `#999999` | Order received, not yet started |
| `in-production` | In Production | `#2196F3` | Production has started |
| `cutting` | Cutting | `#FF9800` | Fabric cutting in progress |
| `sewing` | Sewing | `#9C27B0` | Assembly/sewing in progress |
| `quality-check` | Quality Check | `#FFC107` | QC inspection |
| `ready` | Ready to Ship | `#4CAF50` | Complete, awaiting pickup/shipment |
| `on-hold` | On Hold | `#F44336` | Paused — needs clarification or materials |

### 12.2 Behavior

- Admin can add, rename, reorder, and delete statuses via the settings page.
- Deleting a status that is in use on existing orders will prompt the admin to reassign those orders to a different status.
- New WooCommerce orders automatically receive the first status in the list (default: `pending`).
- Production status is **independent** of WooCommerce order status. They are two separate fields. The WooCommerce status (processing, completed, etc.) is displayed read-only for reference.

### 12.3 Why not use WooCommerce custom order statuses?

- WooCommerce order statuses are visible throughout the WC ecosystem (reports, emails, REST API, other plugins). Adding production-specific statuses there would pollute the WC status workflow.
- Production status is an internal concern of the supplier — it should not trigger WC status-based emails or affect WC reports.
- Keeping it as a separate meta field is cleaner and safer.

---

## 13. Notes System

### 13.1 Supplier notes

- Free-text field per order.
- Editable by any user with `spd_update_production` capability.
- Stored in `_spd_supplier_notes` order meta.
- **Format:** Plain text (no HTML) — sanitized with `sanitize_textarea_field()` on save.
- Displayed as a simple textarea on the order detail view.
- Timestamp of last edit stored alongside for reference.

### 13.2 Admin notes

- Free-text field per order.
- Editable only by administrators (via the SPD admin panel or the order detail view when logged in as admin).
- Read-only for suppliers.
- Stored in `_spd_admin_notes` order meta.
- **Format:** Plain text — same sanitization.
- Displayed in a visually distinct block (e.g., different background color) on the supplier order detail view.

### 13.3 Why single note field instead of threaded notes?

- MVP simplicity. A single note field per role per order is sufficient for production coordination.
- Threaded/timestamped note history can be added post-MVP if needed (would require a custom table or serialized meta).

---

## 14. Settings / Field Mapping System

### 14.1 Settings page (admin only)

**General settings:**

| Setting | Type | Default | Purpose |
|---|---|---|---|
| Orders per page | Integer | 20 | Pagination size on supplier dashboard |
| Default production status | Dropdown | `pending` | Auto-assigned to new orders |
| Show order totals to supplier | Boolean | `false` | Whether to display price/total columns. Default off for privacy. |
| Excluded WooCommerce statuses | Multi-checkbox | `['cancelled', 'refunded', 'failed']` | Orders with these WC statuses are hidden from supplier |

### 14.2 Field mapping page (admin only)

The field mapping system is the critical bridge between raw WooCommerce/Product Configurator data and the supplier-friendly dashboard.

**Mapping table structure:**

| Column | Description |
|---|---|
| Meta key | The raw WooCommerce order item meta key (e.g., `_vpc_config_fabric`, `pa_size`) |
| Display label | The human-readable label (e.g., "Fabric", "Size") |
| Value mapping (optional) | JSON/table of `raw_value → display_value` pairs (e.g., `ITAL-450-NVY → Italian Wool 450g Navy`) |
| Display order | Integer for sorting the options in a consistent order for the supplier |
| Visible | Boolean — allows hiding certain meta keys from the supplier |

**Discovery feature:** The admin settings page should include a "Scan Recent Orders" button that:
1. Reads the last N orders (e.g., 50).
2. Collects all unique item meta keys found.
3. Shows unmapped keys so the admin can create mappings.

This makes initial setup practical — the admin does not need to know the internal key names used by Product Configurator.

### 14.3 Why store mappings in options instead of a config file?

- Site admins need a UI to manage mappings — options are easily read/written from settings pages.
- Different sites will have different Product Configurator setups — this must be configurable per installation.
- A config file (e.g., JSON in the plugin directory) would be overwritten on plugin updates.

---

## 15. UI Flow for Supplier Dashboard

### 15.1 Login

1. Supplier logs into WordPress with their `supplier` role account.
2. On wp-admin load, the URL guard redirects them to `admin.php?page=spd-dashboard`.
3. The wp-admin sidebar shows only the "Production Dashboard" menu item.
4. The admin bar shows only: site name, username, logout link.

### 15.2 Order list page

```
┌────────────────────────────────────────────────────────────────┐
│  Production Dashboard                                          │
├────────────────────────────────────────────────────────────────┤
│  Filters: [WC Status ▼] [Production Status ▼] [Date Range]    │
│           [Search: order #, product, SKU __________] [Search]  │
├──────┬──────────┬──────────┬──────────┬──────┬─────────────────┤
│  #   │ Date     │ WC Status│ Products │ Qty  │ Prod. Status    │
├──────┼──────────┼──────────┼──────────┼──────┼─────────────────┤
│ 1042 │ Apr 5    │ Processing│ Custom..│ 1    │ ● Cutting       │
│ 1041 │ Apr 4    │ Processing│ Custom..│ 2    │ ● Pending       │
│ 1039 │ Apr 3    │ On Hold  │ Custom..│ 1    │ ● On Hold       │
│  ... │  ...     │  ...     │  ...    │ ...  │  ...            │
├──────┴──────────┴──────────┴──────────┴──────┴─────────────────┤
│  ◄ 1 2 3 ... 12 ►                          Showing 1-20 of 235│
└────────────────────────────────────────────────────────────────┘
```

- Each row is clickable → navigates to order detail view.
- Production status is shown as a colored badge.
- "Products" column shows first product name, with "+N more" if multiple items.

### 15.3 Order detail page

```
┌────────────────────────────────────────────────────────────────┐
│  ← Back to Dashboard              Order #1042 — Apr 5, 2026   │
│                                    WC Status: Processing       │
├────────────────────────────────────────────────────────────────┤
│  LINE ITEMS                                                    │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Custom 2-Piece Suit  |  SKU: SUIT-2PC  |  Qty: 1        │  │
│  │                                                          │  │
│  │ Customization Details:                                   │  │
│  │   Fabric:        Italian Wool 450g Navy                  │  │
│  │   Lining:        Burgundy Satin                          │  │
│  │   Buttons:       Horn — Dark Brown                       │  │
│  │   Monogram:      Yes — Inside Jacket                     │  │
│  │   Fit:           Slim                                    │  │
│  │   Size:          42R                                     │  │
│  │                                                          │  │
│  │ Variation: Slim Fit / Navy / 42R                         │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                │
│  PRODUCTION STATUS                                             │
│  [Cutting ▼]  [Update]                                         │
│  Last updated: Apr 5, 2026 14:30                               │
│                                                                │
│  ADMIN NOTES (read-only)                                       │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Rush order — customer needs by Apr 15.                   │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                │
│  SUPPLIER NOTES                                                │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Fabric in stock. Starting cut tomorrow morning.          │  │
│  │                                                          │  │
│  └──────────────────────────────────────────────────────────┘  │
│  [Save Notes]                                                  │
└────────────────────────────────────────────────────────────────┘
```

### 15.4 Interactions

- **Update production status:** Dropdown + "Update" button. AJAX call to `admin-ajax.php` with nonce.
- **Save supplier notes:** Textarea + "Save" button. AJAX call with nonce.
- Both actions show inline success/error feedback without full page reload.

---

## 16. UI Flow for Admin

### 16.1 SPD Settings page

- **General tab:** Plugin settings (orders per page, default status, show totals toggle, excluded statuses).
- **Production Statuses tab:** Sortable list of statuses. Add/edit/delete with inline editing. Drag to reorder.
- **Field Mapping tab:** Table of meta key → label mappings. Add/edit/delete rows. "Scan Recent Orders" button to discover unmapped keys.

### 16.2 Admin order view integration

- On the native WooCommerce order edit screen, add a **metabox** titled "Supplier Production" that shows:
  - Current production status (editable).
  - Supplier notes (read-only — admin can see what supplier wrote).
  - Admin notes field (editable).
- This allows the admin to manage production data without leaving their normal workflow.

### 16.3 Admin supplier dashboard access

- Admin can click "View Supplier Dashboard" from the SPD Settings page to see exactly what the supplier sees. Useful for testing field mappings and verifying no PII is exposed.

---

## 17. Recommended Folder Structure

```
supplier-production-dashboard/
├── supplier-production-dashboard.php   # Main plugin file (bootstrap, constants, hooks)
├── uninstall.php                       # Clean uninstall logic (remove roles, options, meta)
├── readme.txt                          # WordPress plugin readme (standard format)
├── PLAN.md                             # This document
│
├── includes/
│   ├── class-spd-activator.php         # Activation logic (create role, set defaults)
│   ├── class-spd-deactivator.php       # Deactivation logic (minimal — don't remove data)
│   ├── class-spd-role-manager.php      # Role/capability creation and management
│   ├── class-spd-order-service.php     # Order retrieval + sanitization (core security boundary)
│   ├── class-spd-order-dto.php         # Data transfer object — sanitized order data
│   ├── class-spd-field-mapper.php      # Meta key → label translation
│   ├── class-spd-production-service.php# Production status + notes CRUD
│   ├── class-spd-access-control.php    # URL guard, menu removal, redirect logic
│   └── class-spd-settings.php          # Settings API registration and retrieval
│
├── admin/
│   ├── class-spd-admin-menu.php        # Admin menu registration
│   ├── class-spd-admin-settings-page.php   # Settings page renderer
│   ├── class-spd-admin-metabox.php     # WooCommerce order edit screen metabox
│   ├── css/
│   │   └── spd-admin.css
│   └── js/
│       └── spd-admin.js
│
├── supplier/
│   ├── class-spd-supplier-menu.php     # Supplier menu registration
│   ├── class-spd-supplier-dashboard.php# Dashboard page controller
│   ├── class-spd-supplier-order-detail.php # Order detail page controller
│   ├── class-spd-supplier-ajax.php     # AJAX handlers for supplier actions
│   ├── css/
│   │   └── spd-supplier.css
│   └── js/
│       └── spd-supplier.js
│
└── templates/
    ├── supplier/
    │   ├── dashboard.php               # Order list template
    │   └── order-detail.php            # Single order detail template
    └── admin/
        ├── settings-general.php
        ├── settings-statuses.php
        ├── settings-field-mapping.php
        └── metabox-production.php
```

### 17.1 Why this structure?

- **`includes/`** contains all business logic — models, services, security. No rendering here.
- **`admin/`** contains admin-specific controllers, assets, and page registrations.
- **`supplier/`** contains supplier-specific controllers and assets — cleanly separated from admin.
- **`templates/`** contains HTML templates — separated from logic for maintainability.
- This follows WordPress plugin conventions and keeps the security boundary (OrderService/DTO) in a clear, central location.

---

## 18. Classes / Services / Modules Needed

### 18.1 Core classes

| Class | Responsibility | Key methods |
|---|---|---|
| `SPD_Activator` | Runs on `register_activation_hook`. Creates role, adds caps, sets default options. | `activate()` |
| `SPD_Deactivator` | Runs on `register_deactivation_hook`. Minimal — does not remove data. | `deactivate()` |
| `SPD_Role_Manager` | Creates/removes the `supplier` role. Adds `spd_*` caps to admin. | `create_role()`, `remove_role()`, `add_admin_caps()` |
| `SPD_Access_Control` | Enforces URL guard, removes menu items, simplifies admin bar for suppliers. | `enforce_access()`, `remove_menus()`, `simplify_admin_bar()` |
| `SPD_Order_Service` | **The security boundary.** Queries WooCommerce orders and returns `SPD_Order_DTO` objects — never raw `WC_Order` objects to supplier context. | `get_orders( $args )`, `get_order( $id )`, `sanitize_order( $wc_order )` |
| `SPD_Order_DTO` | Plain data object with only safe fields. Immutable once constructed. | Properties: `$id`, `$number`, `$date`, `$status`, `$items[]`, `$production_status`, `$supplier_notes`, `$admin_notes` |
| `SPD_Order_Item_DTO` | Per-item data object. | Properties: `$name`, `$sku`, `$quantity`, `$customizations[]`, `$variation_summary` |
| `SPD_Field_Mapper` | Translates raw item meta keys/values to supplier-friendly labels. | `map_item_meta( $raw_meta )`, `get_unmapped_keys( $orders )` |
| `SPD_Production_Service` | CRUD for production status, supplier notes, admin notes on orders. | `get_status( $order_id )`, `update_status( $order_id, $status )`, `get_notes( $order_id )`, `update_notes( $order_id, $notes )` |
| `SPD_Settings` | Reads/writes plugin settings from options table. Registers settings via Settings API. | `get( $key )`, `get_all()`, `get_statuses()`, `get_field_mappings()` |

### 18.2 Page controllers

| Class | Responsibility |
|---|---|
| `SPD_Admin_Menu` | Registers admin menu pages via `add_menu_page` / `add_submenu_page` |
| `SPD_Admin_Settings_Page` | Renders settings tabs, handles form submissions |
| `SPD_Admin_Metabox` | Adds and renders the production metabox on WC order edit screen |
| `SPD_Supplier_Menu` | Registers supplier menu page |
| `SPD_Supplier_Dashboard` | Handles the order list page — queries, pagination, filtering |
| `SPD_Supplier_Order_Detail` | Handles the single order detail page |
| `SPD_Supplier_Ajax` | Handles AJAX requests: update status, save notes |

### 18.3 Autoloading

Use a simple `spl_autoload_register` in the main plugin file, mapping `SPD_*` class names to file paths. No Composer autoloader needed for MVP (avoids dependency on Composer for a self-contained WP plugin).

---

## 19. Hooks / Events Needed

### 19.1 WordPress / WooCommerce hooks the plugin will use

| Hook | Type | Purpose |
|---|---|---|
| `register_activation_hook` | Action | Run `SPD_Activator::activate()` |
| `register_deactivation_hook` | Action | Run `SPD_Deactivator::deactivate()` |
| `admin_menu` | Action | Register menu pages (both admin and supplier) |
| `admin_init` | Action | URL guard enforcement for supplier role |
| `admin_bar_menu` | Action | Simplify admin bar for supplier role |
| `admin_enqueue_scripts` | Action | Enqueue CSS/JS on SPD pages only |
| `wp_ajax_spd_update_status` | Action | Handle production status update AJAX |
| `wp_ajax_spd_save_notes` | Action | Handle supplier notes save AJAX |
| `add_meta_boxes` | Action | Register production metabox on WC order screen |
| `woocommerce_process_shop_order_meta` | Action | Save admin notes from metabox |
| `woocommerce_new_order` | Action | Set default production status on new orders |
| `show_admin_bar` | Filter | Optionally hide admin bar on frontend for suppliers |
| `login_redirect` | Filter | Redirect supplier to dashboard after login |
| `plugin_action_links_{plugin}` | Filter | Add "Settings" link on plugins page |

### 19.2 Custom hooks the plugin will fire

| Hook | Type | Purpose |
|---|---|---|
| `spd_production_status_changed` | Action | Fired after production status is updated. Args: `$order_id`, `$old_status`, `$new_status`. For future notification integrations. |
| `spd_supplier_notes_updated` | Action | Fired after supplier notes are saved. Args: `$order_id`. |
| `spd_before_order_sanitize` | Filter | Allows other plugins to add/modify the safe fields before DTO construction. |
| `spd_order_item_meta_display` | Filter | Allows modification of mapped meta display before rendering. |

---

## 20. Risks and Edge Cases

### 20.1 High severity

| Risk | Impact | Mitigation |
|---|---|---|
| **PII leakage through order item meta** | Customer data could be stored in item meta by other plugins (e.g., gift messages, custom text inputs) | The FieldMapper must support a **whitelist mode** (only show mapped keys) as an alternative to showing all meta. Admin should choose one mode in settings. Default to whitelist for maximum safety. |
| **PII leakage through product names** | Product names might contain customer info if the site uses "personalized" product titles | Document this risk. Admin should review product naming conventions. Plugin cannot auto-detect this. |
| **HPOS incompatibility** | If the plugin uses any direct SQL or relies on `wp_posts` for orders | Strictly use `wc_get_orders()` and WC_Order CRUD methods. No `$wpdb`. Test with HPOS enabled. |
| **Role removal on uninstall locks out supplier users** | If the role is removed but user accounts still exist, those users cannot log in | On uninstall, reassign supplier users to `subscriber` role before removing the `supplier` role. |

### 20.2 Medium severity

| Risk | Impact | Mitigation |
|---|---|---|
| **Product Configurator changes meta key format** | Field mappings break after a plugin update | The "Scan Recent Orders" feature allows admin to re-discover keys. Unmapped keys are shown with a visual indicator, not silently dropped. |
| **Large order volumes (1000+)** | Dashboard becomes slow | Pagination (default 20/page), database indexes on meta keys via WooCommerce query API. No unbounded queries. |
| **Concurrent supplier edits** | Two supplier users update the same order simultaneously | MVP: last-write-wins (acceptable for single-supplier context). Post-MVP: optimistic locking with timestamp check. |
| **Plugin conflict with security plugins** | Security plugins may block AJAX or restrict admin access | Use standard WordPress AJAX patterns (`admin-ajax.php`, nonces). Document known conflicts. |

### 20.3 Low severity / edge cases

| Risk | Impact | Mitigation |
|---|---|---|
| **Supplier bookmarks the WP dashboard URL** | Gets redirected — minor confusion | Redirect shows a brief admin notice: "You have been redirected to the Production Dashboard." |
| **Order has zero line items** | Empty item section | Graceful handling with "No items found" message. |
| **Product deleted after order placed** | Product name/SKU may be unavailable from product object | Order item stores product name and meta at time of purchase — use `$item->get_name()` rather than `$item->get_product()->get_name()`. |
| **Admin forgets to configure field mappings** | Supplier sees raw meta keys | Unmapped mode shows raw keys with a notice to admin on the settings page. |

---

## 21. MVP Roadmap by Phases

### Phase 1: Foundation (Days 1–2)

- [ ] Plugin boilerplate: main file, activation/deactivation hooks, autoloader.
- [ ] `SPD_Role_Manager`: create `supplier` role with custom capabilities.
- [ ] `SPD_Access_Control`: URL guard, menu removal, admin bar simplification.
- [ ] Verify: supplier user can log in, is redirected to a placeholder page, cannot access any native wp-admin pages.

### Phase 2: Order Data Layer (Days 3–4)

- [ ] `SPD_Order_Service`: query orders via `wc_get_orders()`, construct DTOs.
- [ ] `SPD_Order_DTO` and `SPD_Order_Item_DTO`: define safe data structures.
- [ ] `SPD_Field_Mapper`: basic meta key → label mapping using hardcoded defaults.
- [ ] `SPD_Production_Service`: CRUD for production status and notes in order meta.
- [ ] Unit testing: verify DTOs contain no PII fields, verify sanitization pipeline.

### Phase 3: Supplier Dashboard UI (Days 5–7)

- [ ] `SPD_Supplier_Dashboard`: order list page with pagination.
- [ ] `SPD_Supplier_Order_Detail`: single order view with customization details.
- [ ] `SPD_Supplier_Ajax`: AJAX handlers for status update and notes save.
- [ ] CSS styling: clean, functional dashboard (not necessarily pretty — functional first).
- [ ] Filters: WC status, production status, date range, search.

### Phase 4: Admin Settings & Metabox (Days 8–9)

- [ ] `SPD_Settings`: register and retrieve plugin options.
- [ ] `SPD_Admin_Settings_Page`: general settings tab + production statuses tab + field mapping tab.
- [ ] "Scan Recent Orders" feature for discovering unmapped meta keys.
- [ ] `SPD_Admin_Metabox`: production info on WC order edit screen.

### Phase 5: Polish & Security Audit (Day 10)

- [ ] Full security review: attempt to access PII as supplier role (manual test).
- [ ] Test with HPOS enabled and disabled.
- [ ] Test with Product Configurator: verify customization data renders correctly.
- [ ] Edge case testing: empty orders, deleted products, no field mappings configured.
- [ ] Code review for `sanitize_*` / `esc_*` / nonce usage.

---

## 22. Acceptance Criteria

### 22.1 Security criteria (MUST pass — non-negotiable)

- [ ] A user with the `supplier` role cannot view any customer PII in any context (dashboard, AJAX responses, page source, browser dev tools network tab).
- [ ] A supplier user accessing `edit.php?post_type=shop_order` is redirected to the SPD dashboard.
- [ ] A supplier user accessing `profile.php`, `users.php`, or any non-SPD admin page is redirected.
- [ ] All AJAX handlers verify nonce and capability before processing.
- [ ] The `SPD_Order_DTO` class does not contain any PII field, property, or method.
- [ ] No raw `WC_Order` object reaches any template file in the `supplier/` directory.

### 22.2 Functional criteria

- [ ] Supplier can view a paginated list of orders with order number, date, WC status, product names, quantities, and production status.
- [ ] Supplier can click an order to see full detail including customization options.
- [ ] Supplier can update production status via dropdown. Change persists and is visible on page reload.
- [ ] Supplier can write and save notes. Notes persist and are visible on page reload.
- [ ] Admin notes are visible to supplier as read-only.
- [ ] Admin can configure production statuses (add, edit, reorder, delete).
- [ ] Admin can configure field mappings (add, edit, delete, scan for unmapped keys).
- [ ] Admin can see and edit production status + admin notes on the WC order edit screen.
- [ ] New orders automatically get the default production status.
- [ ] Filter by WC status and production status works correctly.
- [ ] Search by order number works correctly.

### 22.3 Compatibility criteria

- [ ] Works with WooCommerce HPOS enabled.
- [ ] Works with WooCommerce HPOS disabled (legacy `wp_posts` storage).
- [ ] Works with WordPress 6.4+ and WooCommerce 8.0+.
- [ ] Does not produce PHP errors/warnings/notices on PHP 8.1+.
- [ ] Does not break when Product Configurator is deactivated (customization column shows raw data or "No configuration data").

---

## 23. Future Improvements After MVP

| Feature | Priority | Rationale |
|---|---|---|
| **Email notifications** | High | Notify supplier on new orders, notify admin on production status change. Use WooCommerce email system or `wp_mail`. |
| **Bulk status update** | High | Select multiple orders → set same production status. Saves time for large batches. |
| **Export to CSV/PDF** | Medium | Supplier may need offline production sheets. Generate sanitized reports (no PII). |
| **Order timeline/activity log** | Medium | Track all production status changes with timestamps. Requires custom table or serialized meta. |
| **Multi-supplier support** | Medium | Assign orders/items to different suppliers. Requires supplier taxonomy or user assignment on order items. |
| **Print-friendly order detail** | Medium | CSS print stylesheet for clean printout of order specs. |
| **REST API** | Low | External integrations (ERP, production management software). Must enforce same PII restrictions. |
| **File attachments** | Low | Upload reference images, fabric swatches, measurement sheets per order. |
| **Supplier-to-admin messaging** | Low | Threaded conversation per order, replacing free-text notes. |
| **Dashboard analytics** | Low | Charts showing orders by status, average production time, completion rates. |
| **Webhook / Zapier integration** | Low | Fire webhooks on status changes for external automation. |
| **i18n / translation** | Low | Internationalize all user-facing strings for multilingual teams. |

---

## Appendix A: Configuration Data Discovery Checklist

Before deploying, the admin must:

1. [ ] Create at least one WordPress user with the `supplier` role.
2. [ ] Place a test order through the full WooCommerce + Product Configurator flow.
3. [ ] Use "Scan Recent Orders" on the Field Mapping page to discover meta keys.
4. [ ] Map all relevant meta keys to human-readable labels.
5. [ ] Decide on whitelist vs. show-all mode for item meta display.
6. [ ] Review production statuses and adjust to match actual workflow.
7. [ ] Log in as the supplier user and verify the dashboard is correct and contains no PII.

---

## Appendix B: Glossary

| Term | Meaning |
|---|---|
| **HPOS** | High-Performance Order Storage — WooCommerce's custom table storage for orders (replacing `wp_posts`/`wp_postmeta`) |
| **PII** | Personally Identifiable Information — any data that could identify a customer |
| **DTO** | Data Transfer Object — a simple object carrying data between processes, with no business logic |
| **Product Configurator** | "Product Configurator for WooCommerce" — third-party plugin for visual product customization |
| **WC** | WooCommerce |
| **SPD** | Supplier Production Dashboard (this plugin) |
| **Item meta** | Key-value data attached to individual line items within a WooCommerce order |
| **Order meta** | Key-value data attached to the WooCommerce order itself |
