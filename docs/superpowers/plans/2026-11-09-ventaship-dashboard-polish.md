# Ventaship Part 2d: centered layout, fixed courier accounts, PSGC addresses, Add shipment redesign

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Apply the owner's 2026-11-09 direction:
- Content is centered at a narrower width.
- Courier accounts are a fixed QuadX / SPX / LBC set, one account each, with no add, no delete and no name.
- Addresses use PSGC dropdowns.
- Add shipment is redesigned in a cleaner courier-dashboard style:
  - COD collects the items total.
  - An Insure checkbox sets the declared value.

The owner waived browser drafts for this plan ("Just go ahead").

**Architecture:**
- **Layout width:** one token, applied by a page-width class.
- **Courier accounts:** keyed by courier (`/accounts/quadx`), resolved to that courier's account by `CourierAccounts::forCourier()`. The model stays multi-account capable, but that capability is parked.
- **PSGC:** Venta's cleaned files are copied into `database/data/ph` and served by `App\Support\PhAddress` (file-backed and cached) plus an authenticated JSON endpoint. An Alpine `phAddress` component handles the cascade.
- **Add shipment:** a new layout. The server derives the COD amount and declared value; the UI never sends them.

**Spec:** `docs/superpowers/specs/2026-11-08-ventaship-design.md` §9, plus this plan's Goal, which is the owner's direction.

## Global Constraints

- **Writes:** only inside `/home/ubuntu/ventaship`. `~/venta` is read-only: copy from it, never write to it.
- **Processes:** no cron, scheduler or workers. Never write to the dev MySQL database outside migrations.
- **API:** the API and `docs/api.md` are unchanged. They keep `payment.cod_amount` and `payment.declared_value`, and accept `codes.*`.
- **Look:**
  - Blotter components with violet.
  - No `<style>` or `style=""` in Blade.
  - No Blade directives inside `<x-ui.*>` tags.
  - No native dialogs. Icons only via `<x-ui.icon>`.
  - No explainer copy.
  - Text and placeholders at 4.5:1 or better.
  - 44 px targets under 640 px.
- **Credentials:** the masked View/Edit rules from Part 2c still hold.
- **Money:** decimal strings, never float.
- **Tests:**
  - `Http::preventStrayRequests()`.
  - Couriers only through fakes.
  - The full suite stays green: 796 at the start.

## Review Focus

1. **PSGC cascade correctness:**
   - Manila's barangays hang off its districts. The district step appears only when the city has districts.
   - Changing the province clears the city, district and barangay.
   - The saved names and codes always belong together.
   - The codes are validated server-side against the files, never trusted blindly.
2. **COD and declared value are derived on the server from the items total:**
   - A tampered `cod_amount` or `declared_value` in the UI POST is ignored.
   - Insure off sends declared value **0**, never null. QuadX auto-insures when the value is missing.
3. **Existing data keeps working:**
   - Shipments created before this plan, with free-text addresses and no codes, still render and edit. The edit form preselects by name when no codes exist, otherwise shows the stored text in an editable fallback.
   - Settings senders without codes do the same.
4. **Courier accounts with legacy duplicates:** if a courier has more than one account (made via the CLI), `/accounts/{courier}` shows the default, else the first active, else the first. The others stay untouched and bookable through the API by `account_id`.
5. **Layout width:** no page scrolls horizontally at 390 px. Wide tables keep their own horizontal scroll inside their panel.

---

### Task 1: Centered content width

**Files:**
- Modify: `resources/css/blotter.css` and `resources/css/blotter-components.css`. Add tokens `--bl-content-max: 1240px` and `--bl-form-max: 880px`. `.x-content` gets `max-width: var(--bl-content-max)` and `margin-inline: auto`.
- Modify: the form pages' outer wrappers, with a `.x-content--form` modifier or a page class, so that Settings, account show, Add/Edit shipment and the login card are capped at `--bl-form-max` and centered. The Add shipment summary layout (Task 4) may widen its own wrapper to 1120 px.
- Test: `tests/Feature/Ui/LayoutWidthTest.php` asserts the tokens exist and that pages carry the right width class.

- [ ] **Step 1:** Write the failing test.
- [ ] **Step 2:** Implement.
- [ ] **Step 3:** Run `npm run build` and the full suite.
- [ ] **Step 4:** Commit `feat(ui): centered content width`.

### Task 2: Fixed courier accounts

