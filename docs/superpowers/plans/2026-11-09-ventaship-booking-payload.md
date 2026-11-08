# Ventaship Part 2e: booking payload (Value field, packaging picker, courier-specific fields, payload fixes)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** The Add/Edit shipment form carries only what QuadX, SPX and LBC require, with a user-friendly packaging picker. It fixes the three payload bugs found on 2026-11-09 and stays compatible with VentaCart Pro.

**Owner decisions (2026-11-09):**
- **No price per item.** Items are a name plus a quantity.
- **One required Value field (₱, above 0)** is the shipment total. COD collects the Value, and Insure declares the Value.
- **The other decisions**, accepted as recommended:
  - QuadX "own packaging" maps to Box.
  - LBC shows VentaCart's 9 packages up front, with the other 8 behind "More".
  - Fees on packages come later, as their own step. They are out of scope for this plan.

**Source of truth for every courier field:** `docs/couriers/booking-payload-comparison.md`, which cites the courier docs and code by file and line. Each task's brief names the sections it uses.

**Spec:** `docs/superpowers/specs/2026-11-08-ventaship-design.md` §9, plus the owner decisions above.

## Global Constraints

- **Writes:** only inside `/home/ubuntu/ventaship`. `~/venta` is read-only.
- **Processes:** no cron or workers.
- **Dev database:** never write to it outside migrations.
- **API compatibility:**
  - The API keeps itemised `items[].price` and explicit `total`, `payment.cod_amount` and `payment.declared_value`.
  - New API fields are optional only: `parcel.category`.
  - Update `docs/api.md` and its fixtures for every API-visible change. ApiExamplesTest stays green.
- **Money:** decimal strings only (bcmath / BigInt). No float anywhere new.
- **UI rules:**
  - No `<style>` or `style=""` in Blade.
  - No Blade directives inside `<x-ui.*>` tags.
  - No native dialogs. Icons via `<x-ui.icon>`.
  - No explainer copy.
  - Text at 4.5:1 or better. Targets 44 px or bigger under 640 px.
  - Colour is never the only signal.
- **Courier calls in tests:** only through `CourierRegistry::fake` and `Http::preventStrayRequests()`. Courier adapters are tested against faked HTTP for the payload they build.
- **Suite:** the full suite stays green. It is 835 PHPUnit plus 8 node tests at the start.

## Review Focus

1. **Payload correctness per courier:**
   - **QuadX:** `total` equals the sum of the item amounts and is at least 1.
   - **SPX:** the real `parcel_weight` is sent. COD is whole pesos and at most ₱50,000. Each side is at most 150 cm and the weight is under 50 kg.
   - **LBC:** a COD parcel's `DeclaredValue` is at least the COD amount.
   - Each is asserted on the actual HTTP payload sent through faked HTTP.
2. **API clients unaffected:**
   - An API create with itemised prices that add up to the total still sends those items to QuadX unchanged.
   - Only when the item prices are missing, or don't add up to the total, does QuadX get the single consolidated line.
3. **Courier-specific fields appear and validate only for the chosen courier.** Switching courier clears or hides the other courier's fields, and the server ignores fields that don't apply.
4. **Old shipments** (with item prices and no Value) still open in Edit, with the Value prefilled from their total.
5. **The SPX pickup slot and LBC date lists** are fetched with a courier call only when the admin asks for them. A courier error shows "Couldn't load." with Retry, never a 500.

---

### Task 1: Value field and money model

**Files:**
- Modify:
  - `app/Http/Requests/Ui/ShipmentFormRequest.php`
  - `resources/views/shipments/form.blade.php`
  - `resources/views/shipments/_form-fields.blade.php`, if it's used
  - the JS money helper in `resources/js/` and its node test
  - `ShipmentsController` (edit prefill)
- Test: update `tests/Feature/Ui/ShipmentFormRedesignTest.php`, `ShipmentCreateTest.php` and `ShipmentEditDeleteTest.php`, and the node tests.

**Behaviour:**
- **Items:** rows of Name and Qty. There is no price column and no line totals.
- **Value (₱):** required, above 0, with the same normalisation as before ".5" becomes "0.50"). Message: `Enter the parcel value.`
- **Derived on the server**, overwriting anything posted:
  - `total` = Value.
  - `items[].price` = null for UI posts.
  - `payment.cod_amount` = Value when the method is COD, else null.
  - `payment.declared_value` = Value when Insure is on, else `'0'`.
- **Summary panel:** Total = Value. COD collect = Value. Declared = Value when insured.
- **Edit prefill:** Value comes from the shipment's `total`. If that's null, it comes from `cod_amount`, else from the sum of the old item prices.

**Tests:**
- `test_value_required_and_positive`
- `test_value_drives_total_cod_and_declared_and_tamper_ignored`
- `test_items_have_no_price_in_ui_posts`
- `test_edit_prefills_value_from_legacy_total`
- the node money tests updated

### Task 2: Courier payload fixes (adapters)

**Files:**
- Modify:
  - `app/Couriers/QuadX/QuadXFulfillment.php`
  - `app/Couriers/Spx/SpxFulfillment.php`
  - `app/Couriers/Lbc/LbcFulfillment.php` (and `LbcParcel` if needed)
  - `app/Couriers/CourierOrder.php`
- Test: `tests/Feature/Couriers/BookingPayloadTest.php`. It fakes HTTP and asserts the request bodies.

**Rules:**
- **QuadX items:** if every item has a price and their sum equals the order total, send them as is. Otherwise send ONE product line:
  - `description` = the joined names, e.g. "2 × Shirt, 1 × Cap", cut to 500 characters
  - `quantity` = 1
  - `amount` = total
