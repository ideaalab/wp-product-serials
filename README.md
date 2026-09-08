# WP Product Serials

Manages custom post types (Products, Productions, Serials, Campaigns), a frontend registration form, and a user-facing list of registered products.

| | |
|---|---|
| **Slug** | `wp-product-serials` |
| **Version** | 3.8.0 |
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
- Shortcode `[ial_product_collection]` for the collection panel: tier progress plus every product, with the ones not yet registered dimmed and linked to the shop.

### Loyalty Discount (WooCommerce)

- Automatic percentage discount for customers, based on how many products they have registered.
- Tiers are fully configurable in **Product Serials → Settings**: as many layers as you want (one flat 10%, or 5% → 10% → 15%, or anything else), each with its own threshold, percentage and optional name.
- Levelling criterion is a dropdown: *different products registered* or *serials registered (units)*.
- **It never stacks.** On each cart line the bigger of the price everything else in the shop settled on — sale price, quantity discount, dynamic pricing plugin — and the loyalty discount wins; against a coupon the customer types, the bigger of the two wins and the loser is dropped with an explanatory notice.
- Runs last on `woocommerce_before_calculate_totals` (priority 9999, filterable via `ial_loyalty_price_priority`) precisely so that comparison holds: at an earlier priority another pricing plugin would take the already-discounted price as its base and the two would multiply.
- Scope control: an include/exclude toggle plus two lists — product categories and individual products. *Everywhere except what I pick* keeps the discount off a few things (outlet, spare parts, thin margins); *only on what I pick* restricts it to a chosen range. Categories cover everything filed underneath them, the two lists add up, and a variation is matched by its own ID or its parent's.
- Global cap as the other margin safety net.
- Applied by overriding the cart line price, so WooCommerce computes taxes from each product's own tax class. The discount amount and level are recorded on the order (`_ial_loyalty_percent`, `_ial_loyalty_discount_total`) and on each line item, since a line-price discount does not show up in WooCommerce's discount reports.
- Catalogue prices are deliberately untouched: the product page shows a notice ("you have X% off, applied in the cart"), so page caching stays safe.

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

### 3.8.0
- The category exclusion list is now a full **scope filter**: a toggle between *everywhere except what I pick* and *only on what I pick*, plus two lists — product categories and individual products, both searchable multi-selects. The two lists add up: a product matches if it is named by either.
- Categories match hierarchically. Picking a parent category covers every subcategory under it, which is what the setting looks like it promises — `has_term()` on its own only matches terms assigned directly to the product, and shops normally file a product under its leaf category alone.
- Variations are matched by their own ID or by their parent's, so selecting a variable product in the settings covers all of its variations, and a single variation can be singled out.
- On *only on what I pick* with both lists empty, nothing qualifies and no discount is applied — the literal reading of an empty allow-list. Saving that combination shows a warning on the settings screen explaining it.
- Existing `excluded_cats` settings from 3.7.x are read as an exclude filter, so upgrading changes nothing; the next save writes the current shape.
- New filter `ial_loyalty_product_qualifies` (`$qualifies`, `$product`) for full control over which products the discount applies to. `ial_loyalty_exclude_product` still works as a veto that can only take the discount away.
- The **Registrar nuevo producto** action in *My Account* is now a real button — solid background, its own padding and a `+` glyph — instead of inheriting whatever the theme does with `.button`, which rendered it as plain text and made it easy to miss.

### 3.7.1
- Fix: the registration form could report **"Este número de serie ya ha sido registrado"** for a registration that had in fact just succeeded. The form processed the POST inside the shortcode render and never redirected, so a double click, an F5 on the result page, a *Back → resend*, or a theme/page builder rendering the content twice all ran the submission a second time: the first pass registered the serial, the second found it taken and printed the error over it.
  - The submission is now handled once per request on `template_redirect`, before anything is rendered, and answered with a redirect (post/redirect/get). Reloading the result page no longer resubmits anything. The outcome travels in a short-lived transient referenced by a token in the URL, so the message and the repopulated fields survive the redirect.
  - A serial that is already registered **to the customer's own account** is no longer an error: it now shows a neutral "ya está registrado en tu cuenta" notice with a link to their products. When it belongs to *another* account the error says so explicitly, which is what support needs to tell the two cases apart.
  - The submit button disables itself on the first click (labelled *Registrando…*) and a second submit of the same form is blocked, so the slow part of a registration (mailing list, roles) can no longer be double-fired.
