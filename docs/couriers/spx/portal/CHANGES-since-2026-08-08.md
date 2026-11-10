# SPX portal: changes since the 2026-08-08 harvest

- Compared: `../api.md`, `../guide.md` (2026-08-08) against `guide/*.md` and `api/*.md` in this folder (2026-11-10).
- Method: per-page "Last Updated" comparison, then identifier/number/status-code/error-code set comparison, then manual reads of Track Order, Create Order V2, Get Order Fee, Tracking Web Hook, Evidence Proofs Webhook, authentication guide and address guide.

## Bottom line

**No substantive change was found on the portal between 2026-08-08 and 2026-11-10.**

- Same nav: 12 Guide pages and 15 API pages, with the same titles, the same page URLs and the same order. No page is new and none was removed.
- Every page's SPX "Last Updated" value is identical to the one recorded in the old harvest (all 27 checked; the newest is 4.2 Evidence Proofs Webhook, 24/02/2026).
- Differences in wording between old and new files come from how the old harvest was written (condensed or reformatted), not from SPX edits. Details below.

## New pages

None.

## Removed pages

None.

## Fields added, removed or renamed

None in SPX's own tables. Specific checks:

| Item | Result | Where (new) |
| --- | --- | --- |
| `tracking_link` | Present, unchanged (string, SPX tracking link) | `api/13-4-1-tracking-web-hook.md` "Web Hook parameter description"; `api/02-1-2-track-order.md` and `api/07-1-7-update-order.md` response tables |
| `order_id_link` | Present, unchanged | `api/13-4-1-tracking-web-hook.md` "Web Hook parameter description" |
| `driver_name`, `driver_phone_number` | Present, unchanged (webhook); `driver_name` also in the Track Order route example | `api/13-4-1-tracking-web-hook.md` "Web Hook parameter description"; `api/02-1-2-track-order.md` response example |
| `edd_min`, `edd_max` | Present, unchanged (Track Order `edd_info` object, webhook top-level fields) | `api/02-1-2-track-order.md` response table (`edd_info`); `api/13-4-1-tracking-web-hook.md` "Web Hook parameter description" |
| `remote_area_fee` | Not present anywhere, in the old harvest or the new one. Not added by SPX. | n/a |
| `latest_chargeable_parcel_length/width/height` | Unchanged. The old file merged them into one row; the portal lists three rows. | `api/13-4-1-tracking-web-hook.md` "Web Hook parameter description" (old: `../api.md` "4.1 Tracking Web Hook") |
| Evidence Proofs Webhook fields (`tracking_no`, `customer_order_id`, `id`, `epod_list`, `epop_list`, `epor_list`, `epooh_list`, `tracking_code_name`, `status`, `status_code_name`) | Unchanged. `id` and `status_code_name` still have an empty description. There is still no JSON example. | `api/14-4-2-evidence-proofs-webhook.md` payload table |
| Create Order V2 request fields | Unchanged. The example JSON still includes `deliver_country` and `deliver_town`, which are not in the request table. | `api/04-1-4-create-order-v2.md` "Request body" and example |
| Get Order Fee response | Same fields. Portal shows the `parcel_info` object typed `ActualParcelInfo` and the `chargeable_weight` unit text. The old file did not carry that type label. | `api/10-2-2-get-order-fee.md` response table |

Note: the old `api.md` flattened nested tables (for example `orders[].sender_info.sender_state / sender_city / ...` as one row). The portal files rebuild the nested tables one field per row. Compare them as the same fields, not as additions.

## Status codes added

None. The tracking status table (`guide/09-order-status-code.md`, "Order status code") holds the same 12 codes as before: 1001, 2001, 2006, 3001, 4001, 5001, 5002, 5003, 6001, 6002, 6003, 7001.

The error-code table (`guide/08-error-codes-mapping.md`, "Error codes mapping") holds the same 103 codes as the old `../guide.md` "Error codes mapping" (code-by-code comparison, no code added or removed).

## Changed limits or rules

None found. Number checks (max length 16/32/64/256, 100 orders per batch, PH COD maximum 50,000 Peso, signed 64-bit `random-num`) match.

- Batch limit: `api/04-1-4-create-order-v2.md` ("At most 100 per batch"), `api/09-2-1-check-shipping-fee.md`.
- PH COD cap: `api/04-1-4-create-order-v2.md` Request body, `cod_amount`.
- PH phone rule (11 digits starting 0, or 12 digits starting 63): `api/04-1-4-create-order-v2.md` Request body, `sender_phone`.
- Webhook rules (HTTP 200 reply, 3 second timeout, sending stops after repeated rejections or timeouts): `api/13-4-1-tracking-web-hook.md` and `api/14-4-2-evidence-proofs-webhook.md`, "Web Hook Response".

## Changed signature or auth instructions

None. In `guide/04-api-authentication-guide.md` ("Generates the signature content check-sign"):

- The signing format string is still `"%d_%d_%d_%s"` (app-id, timestamp, random-num, payload).
- The algorithm is still HMAC-SHA256 with `app-secret`, hex-encoded.
- The test vector is unchanged: app-id 100000, app-secret `H25HY53GO4BA2GQ`, timestamp 1677918414, random-num 926611981. The expected result is `97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d`, and the Windows result is still identical to it.
- Only the Go example is on the page, as before.
- Request headers are unchanged (`app-id`, `check-sign`, `timestamp`, `random-num`): `guide/03-how-to-call-the-open-api.md` "General request header".
- Webhook auth is unchanged: the `check-sign` header, signed with the third party's `app-secret` (`api/14-4-2-evidence-proofs-webhook.md` "Web Hook Authentication").

## Corrections to the old harvest (documentation quirks re-verified)

These are not SPX changes. They affect how you read the old notes in `../index.md`.

- `guide/06-get-available-addresses.md`, "How to use the address file?": the PH table still lists `sender_city` / `deliver_city` under both the City and PostCode columns. The TH table uses `sender_post_code` / `deliver_post_code`. The quirk is real in the source, not a harvest error. Use `sender_post_code` / `deliver_post_code` for PH, as `api/04-1-4-create-order-v2.md` documents.
- `guide/10-update-order-flowchart.md` still says "API 1.9 Update Order", while `guide/02-api-list.md` and the nav call it 1.7. The link points to a `spx.co.th` URL.
- Old `../api.md` "4.2 Evidence Proofs Webhook" had harvester notes about an "incomplete" table. The source is confirmed unchanged: `id` and `status_code_name` really have no description.
- Old `../api.md` "1.3 Cancel Order" added an unsourced "Recall" note (13002 OrderUnableCancelCode). That text is from `guide/08-error-codes-mapping.md`, not from the Cancel Order page.
- Old `../guide.md` "API authentication guide" said only the Go tab was captured. This harvest confirms the page itself carries only the Go code. The intro sentence still says "Go/Python3/Node.js/Java/PHP/C#".
- The old `../api.md` "1.2 Track Order" had harvester commentary mixed in (for example "abridged fields shown"). The portal files are the verbatim source text.
