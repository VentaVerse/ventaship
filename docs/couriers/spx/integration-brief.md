# SPX Open API — Integration Brief

Distilled from `docs/spx/guide.md` and `docs/spx/api.md` (harvested 2026-08-08 from the SPX developer portal). This is the input for planning Venta's `CourierFulfillmentInterface` adapter for SPX. No credentials included anywhere in this brief.

## 1. Base URLs

Region-scoped by CID (country ID). For Philippines, CID = `ph`:

| Environment | Base URL |
|---|---|
| UAT / non-live | `https://test-stable.spx.ph/` |
| Live / production | `https://spx.ph/` |

All endpoint paths below are relative to `{{host}}` = one of the above.

Should probably live in `.env` as `SPX_BASE_URL` (or two vars, `SPX_UAT_URL` / `SPX_LIVE_URL`, switched by an env flag) so it matches Venta's existing per-deployment `.env` pattern — do not hardcode.

## 2. Auth scheme (exact signing algorithm)

Every request (including webhook verification) is signed with **HMAC-SHA256** over a specific concatenated string, sent via 4 request headers.

**Required headers on every API call:**

| Header | Type | Description |
|---|---|---|
| `app-id` | integer | Issued by SPX per-app |
| `check-sign` | string | HMAC-SHA256 signature, hex-encoded |
| `timestamp` | integer | Unix timestamp, **seconds** (not ms) |
| `random-num` | integer | signed 64-bit random integer |

**Signature construction:**

```
originalValue = fmt.Sprintf("%d_%d_%d_%s", app_id, timestamp, random_num, payload)
check_sign = hex(HMAC_SHA256(key=app_secret, message=originalValue))
```

Where `payload` is the **exact JSON request body string** (not a normalized/re-serialized version — the raw bytes sent, since line-ending differences between Unix/Windows change the signature output). All requests are `POST`, `Content-Type: application/json`, UTF-8.

**Verified test vector** (use this to unit-test the signer before touching the live API):

| param | value |
|---|---|
| app-id | 100000 |
| app-secret | H25HY53GO4BA2GQ |
| timestamp | 1677918414 |
| random-num | 926611981 |
| payload | `{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}` |
| expected check-sign | `97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d` |

**Implementation note for Laravel:** since `payload` must be the literal bytes sent over the wire, build the signature from the same string you pass to Guzzle's request body (e.g. `json_encode($body)` called once, reused for both signing and the send) — don't let Guzzle re-encode independently, or a byte-level mismatch (e.g. escaping/whitespace) will produce `1007 CheckSignVerificationFailedCode`.

**Credentials:** `app-id` + `app-secret` are issued once per integration by SPX staff (not self-service). A *second* pair of credentials — `user_id` + `user_secret` — is obtained via **3.1 Create Account** (`POST /open/api/v1/account/create`, keyed by phone/email) and is required in the *body* of every order-related call (separate from the header-level app credentials). Store both pairs encrypted (e.g. Laravel encrypted casts / `.env`), never in the repo.

**Auth error codes to handle explicitly:** `1001` app-id/secret wrong, `1003` bad timestamp, `1004` bad random-num, `1005` app-id mismatch, `1006` check-sign header missing, `1007` check-sign verification failed, `1008` empty body, `1009` request expired (timestamp too old — refresh timestamp + resign and retry).

## 3. Create shipment / order

**Endpoint:** `POST {{host}}/open/api/v2/order/batch_create_order`

Two-step async flow — plan the adapter around this, not a single synchronous call:

1. **Create:** submit up to 100 orders in one call → returns only a `batch_no` (int). The orders are NOT yet guaranteed created.
2. **Poll result:** `POST {{host}}/open/api/v2/order/get_order_create_result` with that `batch_no` → returns `task_status` (1 New, 2 Waiting, 3 Running, 4 Done, 5 Failed), and once `Done`, the actual `tracking_no` per order plus a `fail_list` for any that failed. **Even if everything failed, task_status is still `Done`** — always check `fail_count`/`fail_list`, don't infer success from status alone.

