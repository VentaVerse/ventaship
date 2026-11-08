# Rulings — Part 2e Booking payload (2026-11-09)

Plan: docs/superpowers/plans/2026-11-09-ventaship-booking-payload.md. Ends at 264da6e. Suite 924 PHPUnit + 10 node.

- Owner decisions 2026-11-09 (Value field, no unit price; recs 2–4 accepted by default). Start: cc2265d, 835 + 8 node. On main.
- Ruling: QuadX own packaging = Box; LBC 9 + More; fees deferred — owner didn't object to recommendations; cost: small UI changes later.
- Ruling: QuadX COD total stays = cod_amount (collected amount must be right; api.md:936); consolidate when items don't sum to it — cost: itemised API COD lines become one line when cod≠Σitems.
- Ruling: SPX now refuses with no weight ("Enter the parcel weight.") — document in Task 4 for API clients; cost: API SPX bookings without weight fail instead of booking a fake weight.
- Task 2: review Approved. Minors → Task 4 (api.md: invalid pickup_date refused not fallback; parcel.category validator+docs; itemised qty 0; non-COD LBC + COD>50000 centavos tests). Task 2: complete (2619240). PAUSED after Task 2 — owner prioritised Connected platforms (Part 2f) for the POC; resume Task 3 after 2f.
- Task 3: implemented 9f6b0db (912 + 10 node). Rulings: packaging required for all couriers in UI (legacy QuadX picks on edit); blank shipping_payment/category/handover default server-side; empty SPX slot list → 502 Couldn't load (adapter can't tell empty from failure); SPX/LBC book default service; SPX size/weight limits enforced by adapter before sending (Task 2), not the form — cost: small, revisit if owner wants inline errors
- Task 3: review ✅ Approved (3 Minors + info: QuadX UI default shipping_payment cash, was net_off implicitly — per plan; owner told). Fix round 1 dispatched (BASE 9f6b0db)
- Task 4: implemented df61f38 (920). Pre-review fix sent: keep stored email on UI edit (data loss); LBC unbookable pickup_date → 422 on API (was courier_refused). Ruling: Task 4 review folded into 2e final review
- Final review: Ready with fixes. Fix wave dispatched for #1-5. Rulings: #6 address_2 overflow left (rare); #7 LBC 10-business-day API cap is a deliberate tightening; inline SPX limits added to UI form (supersedes earlier 'adapter only' ruling for UI)
- Final review: Ready with fixes; fix wave 264da6e (docs QuadX COD total, SPX inline limits + messages, SPX method from handover, stale LBC date).
- Open owner question: QuadX UI default shipping_payment = cash (was net_off implicitly).