- **QuadX `total`:** the order total, which is the Value for UI shipments. It must be at least 1, or the adapter refuses with `Parcel value is required.` and nothing is sent.
- **SPX `parcel_weight`:** always the typed `parcel.weight_kg`, whether the packaging is a preset or custom. Presets supply only the dimensions.
- **SPX checks before booking**, each refusing with a short message and nothing sent:
  - a weight is required, and it must be under 50 kg
  - each side is at most 150 cm
  - COD is whole pesos and at most ₱50,000
- **SPX `parcel_item_type`:** comes from `parcel.category` when it's one of the 7 SPX values verbatim. The default stays `General merchandise`.
- **LBC COD:** `DeclaredValue` = max(declared or insured value, COD amount, 500).
- **LBC `PreferredDate`:** also reads `pickup_date` (Y-m-d) when there's no `pickup_slot`. Document this in `docs/api.md`.

**Tests:** one payload test per rule:
- `test_quadx_consolidates_unpriced_items_and_total_equals_value`
- `test_quadx_keeps_itemised_prices_when_they_sum`
- `test_quadx_refuses_zero_total`
- `test_spx_sends_typed_weight_with_preset_dimensions`
- `test_spx_limits_refused`
- `test_spx_category_passthrough`
- `test_lbc_cod_declared_at_least_cod_amount`
- `test_lbc_pickup_date_used_when_no_slot`

### Task 3: Packaging picker and courier-specific fields

**Files:**
- Modify: `resources/views/shipments/form.blade.php`, `resources/css/pages/shipments.css`, `resources/js/app.js` (or a new module plus a node test), `ShipmentFormRequest`, `ShipmentsController` (form data)
- Create: `app/Http/Controllers/Ui/ShipmentOptionsController.php`, holding the pickup-slot and date endpoints
- Modify: `routes/web.php`
- Test: `tests/Feature/Ui/ShipmentCourierFieldsTest.php` and node tests

**Form, after the courier tile is chosen:**
- **Packaging picker:** tiles from that courier's `getParcelOptions()`.
  - **QuadX:** White Small Pouch, Blue Medium Pouch, Red Large Pouch, Box, Oversized.
  - **SPX:** the presets plus "Own packaging" (`custom`).
  - **LBC:** N-Pack Small `45`, N-Pack Large `8`, Kilobox Mini `165`, Kilobox Small `6`, Kilobox Slim `164`, Kilobox Medium `63`, Kilobox Large `7`, Kilobox XL `64`, Own box `16`. A **More** fold-out holds the remaining codes.
  - Labels are the courier's or VentaCart's. Each tile is a real radio with a visible label.
  - Default: the account setting's default parcel when one is set, else none chosen.
- **Weight (kg):** shown and required for SPX and LBC. Hidden for QuadX.
- **Size L × W × H (cm):** shown and required only for own packaging (SPX `custom`, LBC `16`). Optional for the LBC Kilobox codes, behind "Add size".
- **Item category:** SPX only. A select with the 7 SPX values, defaulting to General merchandise. Posts `parcel.category`.
- **Who pays shipping:**
  - QuadX: "Cash at pickup" (`cash`) or "Deduct from remittance" (`net_off`). Default `cash`.
  - SPX: "I pay" (`sender_pay`) or "Receiver pays" (`receiver_pay`). Default `sender_pay`.
  - LBC: hidden.
  - Posts `payment.shipping_payment`.
- **Pickup schedule:** QuadX only, as now (`service`).
- **Handover:**
  - **SPX:** "Drop off myself" (the default) or "Pick up". **Pick up** shows a **Load times** button that calls `GET /shipments/options/spx-slots` with the sender address. That calls the courier's `getPickupSlots`. The result is a slot select that posts `pickup_slot`.
  - **LBC:** a pickup date select of the next 10 business days, Monday to Saturday, built server-side without a courier call. It posts `pickup_date` (Y-m-d).
- **Delivery note:** optional, for QuadX and SPX, posts `note`, max 256 characters. Hidden for LBC.

**Server:**
- Validate only the fields of the chosen courier. Ignore and clear the others.
- `parcel.type` must be one of that courier's options.
- A courier-call failure in the slots endpoint returns JSON `{error: "Couldn't load."}` with a 502. The UI shows Retry.

**Tests:**
- `test_packaging_options_per_courier_rendered`
- `test_weight_required_for_spx_lbc_not_quadx`
- `test_size_required_only_for_own_packaging`
- `test_spx_category_and_shipping_payment_posted`
- `test_other_courier_fields_ignored`
- `test_spx_slots_endpoint_success_and_courier_error`
- `test_lbc_dates_are_next_business_days`
- `test_parcel_type_must_be_courier_option`
- node tests for show/hide and clear-on-switch

### Task 4: Drop unneeded fields and API docs

**Files:**
- Modify: the form, `ShipmentFormRequest`, `docs/api.md`, `tests/Fixtures/api/*` (only where needed), and `app/Http/Requests/Api/V1/Concerns/ValidatesShipmentParts.php` (add the optional `parcel.category`, one of the 7 SPX values).
- **Remove from the UI:**
  - recipient **Email**
  - **Unit / building**, which folds into the street line. On edit, an existing `address_2` is appended to `address_1` in the field.
  - the free-text **Type**
  - **size** shown for every courier
  - **weight** for QuadX
- **The API keeps accepting** `email` and `address_2`.
- **`docs/api.md`:**
  - Document `parcel.category`.
  - Document that QuadX receives one consolidated item line when item prices don't add up to `total`.
  - Document `pickup_date` for LBC.
  - Document the SPX limits.
- **Tests:**
  - `test_ui_has_no_email_or_unit_fields`
  - `test_api_accepts_parcel_category`
  - ApiExamplesTest stays green