**Request body shape** (per order, nested under `orders[]`): `order_id` (your own reference, optional), `base_info.service_type` (1 = standard), `sender_info.*` (state/city/district/street/post_code/name/phone/detail_address — district & street & post_code are mandatory for PH specifically), `fulfillment_info.*` (payment_role: 1 sender-pay/2 receiver-pay; cod_collection 0/1; cod_amount, mandatory & >0 if cod_collection=1, PH cap ₱50,000; collect_type: 1 pickup/2 drop-off; pickup_time + pickup_time_range_id, mandatory only if collect_type=pickup, sourced from **1.1 Get Pickup Timeslot**; optional voucher_code from **1.8 Get voucher list**), `deliver_info.*` (mirror of sender_info), `parcel_info.*` (weight kg, length/width/height cm, item_name, item_quantity, express_insured_value, parcel_item_type enum: Food and beverage / Clothing / Electronics / Medical / Groceries / General merchandise / Fragile; optional `item_list[]` for itemized detail).

PH phone format: 11 digits starting with `0`, or 12 digits starting with country code `63`. Use standard-address values from **5.1 Get Address file download link** (an Excel export) for state/city/district/post_code to minimize address-related order failures (error codes 10018/10019/10021/10025/10026/10027/10028 etc. all trace back to non-standard addresses).

Before create, optionally call **2.1 Check Shipping Fee** (`POST /open/api/v1/order/batch_check_order`, same body shape minus a few mandatory flags) to get an estimated fee without committing an order.

## 4. Cancel

**Endpoint:** `POST {{host}}/open/api/v1/order/batch_cancel_order`
**Body:** `{ user_id, user_secret, tracking_no_list: [string, ...] }` — note this is a JSON array of strings, **not** a single comma-joined string (a documented common mistake: `["a,b,c"]` is wrong, `["a","b","c"]` is right).
**Constraint:** only orders in "pending pickup/dropoff" status can be cancelled (error `13002 OrderUnableCancelCode` otherwise). Use `tracking_no` (SPX-assigned, returned from create), not your own `order_id`.
**Response:** `data.tracking_no_list` (succeeded) + `data.fail_list[]` (with `ret_code`/`message`/`tracking_no`).

## 5. Track

**Endpoint:** `POST {{host}}/open/api/v1/order/batch_search_order`
**Body:** `{ user_id, user_secret, tracking_no_list: [...] }`
**Returns:** full order snapshot per tracking number — `status` + `status_code` (see status table below), sender/deliver/parcel/fulfillment info, a `routes[]` history array (status/status_code/message/timestamp/driver_name/driver_phone_number), and `edd_info` (estimated delivery date min/max).

**Important timing caveat:** don't call Track immediately (<1-3s) after a successful Create — the order may not be fully propagated yet and can return `13251` "order id/tracking no cannot be found" or partial data. Build in a retry-with-backoff (1-3s+) rather than treating an immediate failure as authoritative.

**Order status codes** (also delivered via webhook, see §7):

| Code | Status |
|---|---|
| 1001 | Pending Pickup |
| 2001 | In Transit |
| 2006 | Delivering |
| 3001 | On Hold |
| 4001 | Delivered |
| 5001 | Pickup Failed |
| 5002 | Damaged |
| 5003 | Lost |
| 6001 | Returning |
| 6002 | Return Failed |
| 6003 | Returned |
| 7001 | Cancelled |

For **actual** (post-creation, as opposed to estimated) shipping fees, use **2.2 Get Order Fee** (`POST /open/api/v1/order/batch_get_asf`) with the same tracking-number-list pattern.

## 6. Print waybill (AWB)

Same two-step async batch pattern as create:

1. `POST {{host}}/open/api/v2/order/batch_get_shipping_label` with `{ user_id, user_secret, batch_no }` (the same `batch_no` from order creation) → returns task status.
2. Once `task_status = 4 (Done)`, response includes `data.awb_link` — a PDF URL.

**Constraints:** cancelled orders cannot be printed; the returned link is **valid for only 30 minutes** and **openable at most 10 times** — download/cache it server-side immediately rather than exposing the raw SPX link directly to admin users on demand, or you'll hit expiry on a re-visit. Max 30 orders per AWB batch request per `guide.md`'s API list description (though `api.md`'s own 1.6 page doesn't restate that cap — treat 30 as the documented limit from the API-list summary).

