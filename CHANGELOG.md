# Changelog

## Unreleased

### New features

- Connected stores can ask every courier for a fee in one POST /quotes call, which answers within a time budget so checkout never waits on a slow courier.
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
