# Build rulings: Part 2d (layout, fixed accounts, PSGC, Add shipment redesign)

- **Plan:** `docs/superpowers/plans/2026-11-09-ventaship-dashboard-polish.md`
- **Range:** f0e3481..ad1c846
- **Result:** 835 PHPUnit tests and 8 node tests pass. The final review came back clean after one fix wave.

## Decisions (decision, why, cost if wrong)

**Content widths**
- *Decision:* lists 1240 px, forms 880 px, Add shipment 1120 px (with a summary panel). All centered.
- *Why:* the owner asked for centered, not full width. VentaSync centers at 1600 px.
- *Cost if wrong:* one token to tune.

**Courier accounts**
- *Decision:* fixed QuadX / SPX / LBC, one account each. `forCourier` picks the default account, else the first active one, else the first.
- *Set up:* locked and transactional.
- *Legacy duplicates:* untouched, and still bookable through the API by `account_id`.

**PSGC data**
- *Decision:* Venta's cleaned files, copied byte-for-byte and read from disk.
- *How:* compiled to `storage/framework/cache/ph/*.php` and served from opcache. They are not in the cache store, because 7.2 MB exceeds common MySQL packet limits.
- *Why:* matches live VentaCart addresses.
- *Cost if wrong:* refreshing the data means replacing the files.

**`matchesPosted`**
- *Decision:* codes are optional per level. Every coded level must chain to its parent and match by name. Uncoded levels are legacy free text.

**Cascade race**
- *Decision:* a ticket per level, plus a parent re-check on each response.

**Postcode**
- *Decision:* fills from the city's ZIP, or clears when the city has none, unless the user edited it.

**UI money**
- *Decision:* COD amount = items total. Declared value = the total when Insure is on, else an explicit `0`.
- *Why:* VentaCart parity.
- *API:* keeps its explicit fields.

**Insure on a ₱0 total**
- *Decision:* declares 0.00.

**Address errors**
- *Decision:* shown under Province, or under District for an unmatched district.

**Enter key on edit**
- *Decision:* Enter means Save, not Save and book.

**Prices and quantities**
- *Decision:* normalised before validation ".5" becomes "0.50"). Anything the total can't use is rejected.

## Open after this slice (owner, 2026-11-09)

- **Booking form scope:** the owner questions asking for item quantity and price at all. They want packaging chosen from each courier's options (pouches / box / oversized / own packaging), plus weight and size, with only the fields couriers require while staying VentaCart Pro compatible.
- **Payload comparison:** in progress (couriers × Ventaship × VentaCart × API).
