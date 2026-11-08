# Build rulings: Part 2a (shell, login, fulfillment, settings)

Plan: `docs/superpowers/plans/2026-11-08-ventaship-ui-shell.md`.
Range: c6dba4f..c283d00.
Result: 655 tests pass. The final review came back clean after one fix wave.

Each line gives the decision, why it was made, and what it costs if it's wrong.

## Display and behaviour

**Display phase.**
- *Decision:* when `phase` is null, the screens derive it from `state`:
  - draft → draft
  - queued or booking → booking
  - booked → to_handover
  - failed or unknown → attention
  - cancelled → cancelled
  - The API still returns `phase: null` until the courier's first status (api.md §8.2).
  - Book keys on `state = draft`.
- *Why:* the API contract stays unchanged for VentaCart Pro.
- *Cost if wrong:* two notions of phase, one in the API and one in the screens.

**Unpaid tab.**
- *Decision:* the tab shows `payment_state = cod_unpaid` in any phase (D6).
- *Why:* that is what D6 specifies.
- *Cost if wrong:* a count could be off by stale rows. In practice cancels void COD, so the overlap is nil.

**Unknown filter values.**
- *Decision:* an unknown courier account or source id applies no filter.
- *Why:* the plan says unknown values fall back.
- *Cost if wrong:* none.

**Book wiring.**
- *Decision:* every Book posts `ids[]` to `POST /shipments/book`. A row's Book button uses its own small form outside `#bulk`, with no JavaScript.
- *Why:* it follows the plan's interface.
- *Cost if wrong:* some extra hidden forms on draft rows.

**Skipped-waybill message after a download.**
- *Decision:* the message is stored with `session put('notice')`, and the layout reads it with `pull()`.
- *Why:* otherwise the 20 s poll would use up a normal flash before the page reloads.
- *Cost if wrong:* one extra session key.

**Input borders.**
- *Decision:* app inputs keep VentaSync's darker field border. Only the login page uses the draft's lighter border.
- *Why:* the darker border meets the contrast needed for input edges.
- *Cost if wrong:* a small visual difference from the drafts.

**Login draft.**
- *Decision:* the owner approved the login draft on 2026-11-08, with a type-scale fix.

**Poll response to guests.**
- *Decision:* a guest calling poll with JSON gets 401. A guest loading the page in HTML gets a 302 to the login page.
- *Why:* this is Laravel's default behaviour.
- *Cost if wrong:* none.

## Security

**Password change.**
- *Decision:* changing the password rotates the remember token and signs out every other session (`auth.session` on the UI group, plus deleting the other `sessions` rows). The current session stays signed in.

**Current-password check.**
- *Decision:* it is throttled at 5 attempts per 60 s, keyed by admin and IP.

**Re-authentication before live LBC/SPX switches.**
- *Decision:* declined for now.
- *Why:* the switch is one of four locks, and the env flag can only be set on the server. Re-authentication would also need a new screen, which needs an owner-drafted design first (D10).
- *Cost if wrong:* someone holding a hijacked admin session could flip one of the four locks.

**Lockfile supply-chain flag.**
- *Decision:* treated as a false positive.
- *Why:* the versions exist on the registry and the integrity hashes match. `npm audit signatures` verified 96/96.

**Session kept alive by the poll.**
- *Decision:* accepted.
- *Why:* this is an admin tool, and an open Fulfillment tab keeps the session active by design.

## Parked

**`needs_attention` with a null phase.**
- *Issue:* the display phase ignores `needs_attention`. A booked shipment that needs attention but has no courier status yet shows "To handover".
- *Follow-up:* map `needs_attention` plus a null phase to attention, alongside the shipment drawer.