- New: a settled registration lands on the customer's own products panel (*Mi cuenta → Tus productos registrados*) instead of leaving them staring at an empty form, with the confirmation printed above their products — the product they just registered is right there, which is the answer to "did it work?". This covers both a new registration and a serial that was already theirs. Anything they have to correct keeps them on the form with the fields they typed. Falls back to the form page when there is no panel (no WooCommerce); `ial_registration_success_redirect` points it anywhere else, or to the form by returning an empty string.
- Fix: registering while logged out silently did nothing useful — the serial was stamped with the buyer's name, purchase date and seller but linked to nobody, and the form still answered "¡Registro completo!". A session is now required at both ends: without one the shortcode renders a short "necesitas una cuenta" prompt instead of a form nobody can submit, and the submission handler rejects the POST and writes nothing. The two are separate on purpose — the handler runs on `template_redirect`, outside the shortcode, so whatever gates the *display* (a role-locked builder row, a membership plugin) no longer gates the *submission*. Where such a gate is in place the shortcode is never reached, so its prompt and the gate's message can never both appear.
- Fix: the per-IP rate limiter counted *every* submission, so registering six products in a row within a minute of each other locked the customer out for 10 minutes. Only failed lookups count now.
- Fix: `wp_unslash()` before sanitizing the posted fields. Names and sellers with an apostrophe (`O'Neill`) were stored escaped (`O\'Neill`).
- Performance: the post-registration listeners (AcyMailing subscription, role assignment, anything hooked to `ial_user_registered_product`) now run **after the response has been sent**, on a shutdown callback that closes the connection first (`litespeed_finish_request()` / `fastcgi_finish_request()`). The customer used to wait for an external mailing-list call, whatever the role plugins do with a new role and any SMTP mail before seeing a single pixel — which is exactly the wait that produced the double clicks. The serial is claimed before the response goes out, so nothing about the outcome depends on the deferred part, and a failure in it can no longer break the page the customer sees. The loyalty level stays inline: it costs one query and shows up on the very next page. Where the SAPI cannot close the connection early (mod_php) everything runs inline as before; `ial_defer_registration_side_effects` forces either behaviour.
- Hardening: when several `ial_serial` posts share the same serial number (older or imported data — batch creation has enforced uniqueness for a while), the lookup now prefers the one belonging to the selected product that is still unregistered, instead of taking an arbitrary row and possibly rejecting a legitimate registration.

### 3.7.0
- New: **loyalty discount** for WooCommerce. Customers get a percentage off based on how many products they have registered, with as many tiers as the admin configures (threshold + percentage + optional name per tier) and a dropdown to choose whether levelling counts *different products* or *serials (units)*.
- Never stacks, by design. Per cart line the bigger of the loyalty discount and whatever price the rest of the shop settled on — sale price, quantity discount, dynamic pricing plugin — wins. Against a coupon entered by the customer, the bigger of the two wins: if loyalty wins the coupon is removed, if the coupon wins loyalty is suspended — both cases with a notice explaining why.
- Applied as a cart line-price override derived from the regular price, which makes it idempotent (the hook can fire any number of times without compounding) and leaves tax calculation to WooCommerce. It hooks `woocommerce_before_calculate_totals` at priority 9999 — filterable with `ial_loyalty_price_priority` — so it is the last thing to touch the price and can compare against whatever other pricing plugins settled on. Running earlier would let a quantity-discount or dynamic-pricing plugin use the already-discounted price as its base, multiplying the two.
- Margin safety nets: global maximum percentage and per-category exclusions. Filter `ial_loyalty_exclude_product` for anything finer.
- Order records: `_ial_loyalty_percent` and `_ial_loyalty_discount_total` on the order, percentage and amount saved per line item, plus a summary in the admin order screen — a line-price discount is invisible to WooCommerce's discount reports otherwise.
- A user's level is cached in user meta and recalculated on registration and unbind. Changing the tier settings invalidates every cached level automatically, so no batch job is needed.
- New: **collection panel** in *My Account* (`[ial_product_collection]`). Progress bar across every tier with the discount each one unlocks, plus the full product collection — registered ones marked, missing ones dimmed and linked to their shop page. The tab is laid out in three sections: **Tus productos registrados**, **Descuento permanente** and **Colección**.
- Cart and checkout print `TIER − N% aplicado` (e.g. `Iniciado − 5% aplicado`) under the product name, with no label in front — it goes through `woocommerce_cart_item_name` rather than `woocommerce_get_item_data`, which always renders as `Key: value`. Order line items, where meta is always rendered as `label: value`, use the tier name as the label: `Iniciado: −5% aplicado`. Both fall back to the percentage alone when the tier has no name.
- The progress bar plays an intro when the panel scrolls into view: it fills up to the customer's current tier, each milestone lights up as the bar reaches it, and a short confetti burst fires from the tier they are on. Vanilla JS on a canvas, no libraries. The final width is rendered server-side, so with JavaScript off the bar is simply already correct, and the whole intro is skipped when the visitor asks for reduced motion.
- New: **product page notice** for logged-in customers — their current level and discount, or how many products they need to reach the first tier, or a heads-up that this product's own offer already beats their discount. Hooked to `woocommerce_single_product_summary` at priority 11 and retargetable with the `ial_loyalty_notice_hook` / `ial_loyalty_notice_priority` filters; the `[ial_loyalty_notice]` shortcode covers themes and page builders that never fire the standard product hooks. Catalogue prices themselves are left untouched, so page caching is unaffected.
- New Product fields: **Show in collection?** (opt-out; products saved before this version stay visible) and **WooCommerce product**, which links an `ial_product` to its shop product so the "not owned yet" cards can link to the store.

### 3.6.0
- New: **"Desvincular producto"** action on the user-facing My Products page (`[ial_my_registered_products]`). User clicks the link, a modal asks for confirmation and a free-text *Motivo*, and on confirm the serial is released for someone else to register.
- On unbind: clears `uid` and `a_uid` on the serial; appends a timestamped audit line to the serial's `notes` (`[YYYY-MM-DD HH:MM:SS] Desvinculado por el usuario (ID, email). Motivo: …`); fires the new `ial_user_unbound_product` action (`$serial_id, $user_id, $product_id, $motivo`).
- Role cleanup on unbind: if the user no longer holds any other registered serial of a product that assigns the same role, the role is removed. Roles still granted via another currently-registered product are preserved.
- AcyMailing cleanup on unbind: if the user no longer holds any other registered serial of a product subscribed to the same list, they are unsubscribed from that list. Subscriptions kept via another currently-registered product are preserved.
- All user-facing strings in Spanish, code identifiers in English. Nonce + ownership check (`uid === current user`) per serial.

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
