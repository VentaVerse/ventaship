# Ventaship: design

Date: 2026-11-08 · Status: approved by the owner, 2026-11-08

This document records what the owner decided in the 2026-11-08 brainstorm and how it changes the existing technical plan. The technical plan supplies the mechanics (courier port, booking safety, webhook router, outbox, labels).

**Base plan:** `~/ventacart-pro/extensions/docs/plans/2026-11-08-ventaship-plan.md` (sections 1–9 plus the addendum A1–A9). Where the two disagree, this document wins. The biggest change is §4: **the base plan's multi-tenant SaaS model is dropped.**

**Approved drafts** (open them in a browser): `.superpowers/brainstorm/3326191-1794101622/content/`
- `brand-color.html` (option C)
- `dashboard.html`
- `admin.html`
- `settings.html`

---

## 1. Intent

Ventaship is a **self-hosted courier connector** for Philippine online sellers, part of the owner's Ventaverse (products named "Venta…").

**What it is:**
- A seller installs their own copy, the way VentaSync is installed: one install per seller.
- They connect it to **their own** courier accounts and API keys: QuadX, SPX and LBC for now.
- Then they connect every selling platform they use to it: VentaCart, VentaCart Pro, OpenCart, WooCommerce or anything else that can call an API.
- Each platform keeps doing the buying process. Ventaship does the shipping: booking, waybills, tracking, courier webhooks and "unpaid until delivered".

**Why it's different from the two PH companies that shut down:**
- Those companies were intermediaries that resold their own courier accounts.
- With Ventaship, nobody sits in the middle. The seller owns the software and the courier accounts, and pays the courier directly.
- Ventaship never sells shipping and never holds COD money.

**Why it's a separate app from VentaCart:**
- VentaCart could do this inside itself, as it does today.
- Ventaship exists to give **more control over courier work without changing VentaCart or VentaCart Pro**, and to serve any platform, not only Venta ones.
- VentaCart's courier code gets stripped out over time and replaced by calls to Ventaship. The fulfillment process users see stays the same.

**Three ways shipments get in:**
1. **API**, from a connected platform. The VentaCart Pro Seller Center comes first.
2. **Manual entry**, in Ventaship.
3. **CSV upload**, in Ventaship. It creates drafts that are reviewed, then booked.

The last two serve sellers whose orders come through chat or social media.

**First use: internal, on the owner's Raspberry Pi ("buko pi").**
- One Ventaship install serves the owner's platforms.
- VentaCart Pro books every vendor's parcels with the **operator's** courier accounts. Vendors never see Ventaship.
- The Seller Center sends each vendor's pickup address with every call, as base plan §1 describes.

## 2. Goals, non-goals and anti-goals

