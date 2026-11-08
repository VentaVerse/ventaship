# Build rulings: Part 2c (Courier accounts screen)

- **Plan:** `docs/superpowers/plans/2026-11-08-ventaship-courier-accounts.md`
- **Range:** 98eeccf..b69f226
- **Result:** 796 tests pass. The final review came back clean after one fix wave.

## Decisions (decision, why, cost if wrong)

**LBC key set follows the account mode**
- *Decision:* staging uses the `test` keys and production uses the `live` keys. `lbc_mode` is synced to match and hidden in the UI.
- *Why:* one toggle, per the owner's rule.
- *Cost if wrong:* an LBC account can't use test keys while in production mode.

**SPX keys are always shown**
- *Decision:* SPX keys aren't tied to a mode. Its modes are Staging / UAT / Live.
- *Cost if wrong:* none.

**Removing a stored secret**
- *Decision:* out of scope. Edit replaces a secret.
- *Cost if wrong:* a key can't be blanked from the UI.

**Short secrets**
- *Decision:* secrets under 8 characters show dots only, with no last 4.
- *Why:* 4 characters would give most of a short secret away.

**Editing a key in the hidden mode group**
- *Decision:* a key edited in the mode group that is now hidden still saves.
- *Why:* the admin typed it.

**Failed Test connection**
- *Decision:* shown through the error bag.
- *Why:* the layout's notice uses the OK icon.

**Courier error messages**
- *Decision:* a real transport failure shows `No answer from {Courier}.`. Any other courier message is capped at 160 characters.

**Inactive accounts**
- *Decision:* an inactive account drops the default slot.
- *Shipments page:* a courier whose accounts are all inactive shows "Inactive" with an Open link.

**CLI webhook address**
- *Decision:* `account-create` prints the webhook URL using the public address when it is set. Otherwise it uses `app.url` as before.

**Declined**
- the CLI token mask style
- the Safari clipboard edge case
- the badge tone

## Owner direction received during this slice (next slice)

- **Courier accounts:** fixed QuadX / SPX / LBC, one account each. No add, no delete, no account name. The model stays multi-account capable, parked.
- **Add shipment:**
  - redesigned to a cleaner courier-dashboard style
  - PSGC dropdowns, using Venta's cleaned files read from disk
  - COD collects the items total
  - an Insure checkbox sends the declared value: the total when ticked, 0 when not
- **Layout:** content is centered at a narrower width.
