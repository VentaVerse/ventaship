# Build rulings: Part 2b (Shipments CRUD, courier dashboard)

- **Plan:** `docs/superpowers/plans/2026-11-08-ventaship-shipments-crud.md`
- **Spec:** §9 of `docs/superpowers/specs/2026-11-08-ventaship-design.md`, binding
- **Range:** d7916e7..c219f14
- **Result:** 741 tests pass. The final review came back clean after one fix wave.

Each entry gives the decision, why, and what it costs if wrong.

## Owner decisions in this slice

- **Courier dashboard, not a fulfilment process.**
  - The platforms keep their own fulfilment and status mapping.
  - Ventaship has no Status mapping screen and no seeder.
- **The list uses All / Active / Finished views,** plus courier and source filters and a courier-status filter built from the data.
- **Courier statuses use the official labels:**
  - QuadX: 58 labels from its docs. The owner's 31 picks are the reference.
  - SPX: its 12 official names.
- **SPX defaults:**
  - 5002 Damaged and 5003 Lost go to `lost_damaged`.
  - 3001 On Hold goes to `attention`.
- **No browser drafts** for this plan.
- **No cron, scheduler or workers.** UI Book, Cancel and Add reach the courier during the click.

## Rulings

**Status keys**
- *Decision:* queued and booking share the key `ventaship:booking`, shown once as "Booking".
- *Why:* the two are the same thing to the admin.
- *Cost if wrong:* none.

**Cancelled shipments**
- *Decision:* a shipment cancelled from Ventaship reads "Cancelled" (`ventaship:cancelled`). A cancel that the courier itself reports keeps its courier key.
- *Why:* otherwise a shipment you cancelled keeps showing its last courier status.
- *Cost if wrong:* none.

**Book button on unbooked rows**
- *Decision:* the Book button takes the place of the status badge.
- *Why:* follows the Part 2a draft.
- *Cost if wrong:* none.

**Sidebar label**
- *Decision:* the sidebar says "Add shipment".
- *Why:* it matches the button in the page header.
- *Cost if wrong:* none.

**Sender in the audit log**
- *Decision:* the sender details may appear in `setting.changed` audit rows.
- *Why:* they are the operator's own business contact, not recipient data.
- *Cost if wrong:* a business address in the audit log.

**Flash counts after booking or cancelling**
- *Decision:* an SPX `booking` result counts as booked. A row still `queued` after the call counts as failed ("Booking did not start"). A cancel left `requested` counts as not cancellable.
- *Why:* these are the closest truthful wording for each outcome.
- *Cost if wrong:* wording only.

**Transaction guard**
- *Decision:* the guard on the `now: true` paths checks Laravel's after-commit transaction set, not the transaction level.
- *Why:* it works inside the test suite and is the same as level > 0 in production.
- *Cost if wrong:* none.

**Parcel weight**
- *Decision:* weight is required in the UI. The API leaves it optional.
- *Why:* couriers price and label by weight.
- *Cost if wrong:* one extra field.

**Rebooking a refused shipment**
- *Decision:* a manual failed shipment shows Edit and Delete. Rebooking goes through Edit → Save and book.
- *Why:* there is no separate Book for failed shipments on the server.
- *Cost if wrong:* one extra click.

**Cancel visibility**
- *Decision:* Cancel shows only where the server would accept it (`Canceller::accepts`), and not on drafts.
- *Why:* drafts get Book or Delete instead.
- *Cost if wrong:* none.

**Edit with an inactive courier account**
- *Decision:* the account shows as "(inactive)". Save keeps it, but booking on it is refused.
- *Why:* switching accounts silently would be worse.
- *Cost if wrong:* none.

**Official labels in the API (I3)**
- *Decision:* the official labels change the API's `courier_status_label` values, e.g. "For pickup" became "Ready for pickup". This is accepted, and `docs/api.md` and its fixtures were updated to match.
- *Why:* the owner chose the official labels. The field is display wording, and platforms should map from `courier_status`.
- *Cost if wrong:* a platform that matched on the old text has to switch to `courier_status`. The docs now say so.

**Historical timeline labels**
- *Decision:* timeline events keep the label recorded at the time.
- *Why:* they are history, not live data.
- *Cost if wrong:* old events may read "For pickup".

## Parked for tracker task 26, "Run waiting jobs"

All of these need a manual run button, because no workers run on the Pi:
- **A stuck `booking`** after a request was cut off. It needs `shipments-sweep`.
- **A row stranded in `queued`** after an error before the booking started.
- **A `CancelAtCourier` job** queued by a cancel that raced a booking.
- **Long bulk sync bookings** that could hit the web server's or PHP-FPM's timeout.
