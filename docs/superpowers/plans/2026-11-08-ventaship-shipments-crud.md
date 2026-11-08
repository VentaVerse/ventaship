# Ventaship Part 2b: Shipments CRUD (courier dashboard) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Fulfillment page into a courier dashboard: a Shipments list with plain filter tabs, plus Add, view, edit and delete for shipments. Every UI booking or cancel reaches the courier during the click, because no queue workers run on the Pi.

**Architecture:**
- The UI reuses the Part 1 services: `ShipmentCreator` (create, submit), `Canceller` (request) and `LabelBundle`, with the same validation rule sets as the API (`ValidatesShipmentParts`).
- New, small `$now` paths run `BookShipment` and `CancelAtCourier` synchronously for UI requests. The API keeps queueing.
- The display phase (`App\Fulfillment\DisplayPhase`) stays the single source for which tab a row belongs to.

**Tech Stack:** Laravel 12, PHP 8.5, Blade, Alpine 3, Tailwind v4, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-11-08-ventaship-design.md`. Section 9, "Direction change", is binding and supersedes D5 tabs, D6 and parts of D10.

## Global Constraints

- **Writes stay inside `/home/ubuntu/ventaship`.**
- **No background processing:**
  - Never add a cron, scheduler or queue worker.
  - UI-initiated booking and cancelling run synchronously in the request.
  - The API path is unchanged and still queues.
- **The API contract (`docs/api.md`) and its fixtures stay byte-identical.** Do not change Part 1 behaviour for API callers.
- **Look:** the existing Blotter components and the violet tokens.
  - No `<style>` or `style=""` in Blade.
  - No native dialogs. Use `<x-ui.confirm>`.
  - Icons only through `<x-ui.icon>`.
  - Colour is never the only signal.
  - **No explainer copy:** labels, values and short error facts only.
- **Credentials:** none are shown here. Courier accounts get their own screen later.
- **Admin actions are audited** (`actor_type='admin'`, `actor_id`, `ip`). Audit rows never contain recipient PII.
- **Money:** handled as decimal strings, displayed as `₱1,240.00`. It never goes through float.
- **Tests:**
  - `Http::preventStrayRequests()` (already in the base TestCase).
  - Courier calls only through `CourierRegistry::fake`.
  - Shipments are created the way production does: via `ShipmentCreator` or the UI routes, not by seeding `phase`.
  - The full suite stays green: 660 tests at the start.

## Review Focus

1. **The courier refuses or times out during Add.**
   - Refused: the shipment is saved as `failed` with the courier's message shown, and it stays editable and rebookable.
   - Timed out: the shipment becomes `unknown` with attention, and the UI says the booking result is unknown. It is never rebooked automatically.
   - Neither case is a 500.
2. **Double submit of Add** (a double click, or the browser re-posting) never creates two shipments or books twice. Use an idempotency token in the form, unique per form render.
3. **Edit or delete attempted on a booked shipment, or on an API shipment** → refused with a short message, nothing changed. The guard is on the server, not only by hiding buttons.
4. **Sync booking while an API job for the same shipment waits in the queue** → `BookShipment`'s lock and state checks prevent a second booking. Covered by a test.
5. **Purged rows** (`recipient` null) still render on the list and the detail page as "Removed". Edit is refused.

---

### Task 1: Shipments list: plain filter tabs and naming

**Files:**
- Modify: `app/Fulfillment/Tabs.php`, `app/Fulfillment/ShipmentList.php`, `app/Fulfillment/CourierCards.php`, `resources/views/fulfillment/*`, `app/Support/Navigation.php`
- Test: update `tests/Feature/Fulfillment/*`, `tests/Feature/Ui/FulfillmentPageTest.php`, `tests/Feature/Ui/DisplayPhaseFlowTest.php`

**Interfaces:**
- **Produces:**
  - `Tabs::PHASES` (display phases), replacing the old map; there are no chips:
    - `all` → every phase
    - `awaiting_pickup` → `draft, booking, to_handover`
    - `in_transit` → `in_transit`
    - `delivered` → `delivered`
    - `problem` → `attention, failed, lost_damaged`
    - `returned` → `returning`
    - `cancelled` → `cancelled`
  - Labels in that order: All · Awaiting pickup · In transit · Delivered · Problem · Returned · Cancelled.
  - The `unpaid` tab and the `to_pack` / `to_handover` chips are removed.
  - Unknown `tab` or `chip` query values fall back to `all`, as before.
- **Courier cards:**
  - Big figures: **Awaiting pickup** and **Delivered**.
  - Smaller lines: **In transit** and **Problem**.
  - The **COD unpaid** strip is unchanged.
- **Page:**
  - Title "Shipments".
  - The sidebar item becomes "Shipments", still route `fulfillment` at `/`.
  - The header action shows "Add shipment" when `Route::has('shipments.create')`.
- **Payment badges and amounts on rows are unchanged.** COD unpaid is still visible per row.

- [ ] **Step 1: Write the failing tests and update the existing ones**
  - `test_tab_phase_mapping` with the new keys and exact ids.
  - `test_old_tab_values_fall_back_to_all`: `?tab=unpaid` and `?tab=to_ship&chip=to_pack`.
  - `test_cards_show_awaiting_pickup_in_transit_problem`.
  - `test_page_title_and_nav_say_shipments`.
  - Keep the purged-row and pagination tests.
- [ ] **Step 2: Run** `php artisan test --filter='Fulfillment|DisplayPhase'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Run `npm run build` and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(ui): shipments list with plain filter tabs`.

### Task 2: Default sender in Settings

**Files:**
- Modify: `app/Http/Controllers/Ui/SettingsController.php`, `resources/views/settings/edit.blade.php`
- Test: `tests/Feature/Ui/SettingsScreenTest.php`

**Interfaces:**
- **Produces:**
  - A new General tab section, **"Sender"**, with these settings:
    - `pickup.contact_name`, required to save the section, max 255
    - `pickup.contact_phone`: a PH phone, same rule as the API's `phPhone`
    - `pickup.address_1`, required, max 255
    - `pickup.address_2`, optional
    - `pickup.barangay`, optional
    - `pickup.city`, required
    - `pickup.state`, required (shown as "Province")
    - `pickup.postcode`, optional
  - `App\Support\Settings::pickup(): ?array` returns these in the API's `pickup` input shape (`contact_name`, `contact_phone`, `address_1`, …, `country` = `PH`), or `null` when unset.
  - Audited per changed key, like the other settings.

- [ ] **Step 1: Write the failing tests**
  - `test_sender_section_saves_and_audits_changed_keys`.
  - `test_sender_validation_messages` (short facts: `Required.`, `Not a valid PH mobile number.`).
  - `test_pickup_helper_returns_api_shape`.
- [ ] **Step 2: Run** the filter. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Run the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(ui): default sender in settings`.

### Task 3: Synchronous booking and cancelling for UI requests

**Files:**
- Modify: `app/Shipments/ShipmentCreator.php`, `app/Shipments/Canceller.php`, `app/Http/Controllers/Ui/ShipmentActionsController.php`
- Test: `tests/Feature/Shipments/SyncBookingTest.php`, `tests/Feature/Ui/ShipmentActionsTest.php`

**Interfaces:**
- **Produces:**
  - `ShipmentCreator::submit(Shipment $draft, bool $now = false): Shipment`
    - When `$now`, it runs `BookShipment::dispatchSync($id)` after the transaction commits.
    - It returns the shipment fresh, with its outcome: `booked`, `failed`, or `unknown` + attention.
  - `ShipmentCreator::create(?ClientApp $app, array $data, string $source = 'api', bool $now = false): Shipment`
    - When `$now` and submit is true, the booking runs synchronously.
  - `Canceller::request(Shipment $s, bool $now = false): Shipment`
    - When `$now`, it runs `CancelAtCourier::dispatchSync`.
  - The API callers pass nothing, so their behaviour is unchanged.
  - The UI **Book** and **Cancel** actions (row and bulk, from Part 2a) pass `now: true`. Their flash messages report the actual outcome:
    - Book: `{n} booked · {m} failed · {k} skipped`, plus one line per failure as `{courier_ref}: {message}`.
    - Cancel: `{n} cancelled · {m} not cancellable`.

- [ ] **Step 1: Write the failing tests**
  - `test_submit_now_books_synchronously_with_fake_courier` (`Queue::fake` is **not** used; the state is `booked` with a tracking number right after the call).
  - `test_submit_now_refused_sets_failed`.
  - `test_submit_now_timeout_sets_unknown_with_attention_and_is_not_retried`.
  - `test_api_create_still_queues` (`Queue::fake`; `assertPushed(BookShipment::class)`).
  - `test_sync_book_while_job_queued_books_once`.
  - `test_cancel_now_reaches_courier_synchronously`.
  - Update the ShipmentActions flash tests to the new strings.
- [ ] **Step 2: Run** the filter. Expected: FAIL.
- [ ] **Step 3: Implement.** Keep `BookShipment`'s own lock and state guards as the double-booking protection. Don't duplicate them.
- [ ] **Step 4:** Run the full suite. Expected: PASS. `ApiExamplesTest` must stay unchanged.
- [ ] **Step 5:** Commit `feat(shipments): book and cancel synchronously from the dashboard`.

### Task 4: Add shipment (Create)

**Files:**
- Create: `app/Http/Controllers/Ui/ShipmentsController.php` (`create`, `store`), `app/Http/Requests/Ui/ShipmentFormRequest.php`, `resources/views/shipments/form.blade.php`, `resources/views/shipments/_form-fields.blade.php`, `resources/css/pages/shipments.css`
- Modify: `routes/web.php`, `resources/js/app.js` (an `itemRows` Alpine component)
- Test: `tests/Feature/Ui/ShipmentCreateTest.php`

**Interfaces:**
- **Consumes:**
  - `ShipmentCreator::create(null, $data, 'manual', now: true)`
  - `ValidatesShipmentParts` (recipient, pickup, parcel, payment, account rules)
  - `Settings::pickup()`
- **Produces:**
  - Routes `shipments.create` (GET `/shipments/new`) and `shipments.store` (POST `/shipments`), both under `auth`.
  - The form request reuses the API rule sets and accepts the same input shape.
  - A redirect to `shipments.show` on any saved outcome.
- **Form sections, label-left like Settings:**
  - **Courier:**
    - An account select listing active courier accounts as "QuadX · Main account".
    - When there are none, an `<x-ui.empty>` with "No courier accounts" replaces the form, and nothing can be submitted.
    - An optional service select, from the account's courier schema services when available.
  - **Customer:** first name, last name, phone, email (optional), address 1, address 2, barangay, city, province, postcode.
  - **Items:** rows of name, quantity and price, with Add item / Remove. At least 1 row.
  - **Parcel:** weight in kg, plus optional type and dimensions.
  - **Payment:**
    - COD / Prepaid, as a radiogroup.
    - COD amount, shown and required only when COD is chosen.
    - Declared value.
    - An Insure checkbox, unchecked by default.
  - **Sender:** prefilled from `Settings::pickup()`. When that's unset, the fields are empty and required, with no explainer text.
  - **Order reference:** optional. When blank, it's generated as `M-{Ymd}-{6 random A-Z0-9}`, unique among active manual references.
- **Idempotency:**
  - A hidden `form_token` (a UUID per render) is stored in the session.
  - A second POST with the same token redirects to the shipment the first one created.
- **Outcomes**, shown as a flash on the detail page:
  - booked: `Booked · {tracking_number}`
  - failed: `Courier refused: {message}`
  - unknown: `Booking result unknown`
- **Audit:** `shipment.created`, with `subject_id` = `public_id`.

- [ ] **Step 1: Write the failing tests**
  - `test_form_renders_with_sender_prefilled_and_accounts_listed`.
  - `test_no_accounts_shows_empty_state`.
  - `test_store_books_with_fake_courier_and_redirects_to_show`.
  - `test_cod_amount_required_only_for_cod`.
  - `test_validation_errors_keep_input`.
  - `test_generated_reference_format_and_uniqueness`.
  - `test_double_submit_same_token_creates_one`.
  - `test_courier_refusal_saves_failed_and_shows_message`.
  - `test_timeout_saves_unknown`.
  - `test_guest_cannot_store`.
- [ ] **Step 2: Run** the filter. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Run `npm run build` and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(ui): add shipment`.

### Task 5: Shipment detail (Read)

**Files:**
- Modify: `app/Http/Controllers/Ui/ShipmentsController.php` (`show`), `routes/web.php`, `resources/views/fulfillment/_row.blade.php`
- Create: `resources/views/shipments/show.blade.php`
- Test: `tests/Feature/Ui/ShipmentShowTest.php`

**Interfaces:**
- **Produces:**
  - Route `shipments.show` (GET `/shipments/{shipment:public_id}`). An unknown id gives 404.
  - Each list row's recipient name links to it.
- **Page:**
  - **Header:** the recipient name, or "Removed" when purged; `courier_ref` and the client reference; the phase badge (display phase) and the payment badge.
  - **Sections:**
    - **Customer:** name, phone, address.
    - **Items and parcel.**
    - **Payment:** method, COD amount, declared value, insured or not, payment state.
    - **Courier:** account, service, tracking number with its link (http(s) only), booked at.
    - **Source:** the client app name, "Manual" or "CSV import".
    - **Timeline:** `shipment_events`, newest first, each with time (Manila), status label and phase badge.
  - **Actions**, each shown only when allowed:
    - **Print waybill** when a label can exist (`state = booked`)
    - **Cancel** behind `<x-ui.confirm>`
    - **Edit**, **Book**, **Delete** per the Task 6 rules
  - An attention reason, when present, shows as an attn badge plus its text.

- [ ] **Step 1: Write the failing tests**
  - `test_show_renders_sections_and_timeline_newest_first`.
  - `test_purged_shipment_shows_removed`.
  - `test_actions_visibility_by_state_and_source` (booked: Print and Cancel; manual failed: Edit, Book, Delete; API draft: Book only).
  - `test_unknown_id_404`.
  - `test_row_links_to_show`.
- [ ] **Step 2: Run** the filter. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Run `npm run build` and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(ui): shipment detail page`.

### Task 6: Edit and delete (Update, Delete)

**Files:**
- Modify: `app/Http/Controllers/Ui/ShipmentsController.php` (`edit`, `update`, `destroy`), `routes/web.php`, `app/Shipments/ShipmentCreator.php` (an `update()` for unbooked shipments)
- Test: `tests/Feature/Ui/ShipmentEditDeleteTest.php`

**Interfaces:**
- **Rules, enforced on the server:**
  - Editable and deletable only when `source = 'manual'` **and** `state` is `draft` or `failed`, and the shipment isn't purged.
  - Anything else is refused with the error `Only unbooked manual shipments can be changed.`
  - API and CSV shipments belong to their platform.
- **Produces:**
  - `ShipmentCreator::update(Shipment $s, array $data): Shipment`
    - It rewrites the attributes with `self::attributes($data)` and the account.
    - It keeps `client_reference` unless changed, enforcing the active-reference uniqueness.
    - It resets `error_code`, `error_message` and attention.
  - Routes, all under `auth`:
    - `shipments.edit` (GET `/shipments/{shipment}/edit`)
    - `shipments.update` (PUT): **Save**
    - `shipments.update` with `book=1`: **Save and book**, which books via `submit(now: true)`
    - `shipments.destroy` (DELETE), behind `<x-ui.confirm>` with the title "Delete shipment {courier_ref}?" and the button "Delete shipment"
  - The edit form reuses `_form-fields.blade.php`.
  - Deleting removes the shipment together with its events and labels in one transaction. It is audited as `shipment.deleted`, with the reference only and no PII. It redirects to the list with the flash `Deleted`.

- [ ] **Step 1: Write the failing tests**
  - `test_edit_failed_manual_shipment_and_save`.
  - `test_save_and_book_books_synchronously`.
  - `test_edit_refused_for_booked`.
  - `test_edit_refused_for_api_draft`.
  - `test_edit_refused_for_purged`.
  - `test_delete_failed_manual_shipment`.
  - `test_delete_refused_for_booked_and_api` (POST/DELETE straight to the route, bypassing the UI).
  - `test_delete_is_audited_without_pii`.
- [ ] **Step 2: Run** the filter. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Run `npm run build` and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(ui): edit and delete unbooked manual shipments`.
