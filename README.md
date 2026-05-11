# WP Product Serials

Manages custom post types (Products, Productions, Serials, Campaigns), a frontend registration form, and a user-facing list of registered products.

| | |
|---|---|
| **Slug** | `wp-product-serials` |
| **Version** | 3.5.0 |
| **Author** | IDEAA Lab \| Michael Di Desidero |
| **Requires WP** | 5.8+ |
| **Requires PHP** | 7.4+ |
| **Text Domain** | `ial-reg` |
| **License** | GPL-2.0-or-later |

> Internal code identifiers (functions, classes, constants, text domain) still use the legacy `ial_*` / `IAL_REG_*` / `ial-reg` prefixes. These will be renamed in a future version.

## Features

### Core Architecture (Custom Post Types)

- **Products (`ial_product`)** — abstract product model with frontend visibility toggles, images and AcyMailing list mapping.
- **Productions (`ial_production`)** — production batches linked to a Product. Stores hardware/software versions and production dates.
- **Serials (`ial_serial`)** — individual units. Links a Production to a User at registration time and tracks purchase data (date, retailer).

### Frontend Registration

- Shortcode `[ial_registration_form]` for a secure registration form.
- Validates that the serial exists and matches the selected product.
- Nonce verification + per-IP rate limiting to prevent serial brute-forcing.
- Automatically links the registered Serial to the logged-in user.

### User Dashboard & WooCommerce

- Shortcode `[ial_my_registered_products]` for a grid of products owned by the user.
- Adds a **Your Registered Products** tab to the WooCommerce *My Account* area.

### Admin Tools

- Batch serial generation: paste many serial numbers at once for a given Production.
- Dashboard widget with registration stats (registered vs. not registered) and per-product breakdown using Chart.js.
- Email Campaigns CPT for sending HTML emails to registered users with incremental delivery (skips users already mailed).

### Integrations

- **AcyMailing** — auto-subscribe registered users to a per-Product mailing list.

## Installation

1. Clone or upload this plugin to `wp-content/plugins/wp-product-serials/`:
   ```bash
   git clone https://github.com/ideaalab/wp-product-serials.git wp-content/plugins/wp-product-serials
   ```
   Or download a release ZIP and upload it via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin in **Plugins**.
3. Create a page containing the shortcode `[ial_registration_form]`.
4. Go to **Registrations → Settings** and select the page from step 3.

## Updates

Updates are delivered straight from this GitHub repository through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker). New releases appear in the standard WordPress **Dashboard → Updates** screen — no external service required.

> The auto-updater only takes effect for installs that already include this version. To enable it on existing sites, install this release once manually; subsequent tag pushes will appear as regular WP updates.

## Releasing a new version

1. Bump `Version:` in `wp-product-serials.php` (and `IAL_REG_VERSION`).
2. Commit and push.
3. Tag and push the tag:
   ```bash
   git tag -a vX.Y.Z -m "Release X.Y.Z"
   git push origin vX.Y.Z
   ```
4. The `Release Plugin ZIP` workflow builds `wp-product-serials-vX.Y.Z.zip` and attaches it to the GitHub Release.

## Changelog

### 3.5.0
- Admin menu renamed from **Registrations** to **Product Serials**. The internal slug (`edit.php?post_type=ial_product`) is unchanged, so saved bookmarks keep working. Individual item labels (Product/Products) are unchanged.
- New: **retroactive role application** on the Product edit screen. Shows how many serials of the product are registered vs. unregistered, and a one-click button applies the currently saved roles to every user who registered a serial — additively, idempotent. Useful when a product has existing registrations from before role assignment was configured. AJAX, nonce + cap-checked per product.
- New: **Quick Edit** on the Products list for `frontend_enable`, `acymailing_list_id`, and `assign_roles`. Multi-select for roles uses a native control (compact for the inline UI). Image and notes remain edit-only.
- New: **Bulk Edit** on the Products list for `frontend_enable` and `acymailing_list_id`, both with a "No change" sentinel so untouched fields are preserved.

### 3.4.0
- Shorter, more focused plugin description in the WP plugins list.
- Removed the default `All / Mine / Published` views above the list tables for Products, Productions and Serials. They were unhelpful here and "Mine" could trigger 403s from security plugins blocking `?author=` user enumeration.
- New: **Assigned Roles** column in the Products list table, showing the configured roles per product as chips.
- Improved role selector in the Product metabox: chip-style multi-select with autocomplete dropdown, replacing the checkbox list. Vanilla JS, no extra dependencies.

### 3.3.0
- New: per-product role assignment. Configure one or more WordPress roles in the **Product** metabox; each role is added (additively) to the user when they successfully register a serial of that product.
- New extension hook `ial_user_product_registered` (`$user_id`, `$ial_product_id`) fires after roles are assigned, for third-party integrations (e.g. discount rules based on user role).
- Note: existing registrations are not retroactively granted roles when the configuration changes — only future registrations are affected.

### 3.2.1
- Rebranded plugin and slug to **WP Product Serials** (`wp-product-serials`).
- Bundled GitHub-based auto-updater (plugin-update-checker v5.6).
- Initial GitHub release.

## License

GPL-2.0-or-later.
