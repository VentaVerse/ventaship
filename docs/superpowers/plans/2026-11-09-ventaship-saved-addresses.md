# Ventaship Part 2g: Saved addresses (pickup addresses + customers)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Clean up Add/Edit shipment. Pickup becomes one saved-address card with **Change**. Customer gets a search box that fills the fields from saved customers. A new **Addresses** page (under Setup) manages both.

**Owner decisions (2026-11-09):**
- The browser draft `.superpowers/brainstorm/1404217-1794200284/content/saved-addresses.html` (A, B) is approved.
- Customers are saved **automatically** on every shipment, matched by phone.
- The 180-day purge is gone, so customers are kept.

**Architecture:**
- Two tables:
  - `pickup_addresses`: label, contact, PSGC address and codes, `is_default`.
  - `customers`: name, phone (normalised, unique), PSGC address and codes, `last_shipped_at`, `shipments_count`.
- Shipments keep their own copy of both addresses (snapshot), as today. Editing a saved address never changes a booked shipment.
- `ShipmentCreator` upserts the customer after a create or update, from both the UI and the API. That gives one code path, and it runs after the transaction commits.
- The settings `pickup.*` sender moves into a default pickup address via a migration, and the Settings → General → Sender section goes away.

## Global Constraints

- Writes only inside `/home/ubuntu/ventaship`. Never touch another project's files or processes.
- Never write to the dev DB outside migrations.
- The API contract is unchanged. API shipments still send `pickup` and `recipient` in full, and `docs/api.md` only gains a note that recipients are saved as customers.
- UI rules:
  - The existing Blotter components.
  - Form width 880 px, list width 1240 px.
  - No `<style>` or `style=""`.
  - No Blade directives inside `<x-ui.*>` tags.
  - No native dialogs (deleting uses `<x-ui.confirm>`).
  - No explainer copy. Labels only.
  - Text at 4.5:1 or better. Targets at least 44 px under 640 px.
  - The PSGC cascade uses the existing `<x-ph-address>`.
- The phone key: use the same normalisation the couriers use (find the existing phone normaliser), so "0917 123 4567", "+63917…" and "917…" are one customer.
- Tests: `Http::preventStrayRequests()`. The full suite stays green (950 at start). After each task, run `php artisan queue:restart`. The controller restarts the scheduler.

## Review Focus

1. A booked shipment's pickup and recipient never change when a saved address is edited or deleted.
2. The customer upsert never blocks or fails a booking. Errors are logged and swallowed, and it runs after commit.
3. Two shipments to the same phone written differently make one customer. The latest address wins.
4. Customer search only returns the needed fields, caps at 8 results, needs at least 2 characters, and requires auth.
5. Deleting the default pickup address promotes the next one. With no pickup addresses, Add shipment shows **Add pickup address**, never a 500.

---

### Task 1: Data, services, migration of the sender default

**Create:**
- migrations for `pickup_addresses` and `customers`
- models `PickupAddress` and `Customer` (`public_id` like the other models)
- `app/Addresses/PickupAddresses.php` (`create`, `update`, `delete`, `makeDefault`, `default()`)
- `app/Addresses/Customers.php` (`upsertFromShipment(Shipment)`, `search(string $q, int $limit = 8)`, `update`, `delete`)
- a data migration: the `pickup.*` settings become a "Main" default pickup address when they are filled, then the `pickup.*` keys are deleted

**Modify:**
- `ShipmentCreator`: call `Customers::upsertFromShipment` after commit, for create and update, wrapped so it never throws.
- Everything that read the `pickup.*` settings now reads `PickupAddresses::default()`:
  - the form prefill
  - the API default pickup, if any
- Remove the Sender section from Settings → General.

**Tests:**
- The CRUD, plus default promotion.
- The upsert merges by normalised phone, the latest address wins, and the count goes up.
- An upsert failure doesn't fail the booking.
- The settings migration moves the data.
- The settings page has no Sender section.

### Task 2: Addresses page

**Routes:**
- `addresses.index` (`/addresses?tab=pickup|customers`)
- `addresses.pickup.create`, `.store`, `.edit`, `.update`, `.destroy`, `.default`
- `addresses.customers.edit`, `.update`, `.destroy`

**Sidebar:** "Addresses" under Setup.

**Pickup tab:**
- The table holds name + Default badge, contact + phone, address, and the actions Make default and Edit.
- The header has **Add address**.
- The add/edit form fields are label, contact name, phone, street, `<x-ph-address>` and postcode, with Delete behind a confirm.

**Customers tab:**
- A search box (GET q).
- The table holds name, phone, address, shipments count, last shipped, and Edit.
- Pagination is 50 per page.
- The edit form deletes behind a confirm.

**Tests:**
- Both tabs render.
- CRUD.
- Search.
- Guest redirect.
- The UI rules: no `style=`.

### Task 3: Add/Edit shipment form

**Pickup:**
- The Pickup panel shows the default address card.
- **Change** opens a radio list of the saved addresses, each a real radio with a visible label.
- It posts `pickup_address_id`. The server snapshots that address into the shipment's `pickup`.
- On edit, it shows the shipment's own pickup snapshot. If that matches a saved address, the address is selected; otherwise a "Current" card shows.
- With no saved addresses, it shows a link button **Add pickup address** to `addresses.pickup.create`.
- The old Sender fields are removed from the form.

**Customer:**
- A search box above the fields calls `GET /customers/search?q=` (JSON: `public_id`, name, phone, the address summary, and the full address payload for the fill).
- It debounces at 250 ms, uses arrow keys plus Enter, and shows "New customer" as the last row.
- Picking a customer fills name, phone, street and the PSGC cascade (reuse `phCascade` to set codes in order).

**Shipment page:** the address line includes the district (e.g. "Barangay 659, Ermita, Manila").

**Tests:**
- `pickup_address_id` snapshots the address.
- A tampered or other id gives a 422.
- Search returns the capped fields and requires auth.
- The form has no sender inputs.
- The edit with a "Current" snapshot.
- Node tests for the search/fill logic.
- The district shows on the shipment page.