**Files:**
- Modify: `app/Couriers/CourierAccounts.php`. Add `forCourier(string $courier): ?CourierAccount` (the default, else the first active, else the first) and `setUp(string $courier, string $mode, array $actor): CourierAccount`. `setUp` creates the account with label = the courier name and default = true.
- Modify: `CourierAccountsController`, `routes/web.php` and `resources/views/accounts/*`:
  - **Routes:** keyed by courier, `{courier}` constrained to `quadx|spx|lbc`:
    - `accounts.index` at `/accounts`
    - `accounts.show` at `/accounts/{courier}`
    - `accounts.update`, `accounts.test`, `accounts.reveal`, `accounts.series` under the same prefix
    - `accounts.setup` (POST `/accounts/{courier}/setup`)
  - **Remove:** `accounts.create`, `accounts.store`, the create view and the "Connect courier" header button.
  - **List:** exactly three rows in the order QuadX, SPX, LBC.
    - A row with an account shows its mode and connection badges, plus Inactive when it applies, and **Open**.
    - A row without one shows **Set up**, linking to `accounts.show`.
  - **Show, no account yet:** the header (tile + courier name) and a single section with **Mode** (Staging/Live, or Staging/UAT/Live for SPX) and a **Set up** button. This calls `setUp` and redirects to the full page with the flash `Ready`.
  - **Show, with an account:** as now, minus the **Name** field. The heading is the courier name only: "QuadX".
  - **Shipments page cards:** "Connect {Courier}" and the Inactive "Open" link now point to `accounts.show` for that courier.
- Modify: `CourierAccounts::create` callers. The CLI `account-create` is unchanged.
- Test: update `tests/Feature/Ui/CourierAccount*Test.php` to the courier-keyed routes. Add:
  - `test_list_has_exactly_three_rows`
  - `test_setup_creates_default_account_named_after_courier`
  - `test_no_name_field`
  - `test_legacy_duplicate_accounts_resolve_to_default`
  - `test_create_routes_gone`

- [ ] **Step 1:** Write the failing tests and update the existing ones.
- [ ] **Step 2:** Implement.
- [ ] **Step 3:** Run `npm run build` and the full suite.
- [ ] **Step 4:** Commit `feat(ui): fixed courier accounts, one per courier`.

### Task 3: PSGC address data and the cascade component

**Files:**
- Create:
  - `database/data/ph/{provinces,cities,districts,barangays,city-zip-map}.json`, copied byte-for-byte from `~/venta/database/data/ph/`. Record the source and date in `database/data/ph/README.md`.
  - `app/Support/PhAddress.php`
  - `app/Http/Controllers/Ui/PhAddressController.php`
  - an Alpine `phAddress` component in `resources/js/app.js`
  - `resources/views/components/ph-address.blade.php`
- Modify: `routes/web.php`.
- Test: `tests/Unit/PhAddressTest.php` and `tests/Feature/Ui/PhAddressEndpointTest.php`.

