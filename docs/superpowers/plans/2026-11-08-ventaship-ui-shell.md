# Ventaship UI, Part 2a (shell, login, fulfillment, settings) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the default Laravel page with the Ventaship admin app: login, the approved Fulfillment home page with its row and bulk actions, and the Settings screen. Only the screens whose drafts are approved get built.

**Architecture:**
- Plain Blade, served by controllers behind the `web` session guard (provider `admins`, which already exists). The look is VentaSync's Blotter system, copied into this repo and re-branded violet.
- Read models for the fulfillment page live in `app/Fulfillment`.
- Actions reuse the Part 1 services (`ShipmentCreator::submit`, `Canceller::request`, `LabelStore` and `LabelMerger`) and never call couriers from a web request.

**Tech Stack:** Laravel 12, PHP 8.5, Tailwind v4 (CSS-first `@theme`), Alpine 3, Vite 7, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-11-08-ventaship-design.md` (D1, D3, D4, D5, D6, D8, D10).

**Approved drafts:**
- `.superpowers/brainstorm/3326191-1794101622/content/dashboard.html`
- `.superpowers/brainstorm/3326191-1794101622/content/settings.html` (the look)
- `.superpowers/brainstorm/3326191-1794101622/content/login.html` (approval asked together with this plan)

**Out of this slice:** these each wait for their own browser draft (D10).
- the shipment drawer
- New shipment and Import CSV
- Courier accounts, Pickup addresses and Status mapping
- Connected platforms and Webhook logs
- the System screens

## Global Constraints

- **Writes stay inside `/home/ubuntu/ventaship`.** `~/ventasync` is read-only reference material to copy from.
- **Look (D3):**
  - Copy VentaSync's Blotter tokens and components from `~/ventasync/ventasync-pro/resources/css/blotter*.css`, `resources/views/components/ui/*` and `resources/views/layouts/blotter.blade.php`.
  - Keep: Archivo and JetBrains Mono; the navy sidebar; radii 16/11/4/999; the shadows; the attn/good/bad/cool state colours; the theme switch.
  - Do not use VentaCart's `--v-*` layer.
- **Brand (D4):**
  - brand `#8B7CF6`, used for the logo mark, active nav marker and active tab underline
  - button and strong fill `#5B45D6`, with white text on it
  - link and ink-on-wash `#4C38C2`
  - wash `#efecfe`
  - Brand colours are for brand and interaction only, never for status.
  - Dark-mode values come from the tracker page tokens: `--brand:#9d90f8;--brand-btn:#7563ec;--brand-link:#b4a9ff;--brand-w:#221d45`.
- **Enforced UI rules (D3):**
  - no `<style>` or `style=""` in Blade
  - no native `alert`/`confirm`/`prompt` (use the Alpine confirm dialog component)
  - icons only from `<x-ui.icon>`
  - colour is never the only signal: every badge carries text
- **No explainer copy (owner rule):** no helper sentences, captions that restate a label, or tooltips. Labels, values and badges only. Error messages are short facts ("Wrong email or password.").
- **Sidebar:** shows only screens whose route exists (`Route::has`), in the D5 group order. Unbuilt screens are absent, not disabled.
- **Times:** stored and shown in Asia/Manila. Money: `₱1,240.00` (peso sign, comma thousands, two decimals).
- **Admin actions are audited:** they write `audit_entries` with `actor_type='admin'`, `actor_id=<admin id>` and `ip`, following the shape `SettingSet` uses.
- **Tests:**
  - every feature test keeps `Http::preventStrayRequests()` (already in the base TestCase)
  - courier calls only through `CourierRegistry::fake`
  - the full suite stays green: 573 tests at the start of this plan
- Vite assets are built with `npm run build`. Tests must not need a built manifest: call `$this->withoutVite()` in the base TestCase.

## Review Focus

1. **A guest hits any app URL, including a bulk-action POST** → redirect to `/login` (or 401 for JSON), never a 500 or a silent action. Task 2 tests.
2. **A bulk action across rows in mixed states** (some booked, some drafts, some cancelled) → each row is acted on or skipped by its own state, and the flash counts both. Nothing is half-applied, and one bad row never aborts the rest. Task 5 tests.
3. **Settings saved with the safety switches** → stored as strict JSON `true`/`false`, so `LiveGuard`'s `=== true` check reads them correctly. An unchecked box stores `false`, not a missing key or `"0"`. Task 6 tests.
4. **The fulfillment list after PII purge** → purged rows (recipient null) still render, showing "Removed" as the name, never an error. Task 4 tests.
5. **Tabs and filters combined with pagination** → page 2 keeps the tab, chip, courier and source query string, and counts don't change with the page. Task 4 tests.

---

### Task 1: Frontend base, layout and navigation

**Files:**
- Create: `resources/css/blotter.css`, `resources/css/blotter-components.css` (copied from VentaSync and trimmed to what this slice uses; brand tokens replaced with D4)
- Modify: `resources/css/app.css` (imports, Tailwind `@theme` tokens), `resources/js/app.js` (Alpine plus a `confirmDialog` Alpine data component), `package.json` (add `alpinejs ^3.15`), `vite.config.js`
- Create: `resources/views/layouts/app.blade.php`, `resources/views/partials/theme-boot.blade.php`, `resources/views/partials/nav.blade.php`
- Create: `resources/views/components/ui/{icon,button,badge,empty,field,input,select,pager,confirm}.blade.php` (copied and adapted; `confirm` is the dialog markup)
- Create: `app/Support/Navigation.php`
- Modify: `tests/TestCase.php` (`$this->withoutVite()` in setUp)
- Test: `tests/Feature/Ui/NavigationTest.php`

**Interfaces:**
- Produces:
  - `App\Support\Navigation::groups(): array<int, array{label: ?string, items: array<int, array{label: string, route: string, icon: string, badge?: int}>}>`
    - Fixed order and labels from D5:
      - (no label): Fulfillment `fulfillment`, New shipment `shipments.create`, Import CSV `imports.create`
      - Setup: Courier accounts `accounts.index`, Pickup addresses `pickup.index`, Status mapping `mapping.index`
      - Developers: Connected platforms `apps.index`, Webhook logs `webhooks.index`
      - System: Health `system.health`, Unmatched webhooks `system.unmatched`, Failed jobs `system.failed`
      - (no label): Settings `settings.edit`
    - Items whose route doesn't exist are dropped, and a group left empty is dropped too.
  - Layout `layouts.app`: `@section('title')` and `@section('content')`; an optional `@section('actions')` renders top-right of the page header; `@section('crumb')`.
  - `<x-ui.badge tone="good|attn|bad|cool|mute">text</x-ui.badge>`, `<x-ui.button variant="primary|default" size="sm|md" href=?>`, `<x-ui.icon name= size=>`.
  - `<x-ui.confirm>`: Alpine `confirmDialog` opened by `$dispatch('confirm', {title, confirm, form})`. On confirm it submits the form element with id `form`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Ui/NavigationTest.php`
  - `test_groups_only_contain_existing_routes`: register a stub route named `fulfillment` and assert `Navigation::groups()` contains one item with route `fulfillment`, and no `accounts.index`.
  - `test_empty_groups_are_dropped`: with only `fulfillment` and `settings.edit` registered, the labels are `[null, null]`.
- [ ] **Step 2: Run** `php artisan test --filter=NavigationTest`. Expected: FAIL (class missing).
- [ ] **Step 3: Implement `Navigation::groups()`**
  - Copy and adapt the CSS and components.
  - The sidebar has no workspace switcher.
  - The logo is the violet mark plus "Ventaship", using the same SVG as the tracker and login draft.
  - The theme switch is in the sidebar footer, next to the signed-in admin's name and a Sign out button. Sign out POSTs to `logout`, so it waits on Task 2's route: render it only when `Route::has('logout')`.
- [ ] **Step 4:** Run `npm install && npm run build`. Expected: build succeeds and `public/build/manifest.json` exists.
- [ ] **Step 5:** Run `php artisan test --filter=NavigationTest`. Expected: PASS.
- [ ] **Step 6:** Commit `feat(ui): Blotter base, violet tokens, layout and navigation`.

### Task 2: Admin login and the admin command

**Files:**
- Create: `app/Console/Commands/AdminCreate.php` (`ventaship:admin`)
- Create: `app/Http/Controllers/Auth/LoginController.php`
- Create: `resources/views/auth/login.blade.php` (matches `login.html`, both states)
- Modify: `routes/web.php` (drop the `welcome` route; add login and logout; `/` → `fulfillment` behind `auth`); delete `resources/views/welcome.blade.php`
- Modify: `bootstrap/app.php` (guests redirect to `route('login')`; authenticated users redirect away from `/login` to `/`)
- Modify: `docs/runbook-staging.md` (an "Admin login" step: `php artisan ventaship:admin you@example.com`)
- Test: `tests/Feature/Ui/LoginTest.php`, `tests/Feature/Console/AdminCreateTest.php`

**Interfaces:**
- Produces:
  - routes `login` (GET, POST `/login`), `logout` (POST `/logout`), `fulfillment` (GET `/`), and the `auth` middleware group that every later UI route sits in
  - `ventaship:admin {email} {--name=}`
    - asks for the password twice with a hidden prompt (minimum 12 characters)
    - creates the admin, or with `--reset` updates the password of an existing one
    - audits `admin.created` / `admin.password_reset` with `actor_type='system'`

- [ ] **Step 1: Write the failing tests**
  - `test_guest_is_redirected_to_login`: GET `/` → 302 to `/login`.
  - `test_guest_post_to_app_route_is_redirected`: POST to a route in the auth group → 302 `/login`, with no side effect.
  - `test_admin_signs_in`: POST valid credentials → 302 `/`. Assert `auth()->id()` equals the admin, `last_login_at` is set, and the session id changed.
  - `test_wrong_password_shows_one_message`: the response has the session error `email` = `Wrong email or password.`, the email is kept in old input, and the password is not.
  - `test_login_is_throttled`: after 5 failed attempts for the same email and IP within one minute, the 6th gets the error `Too many attempts. Try again in :seconds seconds.`
  - `test_remember_me_sets_cookie`.
  - `test_logout_invalidates_session`.
  - AdminCreateTest:
    - creates an admin with a hashed password
    - rejects a password under 12 characters
    - rejects mismatched confirmation
    - rejects an existing email without `--reset`
    - `--reset` changes the password
- [ ] **Step 2: Run** `php artisan test --filter='LoginTest|AdminCreateTest'`. Expected: FAIL.
- [ ] **Step 3: Implement**
  - Throttle with `RateLimiter`, keyed by `lower(email)|ip`, 5 attempts per 60 s.
  - On success: `session()->regenerate()`, update `last_login_at`.
  - The `fulfillment` route can point at a placeholder controller method returning the layout with an empty content section until Task 4.
- [ ] **Step 4: Run** the same filter. Expected: PASS. Then run the full suite: PASS.
- [ ] **Step 5:** Commit `feat(ui): admin login, logout and ventaship:admin`.

### Task 3: Fulfillment read model

**Files:**
- Create: `app/Fulfillment/Tabs.php`, `app/Fulfillment/ShipmentList.php`, `app/Fulfillment/CourierCards.php`
- Create: `app/Shipments/TrackingLink.php` (extracted from `ShipmentResource::trackingUrl`; the resource calls it)
- Test: `tests/Feature/Fulfillment/ShipmentListTest.php`, `tests/Feature/Fulfillment/CourierCardsTest.php`

**Interfaces:**
- Produces:
  - `Tabs::PHASES`, a const map:
    - `all` → every phase
    - `unpaid` → `payment_state = cod_unpaid`, any phase
    - `to_ship` → `draft, attention, booking, to_handover`
      - chip `to_pack` → `draft, attention`
      - chip `to_handover` → `booking, to_handover`
    - `shipping` → `in_transit`
    - `failed` → `failed`
    - `lost_damaged` → `lost_damaged`
    - `delivered` → `delivered`
    - `returning` → `returning`
    - `cancelled` → `cancelled`
  - Tab labels (D5 order): All · Unpaid · To ship · Shipping · Failed · Lost & damaged · Delivered · Returning · Cancelled. Chip labels: To pack · To handover.
  - `ShipmentList::query(array $filters): Builder`
    - filters: `tab`, `chip`, `account` (courier account public_id), `source` (`api:<client app public_id>` | `manual` | `csv`)
    - unknown values fall back to `all` or no filter, never an error
    - newest first by `id`
    - eager-loads `clientApp` and `courierAccount`
  - `ShipmentList::counts(array $filters): array<string,int>`: per tab and per chip. It applies the courier and source filters but not the tab or chip filter.
  - `ShipmentList::sourceLabel(Shipment $s): string`: the client app name, `CSV import` or `Manual`.
  - `CourierCards::all(): array<int, array{courier:string, name:string, account:?CourierAccount, to_ship:int, delivered:int, to_handover:int, shipping:int, failed:int, cod_unpaid:string}>`
    - one entry per active courier account, ordered QuadX, SPX, LBC, then by label
    - plus one entry with `account=null` for each of `quadx`, `spx` and `lbc` that has no active account
    - `cod_unpaid` = the sum of `cod_amount` where `payment_state='cod_unpaid'` and phase ≠ `cancelled`, as a decimal string
  - `TrackingLink::for(Shipment $s): ?string`

- [ ] **Step 1: Write the failing tests**
  - Seed shipments across every phase and payment state with factories or direct inserts, including:
    - one with `client_app_id` null and `source='manual'`
    - one with `source='csv'`
    - one purged (`recipient` null, `pii_purged_at` set)
  - `test_tab_phase_mapping` (one assertion per tab and chip, with the exact ids expected)
  - `test_unpaid_spans_phases`
  - `test_counts_ignore_tab_but_respect_courier_and_source`
  - `test_unknown_filter_values_fall_back`
  - `test_source_labels`
  - `test_cards_include_muted_entries_for_missing_couriers`
  - `test_cod_unpaid_excludes_cancelled_and_uses_cod_amount` (asserts the exact string `'1240.00'`)
  - `test_tracking_link_matches_api_resource` (the resource's `tracking_url` equals `TrackingLink::for`)
- [ ] **Step 2: Run** `php artisan test --filter='ShipmentListTest|CourierCardsTest'`. Expected: FAIL.
- [ ] **Step 3: Implement.** Counts come from one grouped query by phase, plus one query for unpaid; don't run one query per tab.
- [ ] **Step 4: Run** the same filter, then the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(fulfillment): tabs, list query, courier cards`.

### Task 4: Fulfillment page

**Files:**
- Create: `app/Http/Controllers/Ui/FulfillmentController.php` (`index`, `poll`)
- Create: `resources/views/fulfillment/index.blade.php`, `resources/views/fulfillment/_card.blade.php`, `resources/views/fulfillment/_row.blade.php`
- Create: `resources/css/pages/fulfillment.css` (imported from `app.css`)
- Modify: `routes/web.php` (`fulfillment` → `index`; `fulfillment.poll` GET `/fulfillment/poll`)
- Test: `tests/Feature/Ui/FulfillmentPageTest.php`

**Interfaces:**
- Consumes: Task 3.
- Produces:
  - `fulfillment.poll` returns JSON `{counts: {tab: n, ...}, phases: {public_id: phase, ...}}` for the ids in `?ids=a,b,c` (max 100; unknown ids omitted).
  - Row markup carries `data-id="{public_id}"` and a checkbox `name="ids[]" value="{public_id}"` inside a form `id="bulk"` that Task 5 posts.

Layout follows `dashboard.html`:
- **Header:** title "Fulfillment" (crumb omitted).
- **Courier cards grid:** as in D5.
  - The muted card shows a "Connect {Courier}" button only when `Route::has('accounts.create')`. Otherwise it shows "No account".
  - Mode badge: `Live` (good) when `mode=production`, `Staging`/`UAT` (cool) otherwise.
  - Connection badge from `last_check_ok`: `Connected` good, `Check failed` bad, `Not checked` mute.
- **Panel:**
  - tabs with counts; sub-chips under To ship
  - courier and source selects (GET form, auto-submit with Alpine)
  - list, paginated 50 per page with `withQueryString()`
- **Row:**
  - recipient name (or `Removed` when purged); `courier_ref` plus `client_reference`; city and `items_count` items
  - courier and service, with the tracking link (opens a new tab, `rel=noopener`) or `Not booked`
  - amount, which is `cod_amount` for COD or `total` for prepaid
  - payment badge:
    - `COD · Unpaid` attn
    - `COD · Collected` cool
    - `COD · Remitted` good
    - `COD · Void` mute
    - `Prepaid` mute
  - phase badge:
    - draft `Draft` mute
    - booking `Booking` cool
    - attention `Needs attention` attn
    - to_handover `To handover` cool
    - in_transit `Shipping` cool
    - delivered `Delivered` good
    - failed `Failed` bad
    - lost_damaged `Lost / damaged` bad
    - returning `Returning` attn
    - cancelled `Cancelled` mute
  - source label; a Book button on rows in phase `draft`
- **Empty tab:** `<x-ui.empty>` with "No shipments".
- **Polling:** Alpine polls `fulfillment.poll` every 20 s while the tab is visible. It updates the tab counts in place, and reloads the page when a visible row's phase changed and no checkbox is ticked.

- [ ] **Step 1: Write the failing tests**
  - `test_page_renders_cards_tabs_and_rows` (assert the visible text: tab labels, a row's `courier_ref`, `₱1,240.00`, `COD · Unpaid`, `To handover`, `VentaCart Pro`)
  - `test_purged_row_renders_removed`
  - `test_pagination_keeps_filters` (51 rows; the page-2 link contains `tab=to_ship&chip=to_pack&source=manual`)
  - `test_connect_button_hidden_without_accounts_route`
  - `test_poll_returns_counts_and_phases`
  - `test_poll_caps_ids_at_100`
  - `test_guest_cannot_poll` (401 JSON)
- [ ] **Step 2: Run** `php artisan test --filter=FulfillmentPageTest`. Expected: FAIL.
- [ ] **Step 3: Implement.** No inline styles.
- [ ] **Step 4:** Run the filter, then `npm run build`, then the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(ui): fulfillment page`.

### Task 5: Row and bulk actions (Book, Cancel, Print waybills)

**Files:**
- Create: `app/Shipments/LabelBundle.php`
  - extracted from `Api\LabelsController::store`'s fetch-and-merge loop
  - the API controller is refactored to use it, with byte-identical API behaviour (the existing label tests stay green unchanged)
- Create: `app/Http/Controllers/Ui/ShipmentActionsController.php` (`book`, `cancel`, `labels`)
- Modify: `routes/web.php`, `resources/views/fulfillment/index.blade.php` (the bulk bar plus `<x-ui.confirm>` for Cancel)
- Test: `tests/Feature/Ui/ShipmentActionsTest.php`

**Interfaces:**
- Consumes: `ShipmentCreator::submit(Shipment): Shipment` (throws `ApiError`), `Canceller::request(Shipment): Shipment` (throws `ApiError`), `LabelStore`, `LabelMerger`.
- Produces:
  - `LabelBundle::build(Collection<Shipment> $shipments): array{bytes: string, type: 'pdf'|'zip', skipped: list<string>}`
    - throws `ApiError` 502 `courier_unavailable` or 409 `label_not_ready` exactly as the controller did
  - routes, all in the `auth` group:
    - `shipments.book` POST `/shipments/book`
    - `shipments.cancel` POST `/shipments/cancel`
    - `shipments.labels` POST `/shipments/labels`
    - each takes `ids[]` (public ids, 1–100)

**Behaviour:**
- **Book:** for each id in phase `draft` with `state='draft'`, call `submit`. Other rows are skipped.
  - Flash `{n} queued for booking`, plus ` · {m} skipped` when m > 0, plus one line per `ApiError` as `{courier_ref}: {message}`.
  - Audit `shipment.book_requested` per queued row.
- **Cancel:** behind the confirm dialog ("Cancel {n} shipments?", button "Cancel shipments"). Call `request` per id and catch `ApiError` per row.
  - Flash `{n} cancelled or cancelling · {m} not cancellable`.
  - Audit `shipment.cancel_requested`.
- **Print waybills:** returns the download (`labels.pdf` or `labels.zip`).
  - When everything is skipped, redirect back with the error `None of these have a waybill yet.`
  - When some are skipped, the download still happens, and a flash is set for the next page view: `{m} skipped: no waybill yet`.
- **Courier calls:** none in the request. `submit` only dispatches `BookShipment`. The label fetch is the exception, as already in the API.

- [ ] **Step 1: Write the failing tests**
  - `test_book_queues_drafts_and_skips_others` (mixed: 2 drafts, 1 booked, 1 cancelled → `Queue::assertPushed(BookShipment::class, 2)`, flash `2 queued for booking · 2 skipped`)
  - `test_book_reports_account_error_per_row`
  - `test_cancel_mixed_states_one_failure_does_not_abort_rest`
  - `test_labels_single_pdf`
  - `test_labels_merged_with_skipped_flash`
  - `test_labels_none_ready_redirects_with_error`
  - `test_ids_over_100_rejected`
  - `test_actions_are_audited_with_admin_actor`
  - `test_api_labels_tests_still_pass` (run the existing label test class unchanged)
- [ ] **Step 2: Run** `php artisan test --filter=ShipmentActionsTest`. Expected: FAIL.
- [ ] **Step 3: Implement.** Extract `LabelBundle` first, then run the API label tests to prove the refactor changed nothing.
- [ ] **Step 4:** Run the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(ui): book, cancel and print waybills from fulfillment`.

### Task 6: Settings

**Files:**
- Create: `app/Http/Controllers/Ui/SettingsController.php` (`edit`, `update`, `account`)
- Create: `resources/views/settings/edit.blade.php` (tabs as query `?tab=`), `resources/css/pages/settings.css`
- Modify: `routes/web.php` (`settings.edit` GET `/settings`, `settings.update` PUT `/settings`, `settings.account` PUT `/settings/account`)
- Test: `tests/Feature/Ui/SettingsScreenTest.php`

**Interfaces:**
- Consumes: `App\Support\Settings::get/set`. The audit shape is `SettingSet`'s, with `actor_type='admin'`.
- Produces: the tabs below. Only settings something reads today are shown (D8). Shipping defaults, Waybills and Notifications arrive with the screens that read them.

**General** (form footer: Discard · Save, as in the draft):
- **Reference prefix** → `shipments.reference_prefix`
  - `^[A-Z0-9]{1,8}$`, default `VS`
  - Error: `Use 1–8 capital letters or digits.`
- **Keep recipient details for (days)** → `privacy.retention_days`
  - integer 30–3650, default 180
  - Error: `Between 30 and 3650 days.`

**Couriers & safety:**
- **Allow live LBC bookings** → `safety.allow_live_lbc`
- **Allow live SPX bookings** → `safety.allow_live_spx`
- Toggles. They store strict `true`/`false`, so an unchecked toggle stores `false`.

**Account** (the signed-in admin):
- name, email (unique among admins)
- new password plus confirmation (minimum 12), which requires the current password whenever email or password changes
- Errors: `Current password is wrong.`, `At least 12 characters.`, `Passwords don't match.`

**Saving:** only changed keys are written and audited (`setting.changed`, from/to). Flash `Saved`. Account changes are audited as `admin.updated` with field names only, never values.

- [ ] **Step 1: Write the failing tests**
  - `test_tabs_render_with_current_values`
  - `test_unchecked_safety_switch_stores_strict_false` (assert `Settings::get('safety.allow_live_lbc') === false`, and that `LiveGuard` still blocks)
  - `test_checked_switch_stores_true`
  - `test_invalid_prefix_rejected_with_message`
  - `test_retention_bounds`
  - `test_only_changed_keys_audited_with_admin_actor`
  - `test_email_change_requires_current_password`
  - `test_password_change_rehashes_and_keeps_session`
  - `test_audit_never_contains_password`
- [ ] **Step 2: Run** `php artisan test --filter=SettingsScreenTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Run `npm run build`, then the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(ui): settings (general, couriers & safety, account)`.