**POC done (this build)** = through the client API, a signed client books a **QuadX** parcel, gets the waybill, receives every status change as a signed outgoing webhook through to delivered, and `docs/api.md` documents all of it. This build proves it with an automated end-to-end test and a run on QuadX staging. **The overall POC**, which the VentaCart Pro session completes, is VentaCart Pro booking a QuadX parcel through Ventaship and tracking it to the end:
- book → waybill → courier webhook → status forwarded to VentaCart Pro → delivered
- QuadX is complete and live
- SPX and LBC follow on the same path (their API and UAT runs have passed, but they aren't in use yet)

**After the POC:**
- the fulfillment dashboard
- manual entry and CSV import
- the courier accounts and status mapping screens
- the API connection page
- the system screens
- settings

**Parked: not built and not designed now.**
- multiple users, roles and permissions
- multi-tenant SaaS: workspaces, sign-up, plans, billing

The owner sees these as possible futures ("it has the potential to become those companies if someone wants"). Keep code clean enough that a later change could add them. **Do not add tenancy columns or scopes now.**

**Non-goals for now:**
- remittance imports
- the audit-log UI
- the public docs site

**Anti-goals.** Any of these makes it a failure, even if it technically works:
- Ventaship acting as a shipping reseller, or holding COD.
- A real LBC booking made by a test or by accident. LBC has no sandbox and no cancel.
- Changes forced onto VentaCart or VentaCart Pro beyond swapping their courier calls for Ventaship calls.
- Work done outside `/home/ubuntu/ventaship` without the owner's approval.
- UI with explainer copy, helper sentences or tooltips (owner rule).

## 3. Decisions

**D1. One install, one admin.**
- A single `admin` login guard, with one or more admin rows created by `php artisan ventaship:admin {email}`. No roles.
- No sign-up, invites, members or workspace switcher.
- The "Team" settings tab from the draft is dropped. Account settings (name, email, password, TOTP later) take its place.

**D2. Connected platforms = client apps.**
- One install has many client apps, one per platform: "VentaCart Pro", "VentaCart Store", "My WooCommerce" and so on.
- Each has its own API keys, signing secret, outgoing webhook URL and secret, and event filter.
- Every shipment records which app it came from (`client_app_id`, null for manual and CSV). The fulfillment list shows it as the **source**.
- `client_reference` stays unique per app while active, as in the base plan.

**D3. Look: the Ventaverse base, with VentaSync as the source.**
- Copy VentaSync's "Blotter" system: `~/ventasync/ventasync-pro/resources/css/blotter*.css`, `components/ui/*` and `layouts/blotter.blade.php`.
- That covers the tokens, Archivo and JetBrains Mono, the navy sidebar, radii 16/11/4/999, the shadows, the attn/good/bad/cool state colours and the theme switch.
- Stack: Tailwind v4 (CSS-first `@theme`), Alpine 3 and plain Blade. No Livewire, Inertia or Filament.
- Keep VentaSync's enforced rules: no CSS in Blade, no native dialogs, icons only from the icon component, colour never the only signal.
- Do not use VentaCart's `--v-*` layer.

**D4. Brand colour: violet.**
- brand `#8B7CF6` (logo mark, active nav marker, active tab underline)
- button and strong fill `#5B45D6`, text on it white
- link and ink-on-wash `#4C38C2`
- wash `#efecfe`

It is used only for brand and interaction, never for status. Dark-mode values follow VentaSync's method, and contrast must pass AA.

**D5. Fulfillment screen, the home page** (`dashboard.html`, approved).

*Courier cards at the top:* one per courier account, like VentaSync's per-channel `fh-card`. Each card shows:
- the courier mark and name, account label and mode (Live or UAT/Staging), and a connection badge
- **To ship** and **Delivered** as large figures
- To handover, Shipping and Failed as smaller lines
- a **COD unpaid ₱ total** strip
- for a courier with no account, a muted card with one "Connect {courier}" button

The courier accent shows on the logo tile only, with no coloured card border.

*Shipment list below:*
- Tabs: All · Unpaid · To ship (sub-chips **To pack** and **To handover**) · Shipping · Failed · Lost & damaged · Delivered · Returning · Cancelled.
- Filters by courier and by source.
- Row checkboxes with a bulk bar: Print waybills, Book, Cancel.
- Each row shows:
  - the recipient, Ventaship ref plus client order ref, and city and item count
  - the courier and service, and the tracking link
  - the amount and payment badge
  - the phase badge
  - the source: the client app's name, "CSV import" or "Manual"
- Unbooked rows show a Book button.

*Sidebar, with the workspace switcher from the draft removed:*
- Fulfillment, New shipment, Import CSV
- Setup: Courier accounts, Pickup addresses, Status mapping
- Developers: Connected platforms (API connection), Webhook logs
- System: Health, Unmatched webhooks, Failed jobs
- Settings

**D6. Tabs mapped onto the plan's phase spine (my call).**
- **To pack** = draft and attention (the plan's "To arrange").
- **To handover** = booking and to_handover.
- **Unpaid** = `payment_state = cod_unpaid` across every phase. Like All, it overlaps the other tabs.
- The payment filter chips from A6 stay as a secondary filter.

**D7. The approved admin page becomes the System area (my call).**
- The admin draft's courier-health panel, unmatched webhooks and failed jobs carry over as System screens in the one app.
- Dropped: the workspaces table, the workspace and active-user stats, and "Open dashboard as".
- Kept stats: shipments in the last 30 days, and unmatched webhooks.
- These screens get a fresh browser draft before they're built (D10).

**D8. Settings** (`settings.html`, the look is approved).
- One settings area. Tabs: General, Shipping defaults, Waybills, Notifications, Couriers & safety, Account.
- **Shipping defaults** as drafted:
  - default courier account, pickup address, parcel weight and service
  - new shipments default to COD or Prepaid
  - COD paid only after delivery, and track remittance
  - automation switches
- **Couriers & safety** (the platform-settings part of the draft):
  - which couriers are enabled
  - the public webhook base URL
  - outbound retry policy
  - the "allow live LBC / SPX bookings" switches, off by default
- The owner said "add, edit or strip as we go", so the setting contents are a starting set. Build the shell and only the settings that something actually reads.

**D9. Live-booking safety (my call).** These locks add to the base plan's guard; none replaces it.
- **LBC:** all of these must be on:
  - the env flag `COURIERS_LBC_LIVE`
  - the settings switch (D8)
  - the per-account `allow_live_booking` latch
  - the account in production mode
- **SPX:** production-mode accounts need the same env, switch and latch, using `COURIERS_SPX_LIVE`. SPX UAT accounts book freely.
- **QuadX:** no extra lock. It is complete and live, and has staging and cancel.
- **Tests:** always `Http::preventStrayRequests()`, and fakes for every courier.

**D10. Owner working rule.** Every UI question or new screen gets a **browser draft first**, approved before it's built. Still to draft:
- New shipment (manual entry)
- CSV import (upload, column check, review)
- Courier accounts (connect, settings form, test, webhook URL, LBC blocks)
- Status mapping
- Connected platforms (apps, keys, webhook URL and secret, deliveries, call logs, quickstart)
- Shipment detail drawer
- Pickup addresses
- The System screens
- The login page

**D11. Courier credentials.**
- Encrypted with the separate `VENTASHIP_VAULT_KEY` (base plan).
- The admin can view them, throttled and audited, as well as edit and remove them, on the courier account page.
- In the POC, credentials are entered with `account:create` and `account:set --secret`. The Courier accounts screen comes in Part 2.

## 4. Changes to the base plan

1. **Drop multi-tenancy entirely:**
   - the `workspaces`, `users` and `workspace_user` tables
   - the `workspace_id` columns, the `BelongsToWorkspace` scope and `CurrentWorkspace`
   - the `/app/{workspace}` routes and "Open dashboard as"
2. **One app area** behind the `admin` guard at `/`. `admin_users` (rename to `admins`) is the only user table.
3. **These now hang off the install** instead of a workspace: `client_apps`, `api_keys`, `courier_accounts`, `shipments`, `tracking_series`, `status_mappings`, `outgoing_events`, `api_requests`, `courier_calls` and `audit_entries`.
   - API keys still belong to a client app, and the outbox still delivers per app.
   - The "unique default per courier" rule becomes `default_slot = courier`.
4. **Status-mapping overrides** are per install: unique (courier, courier_status).
5. **The settings `json`** that sat on `workspaces` moves to a `settings` key/value table: retention days, `courier_ref` prefix, shipping defaults and safety switches.
6. **Look:** VentaSync's Blotter base plus violet (D3, D4). This replaces A9/O8.
7. **Manual entry and CSV** move from "later" to Part 2:
   - `source` takes the values `api`, `manual` and `csv`
   - new table `csv_imports` (id, admin_id, filename, rows, created, failed, errors json, status)
8. **Poll endpoint:** the base plan describes core's `FulfillmentController::poll` as a counts endpoint, but the code actually runs `courier:poll-status`. Ventaship's `poll()` is a new JSON endpoint returning counts plus the visible rows' phases.
9. **Mass print is new work.** No VentaCart code exists for it.
10. **Commands:** `workspace:create` is dropped. `app:create`, `key:issue`, `account:create {courier}` and `account:set {account} {key} {value} [--secret]` stay.

## 5. Build order

**Part 1, the POC** (base plan A7 V0–V7, without tenancy and without V3-lean), **QuadX** to the "POC done" line:
- **V0:** repo, database, app on 8040, admin auth, `preventStrayRequests`, the commands.
- **V1:** client-app auth (key, HMAC and replay), idempotency, and `api_requests`.
- **V2:** the port, per-account settings, `PhaseResolver`, and `payment_state`.
  - SPX and LBC are ported against fakes.
  - LBC must first fix its known breakages: the `checkServiceability` and `resolvePendingCreation` signature mismatches, its leftover VentaCart order and `OrderHistory` references, and the missing live guard.
- **V4:** the Shipments API.
- **V5:** labels.
- **V6:** the inbound router and polling.
- **V7:** the outbox.
- All Part 2 schema lands in the Part 1 migrations.

**Part 2, the screens:** each starts with a browser draft (D10).
- the shell
- the fulfillment page
- bulk actions
- courier accounts
- status mapping
- connected platforms
- manual entry and CSV
- the System screens
- settings

## 6. Constraints

- Raspberry Pi dev environment. Laravel 12, PHP 8.5, MySQL database and user `ventaship`, port 8040, timezone Asia/Manila.
- No sudo for Claude. The owner runs `CREATE DATABASE` and `CREATE USER`.
- Own git repo at `/home/ubuntu/ventaship`. `.superpowers/` goes in `.gitignore`.
- The client API is platform-neutral. Nothing VentaCart-specific goes in it, so OpenCart and WooCommerce can connect later.
- Never make real LBC calls in tests.
- No explainer copy in the UI.

## 7. Still open

**For the owner:**
- **Q1, answered:** the Seller Center and marketplace-extension work is **out of scope for this build**. Another Claude session, working in VentaCart Pro, builds VentaCart Pro's side of the connection. This build stays inside `/home/ubuntu/ventaship`: it ships the API and webhooks, plus a written API contract (`docs/api.md`) for that session to build against.
- **Q2, answered:** prove the full loop on **QuadX staging** first, simulating the last webhook steps if staging never sends them. Then book **one real parcel on live QuadX** once O1 (the public https host) is settled.
- **O1:** the public hostname, needed before real courier webhooks.
- **O2:** whether the VentaCart store and the marketplace share the QuadX account. Default: shared. Each is a client app on the one install, so a relay may no longer be needed if the store also moves to Ventaship.
- **O3:** the LBC number block.
- **O4:** the SPX app model.
- **O5:** whether to show the courier fee to sellers. Default: no.
- **O6:** personal-data retention. Default: 180 days.
- **O7:** "Mark remitted" by hand. Default: yes.

**Before anyone else installs it:** check each courier's API terms for software used by third parties, and the PH Data Privacy Act.

## 8. Left to the builder

- Class layout, beyond the base plan's.
- The CSV template columns. Start from the manual entry fields: recipient name, phone, address lines, barangay, city, province, postcode, COD or prepaid, COD amount, declared value, item summary, weight, courier and service. Add a downloadable sample.
- Dark-mode token values.
- Courier-health metrics, taken from `courier_calls` and `webhook_receipts`.

## 9. Direction change (2026-11-08, owner): courier dashboard, not a fulfilment process

**Ventaship is the courier dashboard and gateway.** It is what you would use if you logged into SPX, LBC and QuadX directly, but one screen for all three, plus the API. It is **not** a fourth fulfilment process. VentaSync, VentaCart and VentaCart Pro already have fulfilment processes, and a fourth would be redundant and confusing.

**The platforms keep their own fulfilment.**
- A platform books through the API.
- It receives signed webhooks carrying `courier_status`, `courier_status_label` and `phase`.
- It maps those onto its own order statuses on its own side.
- The Part 1 API and webhooks are unchanged.

**In Ventaship:**
- **Shipments page.** One list of bookings. The tabs are plain filters with no workflow: All · Awaiting pickup · In transit · Delivered · Problem · Returned · Cancelled. Courier cards, waybill printing, Cancel and COD amount/state stay.
- **Add shipment** books on save. The form takes:
  - customer details
  - courier account
  - payment method (COD or prepaid)
  - parcel and items
  - the sender (pickup), defaulted from Settings
- **There is no "book later" in the UI.** A shipment the courier refused stays editable, and you rebook it.
- **CRUD:**
  - Edit and delete are allowed only while nothing exists at the courier: a draft (from the API) or a failed booking.
  - A booked shipment is cancelled, never deleted.
- **No background processing on the Pi.** There is no cron, scheduler or queue workers (owner rule). Anything clicked in the UI (Add/Book, Cancel) talks to the courier during the request. API-created work waits in the queue until a "Run waiting jobs" button runs it (tracker t26). Status polling runs from a "Check statuses now" button (t25).

**Dropped:**
- the Status mapping screen and its seeder (D10 item, tracker t24)
- the fulfilment-style tabs and chips from D5/D6: To pack, To handover, Unpaid

Ventaship keeps its built-in courier→phase defaults only to give platforms the shared `phase`. The owner's 2026-11-08 choices for those defaults:
- **SPX:** 5002 Damaged and 5003 Lost → `lost_damaged`; 3001 On Hold → `attention`.
- **QuadX:** the owner's 31 statuses.

The courier references live in `docs/couriers/`.

**Superseded by this section:** D5's tab list, all of D6, and the D10 items for Status mapping and the shipment drawer. The drawer is replaced by a shipment detail page.