**Interfaces:**
- **`PhAddress`** (all lists sorted by name; files read once and cached with `Cache::rememberForever`, keyed by the file's mtime):
  - `provinces(): list<array{code,name}>`
  - `cities(string $provinceCode): list<array{code,name,zip}>`
  - `districts(string $cityCode): list<array{code,name}>`, empty when the city has none
  - `barangays(string $parentCode): list<array{code,name}>`, where the parent is a city or district code
  - `find(string $level, string $code): ?array`
  - `validChain(?string $state, ?string $city, ?string $district, ?string $barangay): bool`, which checks parent links
- **Route `ph.options`:** GET `/ph/{level}?parent=`, with `level` one of `provinces|cities|districts|barangays`. Auth only. Returns a JSON array. An unknown parent returns `[]`.
- **`<x-ph-address prefix="recipient" :values="..." />`** renders:
  - province, city, district (hidden unless the city has districts) and barangay selects
  - hidden inputs `{prefix}[state]`, `{prefix}[city]`, `{prefix}[barangay]` (names) and `{prefix}[codes][state|city|barangay]` (codes). A Manila district's name goes into `{prefix}[district]` (the API has a `district` field).
  - a postcode input, auto-filled from the city's zip and still editable
  - preselection from existing codes. When there are only names (legacy data), it matches by case-insensitive name within the parent, or else shows the stored text in an editable fallback with a **Choose from list** button.
- **Server rule:** when `codes.*` are posted, `PhAddress::validChain` must pass, and the names must equal the codes' names. Otherwise the error is `Choose from the list.`

- [ ] **Step 1:** Write the failing tests:
  - `test_files_load_and_counts_match` (82 provinces, 1,632 cities, 14 districts, 41,954 barangays)
  - `test_manila_has_districts_and_barangays_hang_off_them`
  - `test_valid_chain_rejects_mismatched_parent`
  - `test_endpoint_lists_children_and_requires_auth`
  - `test_component_renders_selects_and_hidden_fields`
- [ ] **Step 2:** Implement.
- [ ] **Step 3:** Run `npm run build` and the full suite.
- [ ] **Step 4:** Commit `feat(addresses): PSGC data and cascade component`.

### Task 4: Add/Edit shipment redesign

**Files:**
- Modify:
  - `resources/views/shipments/form.blade.php`, `_form-fields.blade.php` and `resources/css/pages/shipments.css`
  - `app/Http/Requests/Ui/ShipmentFormRequest.php`
  - `ShipmentsController` (`create`, `edit`, `form`)
- Test: update `tests/Feature/Ui/ShipmentCreateTest.php` and `ShipmentEditDeleteTest.php`. Add `tests/Feature/Ui/ShipmentFormRedesignTest.php`.

**Layout:** main column plus a sticky summary on desktop, stacked on mobile.
1. **Courier:** three courier tiles as a radiogroup of the bookable accounts, one per courier, via `forCourier`. Inactive or unset couriers appear disabled with the text "Not set up". Below the tiles, the chosen courier's service options.
2. **Customer:**
   - full name, as first and last
   - phone
   - email (optional)
   - **Address:** street (`address_1`), unit / building (`address_2`, optional), then `<x-ph-address prefix="recipient">`
3. **Items:** rows of name, quantity and unit price, plus a line total. **Add item.** A computed **Total**.
4. **Payment:**
   - **COD** / **Prepaid** as a segmented radiogroup.
   - With COD on, a read-only row "Collect on delivery: ₱{total}".
   - An **Insure** checkbox. With it on, a read-only row "Declared value: ₱{total}".
   - There is no COD amount or declared value input.
5. **Parcel:** weight (kg), required. A **Size** fold-out holding type and dimensions.
6. **Sender:** a collapsed summary card from `Settings::pickup()` with a **Change** button. It expands to editable fields, including `<x-ph-address prefix="pickup">`. When no sender is set, it shows expanded and required.
7. **Reference:** optional, as now.

**Summary panel:** courier, items count, total, COD collect or Prepaid, Insured yes/no, and the primary **Book shipment** button. Edit mode shows Save and **Save and book**.

**Server, in `ShipmentFormRequest::prepareForValidation`:**
- `payment.cod_amount` = the sum of the items' quantity × price when the method is COD, else null.
- `payment.declared_value` = the total when Insure is on, else `0`.
- `payment.insure` mirrors the checkbox.
- Any posted `cod_amount` or `declared_value` is overwritten.
- Money uses decimal-string math.
- **Validation:** COD requires a total greater than 0, with the error `Add item prices.`

- [ ] **Step 1:** Write the failing tests:
  - `test_cod_amount_derived_from_items_and_tamper_ignored`
  - `test_declared_value_zero_when_not_insured_and_total_when_insured`
  - `test_cod_requires_priced_items`
  - `test_codes_saved_with_names`
  - `test_legacy_shipment_without_codes_edits`
  - `test_courier_tiles_disable_unset_couriers`
  - `test_sender_collapsed_when_settings_sender_exists`
  - Update the existing tests.
- [ ] **Step 2:** Implement.
- [ ] **Step 3:** Run `npm run build` and the full suite.
- [ ] **Step 4:** Commit `feat(ui): add shipment redesign with PSGC and derived COD`.

### Task 5: Settings sender with PSGC

**Files:**
- Modify: `SettingsController`, `resources/views/settings/edit.blade.php` and `App\Support\Settings::pickup()`. It now includes `codes` and `district`.
- New setting keys: `pickup.codes.state`, `pickup.codes.city`, `pickup.codes.barangay` and `pickup.district`.
- The Sender section uses `<x-ph-address prefix="pickup_addr">`, or whatever name the controller maps to the `pickup.*` keys.
- Validation: same chain rule as Task 3. All-empty still means unset.
- Test: update `tests/Feature/Ui/SettingsScreenTest.php`. Add `test_sender_codes_saved_and_returned_by_pickup`.

- [ ] **Step 1:** Write the failing test.
- [ ] **Step 2:** Implement.
- [ ] **Step 3:** Run `npm run build` and the full suite.
- [ ] **Step 4:** Commit `feat(ui): sender address with PSGC`.