## 7. Webhook / callback mechanics

Two separate webhooks, each with its own registered URL (bound to your `app-id` at setup time by SPX staff — not self-service, no dynamic registration endpoint documented):

**4.1 Tracking Web Hook** — fired on every order status change. POSTs a JSON body containing `status`, `status_code`, `message`, `tracking_no`, `tracking_link`, `order_id`, `order_id_link`, `id` (unique per request), `timestamp`, plus conditionally `actual_shipping_fee` / `latest_chargeable_weight` / `cod_amount` / `actual_return_shipping_fee` / driver info / `edd_max`/`edd_min` depending on status. Use this to drive Venta's own order status transitions (map SPX `status_code` → Venta order status via `StockService::adjustForStatusChange()`-style handling, mirroring the existing courier tracking pattern).

**4.2 Evidence Proofs Webhook** — fired when delivery evidence (signatures, pickup/delivery/return/other-handling proof photos) is generated or updated. Payload includes `tracking_no`, `customer_order_id`, `epod_list`/`epop_list`/`epor_list`/`epooh_list` (arrays of proof-image URLs), `status`, `tracking_code_name`. (Documentation for this one is incomplete — no JSON example, last field's description is missing in the source.)

**Both webhooks:**
- **Auth:** SPX signs the POST body with your `app-secret` using the *same* HMAC-SHA256 algorithm as outbound API calls (§2), placed in the `check-sign` request header. Your webhook receiver must independently recompute the signature and compare — this is the *inverse* direction of the same signing code you write for outbound calls, so the signer/verifier can share one implementation.
- **Response contract:** you must return HTTP `200` within **3 seconds**, before doing slow processing (queue the actual handling via Laravel queues per project convention, ack immediately). **If SPX sees repeated rejections or timeouts, it stops sending webhooks entirely** — silent data loss risk, so this is not just a performance nicety, it's a hard requirement. Route should be excluded from Laravel's default CSRF middleware (it's a server-to-server callback) but must still verify the SPX signature as its actual auth mechanism.

## 8. Error handling notes for the adapter

- Response envelope is always `{ ret_code, message, data }`; `ret_code: 0` = success. Batch endpoints additionally return a `fail_list[]` alongside successes — a `200` HTTP response does not mean every item in the batch succeeded.
- Full error code table is in `guide.md` → "Error codes mapping" (internal 99xxx, auth 1xxx, param 11xxx, account 12xxx, order 13xxx, business-rule 10xxx ~86 distinct codes) — worth mapping the order-creation-relevant 10xxx codes (weight/dimension/address/COD/pickup-slot validation) to user-facing admin messages rather than surfacing raw SPX text.
- `guide.md` → "Suggestions of common errors" ranks the top ~10 real-world failure codes (mostly address standardization and pickup-slot staleness) — worth pre-validating client-side before hitting the API (e.g. re-fetch pickup slots right before create, per SPX's own recommendation, rather than reusing a slot fetched earlier in an admin session).
- Parcel limits for PH: weight < 50kg, length/width/height max 150cm each, COD max ₱50,000.

## 9. Endpoints not yet covered above (lower priority for a first adapter cut)

- **1.7 Update Order** (`/open/api/v1/order/batch_update_order`) — edit an existing order pre-pickup, max 3 edits/order, AWB must be reprinted after; payment_role/insurance_collection/express_insured_value/collect_type are immutable.
- **1.8 Get voucher list**, **2.1 Check Shipping Fee**, **3.2 Check Account Credentials**, **5.1 Get Address file download link** — supporting/auxiliary endpoints, see `api.md` for full detail if the plan needs them.

## 10. Open items to verify directly with SPX before/during implementation

1. The `guide.md` PH address-file-to-API-field mapping table looks wrong (repeats `sender_city`/`deliver_city` for the postcode column) — confirm the correct field name is `sender_post_code`/`deliver_post_code` (as used elsewhere in `api.md`) before building the address-import mapping.
2. Whether webhook registration truly requires manual SPX staff involvement (no self-service endpoint was found in the harvested docs) — affects onboarding/runbook, not code.
3. AWB batch size limit: `guide.md` API list says max 30; `api.md` 1.6 doesn't restate a cap — confirm actual enforced limit.
