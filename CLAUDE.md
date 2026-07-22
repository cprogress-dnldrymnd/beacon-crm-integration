# Beacon CRM Integration for WooCommerce

A single-file WordPress plugin (`beacon-crm-integration.php`) that syncs WooCommerce orders
and LearnDash course enrollments to Beacon CRM (https://beaconcrm.org). Everything —
admin UI, AJAX handlers, API client, business logic — lives in one class,
`Beacon_CRM_Integration`, implemented as a singleton (`get_instance()`) instantiated at
the bottom of the file.

There is no build step, no dependency manager (no composer.json/package.json), and no
test suite. This is a WordPress plugin meant to be dropped into `wp-content/plugins/`.

## Requirements / Dependencies

Relies on WordPress core plus two other plugins being active:
- **WooCommerce** — orders, products, `wc_get_order()`, `wc_get_orders()`, product meta.
- **LearnDash** — `sfwd-courses` post type, `learndash_update_course_access` hook.

Also assumes a custom post type `beaconcrmlogs` exists for the CRM log entries — it is
**not registered in this file** (look for it in another plugin/mu-plugin, or register it
separately; the log-related methods here only hook into columns/filters/meta boxes for it).

## Architecture

### Two kinds of "courses" synced to Beacon CRM
1. **Online Courses** — LearnDash courses (`sfwd-courses` post type). Beacon mapping
   (`_beacon_course_id`, `_beacon_course_type`) is stored as post meta and edited directly
   on the LearnDash course edit screen (metabox added via `register_meta_boxes`).
2. **Live Courses** — custom, non-LearnDash courses stored as an array in the
   `beacon_crm_live_courses` option (keyed by an admin-assigned integer ID). These are
   linked to WooCommerce products (and, optionally, individual product variations) via
   `_beacon_live_courses` post meta (a list of live-course IDs). The linkage is editable
   from **either** side and kept in sync both ways:
   - Product editor → `render_wc_product_fields` adds a "Linked Live Courses"
     multiselect (styled to match native wp-admin product-data fields) directly to
     the product's meta.
   - Variable product variations → `render_variation_live_course_fields`
     (`woocommerce_product_after_variable_attributes`) adds the same multiselect per
     variation, saved by `save_variation_live_course_fields`
     (`woocommerce_save_product_variation`) onto the variation post's own
     `_beacon_live_courses` meta. A variation's mapping **overrides** its parent
     product's; an empty/missing variation mapping falls back to the parent's at sync
     time (`sync_live_courses_from_order()`). The picker is only rendered if at least
     one Live Course exists.
   - Course Mapping modal → the same Live Course add/edit modal (`ajax_save_course_mapping`)
     includes a "Linked Products" picker; on save it calls `sync_live_course_products()` to
     add/remove `_beacon_live_courses` on the affected post IDs so both sides agree.
     `get_wc_products_for_select()` lists both parent products and, for variable products,
     each variation (labelled with its formatted attributes, e.g. "Product – Size: L"),
     so this picker can link/unlink variations directly too — `sync_live_course_products()`
     just calls `update_post_meta()` on whatever post ID was selected.
   - `get_live_course_product_map()` builds the reverse lookup (course ID → product/variation
     IDs) via a direct `$wpdb` query against both `product` and `product_variation` post
     types, used to prefill both the mapping-table UI and the modal's product picker.
     `ajax_delete_live_course()` does the same cleanup scan when a Live Course is deleted.

Both course types are managed from a single admin table (Settings > Beacon CRM > Course
Mapping tab), edited through one shared AJAX modal (`ajax_save_course_mapping`).

### Sync trigger points
- `woocommerce_payment_complete` → `handle_payment_complete()` (creates/updates the
  Beacon Person + Payment) and `sync_live_courses_from_order()` (creates Training records
  for any Live Courses linked to purchased products).
- `learndash_update_course_access` → `handle_training_logic()` — fires when LearnDash
  grants/revokes course access; creates the Beacon Training record for Online Courses.
  Comments in the code mark this as "untouched" / native LearnDash logic — be cautious
  changing it, since bulk order sync deliberately avoids double-triggering it (see
  `ajax_process_chunk`, which calls `handle_payment_complete` but explicitly skips
  training-logic to let LearnDash's own enrollment hook handle that).
- Manual "Test Integration" tab → `admin_post_beacon_test_sync` →
  `handle_test_sync_submission()`, runs both payment and live-course sync for one order.
- "Bulk Date Sync" tab → AJAX endpoints `beacon_init_bulk_sync` (collects order IDs in a
  date range) then `beacon_process_chunk` (processes 5 orders at a time client-side, with
  a 500ms server-side delay per chunk to avoid rate-limiting).

### Beacon CRM API client
- Credentials (`get_credentials()`): API key + Account ID stored via Settings API
  (`beacon_crm_api_key`, `beacon_crm_account_id`, `beacon_crm_api_base`, default base
  `https://api.beaconcrm.org/v1/account/`). Returns `false` if not configured — most sync
  paths bail out early in that case.
- `send_request($resource, $body, $order_id, $method)` — thin wrapper over
  `wp_remote_request`, PUT by default (Beacon's API pattern is upsert-via-PUT).
- Entities pushed: `entity/person/upsert`, `entity/payment/upsert`, `entity/c_training/upsert`.
- Person lookup is cached per WP user via `beacon_user_id` user meta (created once, then
  reused — `get_or_create_person()` for WooCommerce orders, `get_or_create_person_from_user()`
  for the LearnDash-only enrollment path where no order exists).
- Notable retry behavior in `get_or_create_person()`: if Beacon rejects the request due to
  `phone_numbers`, it retries once with the phone number stripped.
- Every API call is logged via `log_to_db()` as a `beaconcrmlogs` post, with `type`
  (person/payment/training), `api_url`, `args`, `return`, and a derived `status`
  (success/error) — viewable in the WP admin as a custom post list with type/status
  filters (`add_log_filters`, `filter_logs_by_meta`) and a metabox showing the raw
  request/response (`render_log_metabox`).

## Admin UI

Top-level admin menu **Beacon CRM** (`add_menu_page`, slug `beacon-crm-settings`, page
lives at `admin.php?page=beacon-crm-settings` — not under Settings anymore), with a
"Settings" submenu (same page) and a "Course Mapping" submenu that deep-links to
`&tab=mapping`. Four tabs total (`?tab=api|mapping|test|bulk`), all rendered by
`render_settings_page()`:
- **API Configuration** — credentials form.
- **Course Mapping** — combined LearnDash + Live Course table with an AJAX add/edit/delete
  modal (`render_ajax_mapping_modal()`). Its "Linked Products" field is a custom
  search-box-plus-suggestions picker (chips + typeahead, hand-built in jQuery) rather than
  select2/selectWoo — see gotchas below.
- **Test Integration** — search-and-sync a single WooCommerce order; its order-search field
  uses selectWoo/select2 (enqueued only on this admin page via `enqueue_admin_scripts`).
- **Bulk Date Sync** — client-driven chunked processing of a date range of orders, with
  a progress bar.

## Conventions / gotchas

- All business logic, admin rendering, and inline JS/CSS live in this one ~1950-line file
  — there's no asset pipeline, so JS is embedded in PHP via `<script>` blocks per tab/modal.
- Admin redirects (e.g. `handle_test_sync_submission()`) build their `add_query_arg()`
  base with `admin_url('admin.php')`, matching the top-level menu — not
  `options-general.php`, which is only correct for pages registered under Settings.
- Live Course IDs are plain integers chosen by the admin at creation time and must not
  collide with a LearnDash course post ID (`ajax_save_course_mapping` checks
  `get_post_type($custom_id) === 'sfwd-courses'` to prevent this) or an existing live
  course ID.
- Nonces are used per-AJAX-action (`beacon_search_orders`, `beacon_bulk_sync`,
  `beacon_save_mapping`, `beacon_delete_live`, `beacon_test_sync_nonce`) — follow this
  pattern (`check_ajax_referer`) when adding new endpoints, and gate on
  `current_user_can('manage_options')`.
- Currency is hardcoded to `GBP` in `handle_payment_complete()`.
- The mapping modal's "Linked Products" field is a custom search-box + suggestions-list
  picker (`#beacon-products-search` / `#beacon-products-suggestions` / chips in
  `#beacon-products-chips`), built from scratch to replace an earlier select2/selectWoo
  multiselect that had positioning and empty-search bugs inside the modal. The full
  product catalogue is read once from a hidden `<select id="beacon-modal-products">`
  (still present so the existing save handler can read `.val()` unchanged); the picker
  keeps that hidden select in sync as chips are added/removed via `syncHiddenSelect()`.
  When touching this UI, keep the hidden `<select>` in sync rather than reading
  `selectedProducts` directly on save.
- The mapping modal's inner box still has `id="beacon-modal-box"` and `position:relative`
  — the custom product-suggestions dropdown (`position:absolute`) relies on this
  ancestor for correct positioning when the modal content scrolls. Keep this anchor if
  the modal markup changes.
- The WooCommerce product editor's own "Linked Live Courses" field
  (`render_wc_product_fields`) is unrelated to the modal picker rewrite — it still uses
  WooCommerce's native `wc-enhanced-select` (WooCommerce's own select2 wrapper). Same for
  the per-variation version of this field (`render_variation_live_course_fields`).
- `sync_live_courses_from_order()` resolves Live Courses per order line item by checking
  the variation's own `_beacon_live_courses` meta first (`$item->get_variation_id()`) and
  only falling back to the parent product's meta if the variation has none set — a
  variation with an explicit empty selection is *not* currently distinguishable from one
  that was never mapped, so both fall back to the parent.
