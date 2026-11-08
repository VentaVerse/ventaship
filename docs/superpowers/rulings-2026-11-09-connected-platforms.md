# Rulings — Part 2f Connected platforms (2026-11-09)

Plan: docs/superpowers/plans/2026-11-09-ventaship-connected-platforms.md. Commits 3dadfa4..7e8767b. Suite 904 PHPUnit + 8 node.

- Owner: "do it" (keys viewable again; signed API kept, not Sanctum). Start: 42435f9, 844 + 8 node. On main. Part 2e paused after its Task 2.
- Ruling: generated keys get Revoke/Issue (no Edit); webhook secret gets Regenerate — owner's masked rule kept for display (View); cost: none.
- Task 2: implemented 70b8559 (870 tests). Ruling: extra neutral "No deliveries" badge accepted — honest state not covered by plan — cheap to rename
- Task 2: review ✅ Approved (7 Minors). Fix round 1 dispatched for #1-5 (BASE 70b8559). Ruling: #6 import order, #7 switch error slot skipped — cosmetic/unreachable. Ruling: timezone fixed by app writing created_at, not DB tz config — narrower blast radius
- Task 3: review ✅ spec, Changes needed (2 Important: prefix collision → address by id; Regenerate hidden when secret null). Fix round 1 dispatched (BASE 8835599). Ruling: deviate from plan key:{prefix} → key:{id} — collision reveals wrong key — cost: none. Owner raised 'says webhook, not API sanctum' — auth direction question pending
- Ruling/owner: switch API auth to Sanctum only (Task 5 appended to plan; runs before Task 4). Webhook signing kept — other direction
- Task 5: review ✅, Approved with changes (1 Important: keep prefix on api_requests). Fix round 1 dispatched (BASE df5f1bf). Ruling: revoke stays hard delete; identity stored on log row
- Task 4: implemented 4d68866 (903). Ruling: Task 4 task-review folded into the final whole-slice review (small, low-risk UI; final reviewer checks its brief explicitly) — cost if wrong: one extra fix wave
- Final review: Ready with fixes (7 Minors). Fix wave dispatched (BASE 4d68866). Rulings: public word 'key', field label 'API key'; 'Issue key' kept; CLI 'shown once' text dropped (untrue now); KeyRevoke accepts id
- Final review: Ready with fixes; fix wave 7e8767b re-checked inline by controller.
- Dev MySQL migrated 2026-11-09 after backup storage/backups/ventaship-pre-sanctum-20261109-0053.sql (git-ignored).
