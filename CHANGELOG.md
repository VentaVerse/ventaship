# Changelog

## 1.5.0 (unreleased)

### New features

- Dashboard is the new home page: today's bookings, parcels in transit, deliveries and what needs action, a 30-day chart of bookings by courier, shipments by status, recent shipments, and each courier's active parcels and COD out. Shipments moved to /shipments.
- Shipment statuses: Ventaship's own statuses can be renamed, switched off, deleted and added to. They start as the eight that couriers already mapped to.
- Status mapping: SPX and QuadX get a Status mapping tab where each courier status picks a shipment status, or Unmapped. Switching off or deleting a status leaves the courier statuses that used it unmapped until it is back.
- GET /shipment-statuses lists the shipment statuses (key, name, enabled, sort order), so connected stores can show the owner's own names.

### Updates

- Courier accounts is now Couriers, at /couriers. Old /accounts links still work.
- Courier logos on the Couriers pages, the Add shipment courier cards and the Shipments page cards.
- Couriers that are not set up or are switched off no longer show on the Shipments page or Add shipment.
- Only a courier's own Cancelled status cancels a booking; a status mapping changes the words, not the booking.
- The shipment's `phase` in the API can now be a status the owner added, or null when its courier status is unmapped.
- Bookings no longer wait for the queue: Ventaship books with the courier right after answering POST /shipments, so the result reaches the store by webhook within seconds, even with no queue worker running. With ?wait=N it books before answering. The queued job stays as a backup and can never book twice.
- SPX pouch sizes (Small, Medium, Large) now book with the size's saved weight as well as its dimensions, so no real weight is needed and the weight field only shows for own packaging. Connected stores can send just `parcel.type`.
- Cancel no longer shows once the courier can't cancel any more: QuadX from Pickup rider found, SPX from In Transit (2001). A cancel the courier refuses as too late keeps its reason but no longer marks the shipment Needs action.

### Bug fixes

- On hosts that block PHP's pcntl functions (HestiaCP), the scheduled queue worker no longer fails every minute: it works the queue with the new queue:drain command instead.

## 1.4.0 (2026-11-16)

### New features

- SPX Sandbox can create its own user: "Create user" asks SPX for a User ID and User Secret, using the default pickup address's mobile number, and saves them.
- Settings → Scheduled jobs sets how often couriers are asked for status updates (1 to 59 minutes), and Run now checks immediately.

### Updates

- Change password has its own page in the profile menu, so the Account page only holds your name and email.
- API key cards show the webhook secret right under the API key, from the moment the key is created, with the webhook URL and Send test below. Revoke is now Delete.
- The courier status check interval moved from the .env file to Settings. The unused COURIERS_FAKE setting is gone.

## 1.3.0 (2026-11-14)

### New features

- Connected stores can ask every courier for a fee in one POST /quotes call, which answers within a time budget so checkout never waits on a slow courier, and can choose the QuadX parcel type for it.
- GET /ping now also answers the Ventaship version, so a store's connection test can show it.
- Connected stores can ask POST /coverage about a parcel's size and weight, and a parcel SPX will not take now comes back as a clear refusal there and on POST /quotes.

### Bug fixes

- SPX now answers POST /coverage and POST /quotes: both accept the sender's and customer's names and phones, which SPX needs and which were dropped before.
- A refused booking shows its reason once, instead of also as a green message that looked like success.

## 1.2.0 (2026-11-13)

### New features

- Book shipment now shows the shipment and the courier's shipping fee for a final check before anything is booked.
- After booking, the shipment page lets you go back to your shipments or add another shipment.
- Book on a draft's page and Book on the shipments list now run the same final check, and a shipment the courier refuses stays a draft with the reason.
- Edit an SPX booking that is still waiting for pickup, up to 3 times: the recipient, the parcel, the note and the pickup time, then print the new waybill.

### Updates

- SPX shipments always ask for the parcel size, and picking a pouch fills its size in, which you can still change.
- A shipment the courier refuses is no longer saved as failed: the form stays open with the reason so you can fix it.
- Courier refusals are now explained in plain words, such as "Too long for SPX. Length is 35 cm. SPX takes up to 25 cm."
- The shipment page's Cancel button is now Cancel shipment, in red and set apart, with a Shipments link at the top to go back.
- New shipments get a courier reference like 20261112-7K2M9Q, shown on the shipment page, and a shipment added without an order number uses it as its order number.
- The reference prefix setting is gone from Settings.
- SPX pouch presets now fit SPX's limits: Medium is 25×35×15 cm and Large is 25×45×20 cm.
- SPX pouch presets are now a table: a length, width, height and weight for Small, Medium and Large.
- LBC's Assigned series can be entered while setting up the LBC account.
- Some minor improvements.

### Bug fixes

- LBC bookings no longer send an order reference longer than LBC accepts.

## 1.0.0 (2026-11-10)

### New features

- Book shipments with QuadX, SPX and LBC from one place, by hand or from your online store.
- See all your shipments in one list, with the ones that need your attention set apart.
- Print waybills for one shipment or many at once.
- Track every parcel until it is delivered.
- Save pickup addresses and customers to fill in new shipments faster.
- Connect your online stores through an API.
