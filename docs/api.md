# Ventaship client API, v1

Ventaship is a courier gateway. A client app (a shop, a storefront, an ERP) sends it a shipment; Ventaship books the parcel with a courier (QuadX, SPX or LBC), keeps the waybill, follows the parcel through the courier's webhooks and status polls, and tells the client app about every change through signed webhooks.

One Ventaship install serves many client apps. There are no workspaces or tenants. Each client app has its own API tokens, its own webhook URL and secret, and sees only its own shipments. Courier accounts belong to the install: every client app can book with every courier account.

This document is the whole contract. Everything here is checked against the code by the test suite: every JSON example in this file is a file in `tests/Fixtures/api/`, and a test checks that each one has the shape the server sends today.

Contents:

1. [Basics](#1-basics)
2. [Authentication](#2-authentication)
3. [Idempotency](#3-idempotency)
4. [Errors](#4-errors)
5. [Rate limits](#5-rate-limits)
6. [Endpoints](#6-endpoints)
7. [The Shipment resource](#7-the-shipment-resource)
8. [States, phases and payment states](#8-states-phases-and-payment-states)
9. [Booking](#9-booking)
10. [Cancel rules](#10-cancel-rules)
11. [Outgoing webhooks](#11-outgoing-webhooks)
12. [Quickstart](#12-quickstart)

---

## 1. Basics

### Base URL and versioning

```
https://{ventaship-host}/api/v1
```

On the development machine the host is `http://localhost:8040`, so the base URL is `http://localhost:8040/api/v1`.

The version is in the path. Within v1, fields, event types and error codes may be **added** without notice. Your client must ignore response fields, webhook event types and error codes it does not know. Nothing is renamed or removed within v1.

### Format

- Requests and responses are JSON (`Content-Type: application/json`). The only non-JSON answers are the label downloads, which are PDF (or ZIP).
- API responses carry an `X-Request-Id` header (`req_…`). Quote it when you report a problem.
- Successful responses wrap their payload in `data`, except `GET /ping`.

### Identifiers

Public ids are lowercase, prefixed ULIDs: a prefix, an underscore and 26 characters of `0-9a-z`.

| Prefix | What |
|---|---|
| `app_` | a client app |
| `acc_` | a courier account |
| `shp_` | a shipment |
| `evt_` | an outgoing webhook event |

`client_reference` is your own id for a shipment (for example your order number). See [9.2](#92-client_reference).

### Money

- In **responses**, money is a string with exactly two decimals, as stored: `"1290.00"`. Never parse it as a float if you can avoid it.
- In **requests**, send money as a JSON number: `1290` or `1290.5` or `1290.00`. At most two decimals. A numeric string such as `"1290.00"` is also accepted. Thousands separators are not: `"1,290.00"` is a 422.
- The largest amount is `9999999999.99`. The currency is always PHP.

### Phone numbers

Only Philippine **mobile** numbers are accepted, for both the pickup contact and the recipient: 10 digits after +63, starting with 9. Everything but digits is ignored (spaces, dashes and parentheses are fine), then the number must be one of:

- 11 digits starting with `0` (`09171234567`)
- 12 digits starting with `63` (`+63 917 123 4567`, `639171234567`)
- 10 digits starting with `9` (`9171234567`)

Anything else (a landline, a short or long number, a number not starting with 9) is a 422 with `Enter a valid PH mobile number (e.g. 09171234567).` on the field. Ventaship stores and returns the number as `09XXXXXXXXX`.

### Timestamps

Every timestamp in a response or webhook is ISO-8601 in Manila time with its offset: `2026-11-08T13:05:00+08:00`. Dates without a time (`pickup_date`) are `YYYY-MM-DD`, Manila calendar. Parse the offset: convert to UTC (or your own timezone) before storing, never store the wall-clock part alone.

### Addresses

Addresses are Philippine only (`country` is `PH` or omitted). The fields are the same everywhere an address appears:

| Field | Rule |
|---|---|
| `address_1` | required, string, ≤ 255 |
| `address_2` | optional, string, ≤ 255 |
| `barangay` | optional, string, ≤ 255 (strongly recommended: couriers route by it) |
| `district` | optional, string, ≤ 255 |
| `city` | required, string, ≤ 255 |
| `state` | required, string, ≤ 255 (the province or region, e.g. `Metro Manila`) |
| `postcode` | optional, string, ≤ 16 |
| `country` | optional, `PH` only; stored as `PH` |
| `codes` | optional object: `state`, `city`, `barangay`, each a string ≤ 32 (PSGC codes, when you have them) |

Keys that are not in this table are dropped.

---

## 2. Authentication

Every API request carries an API key (a Sanctum token) in the `Authorization` header. Nothing else is needed: no signature, no timestamp.

```
Authorization: Bearer 12|vsk_…
```

- The Ventaship admin issues keys, on the platform's page in the dashboard or with `ventaship:key-issue`. A client app may hold several keys. Send the token exactly as given, including the number and `|` in front.
- Older tokens look like `vsk_…`, without a number in front. They keep working; send them as they are.
- A missing, unknown, revoked or expired token is answered `401` with code `unauthorized`. The reason is not disclosed.
- A valid token of a disabled client app is answered `403 app_disabled`.
- Use HTTPS outside your own network: the token is a password.

### PHP

```php
<?php

final class VentashipClient
{
    public function __construct(
        private string $baseUrl,   // e.g. "http://localhost:8040" (no trailing slash, no /api/v1)
        private string $token,     // "12|vsk_…"
    ) {}

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public function request(string $method, string $target, ?array $json = null, array $headers = []): array
    {
        $method = strtoupper($method);
        $body = $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $headers += [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ];
        if ($body !== '') {
            $headers['Content-Type'] = 'application/json';
        }

        $ch = curl_init($this->baseUrl.$target);
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body === '' ? null : $body,
            CURLOPT_HTTPHEADER => array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($headers), $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }

                return strlen($line);
            },
        ]);
        $responseBody = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
    }
}

// $vs = new VentashipClient('http://localhost:8040', '12|vsk_…');
// $vs->request('GET', '/api/v1/ping');
// $vs->request('POST', '/api/v1/shipments', $payload, ['Idempotency-Key' => 'order-4211-create']);
```

### Node (18+)

```js
export async function ventaship(baseUrl, token, method, target, json = null, headers = {}) {
  return fetch(baseUrl + target, {
    method: method.toUpperCase(),
    body: json === null ? undefined : JSON.stringify(json),
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json',
      ...(json === null ? {} : { 'Content-Type': 'application/json' }),
      ...headers,
    },
  });
}

// await ventaship('http://localhost:8040', '12|vsk_…', 'GET', '/api/v1/ping');
```

### curl

```bash
curl -H "Authorization: Bearer $VENTASHIP_TOKEN" -H "Accept: application/json" http://localhost:8040/api/v1/ping
```

---

## 3. Idempotency

Three endpoints change state and **require** an `Idempotency-Key` header:

- `POST /shipments`
- `POST /shipments/{id}/submit`
- `POST /shipments/{id}/cancel`

The key is any string of 1 to 100 characters that you choose, unique per intended operation (for example `order-4211-create`). Without it the request is a `422 invalid_request` with an `errors["Idempotency-Key"]` message. Other endpoints ignore the header.

How it works:

- Keys are scoped to your client app. Two apps can use the same key without meeting.
- The first request with a key is processed and its answer (status and body) is stored.
- A repeat with the same key and the **same request** (same method, same path and query, same body bytes) gets the stored answer again, with the header `Idempotent-Replayed: true`. Nothing is done twice.
- The same key with a **different** request is `422 idempotency_key_reused`.
- The query string is part of the request: reusing a key with a different `?wait` (or `?wait` added or dropped) is `422 idempotency_key_reused`. Retry with exactly the URL you sent first.
- While the first request is still running, a repeat is `409 idempotency_in_progress` (`retryable: true`): wait a moment and retry.
- Answers that say "try again" are **not** stored: any 5xx, and any error with `retryable: true` (429, 409 `idempotency_in_progress`, retryable 502). Retrying with the same key then really runs the request again.
- Every other answer **is** stored, including 4xx errors. A 422 for a bad body is kept under that key: after you fix the body, send it under a **new** key.
- Keys are kept for 7 days.

Use the same key when you retry because of a timeout or a dropped connection. That is what makes a retry safe.

---

## 4. Errors

Every error from `/api/v1` has this body and nothing else (no HTML, no stack traces):

```json
{
    "error": "Human-readable summary",
    "code": "machine_readable_code",
    "retryable": false,
    "errors": {}
}
```

- `code` is what your code should branch on. `error` is for logs and people.
- `retryable: true` means the same request may succeed later unchanged (respect `Retry-After` when present).
- `errors` is always an object. For validation errors it maps each field (dot notation, e.g. `payment.cod_amount`, `items.0.name`) to a list of messages. Some codes put other details there, as listed below.

Validation error example (`create-shipment-invalid.request` sent to `POST /shipments`):

<!-- fixture: create-shipment-invalid.request -->
```json
{
    "courier": "quadx",
    "client_reference": "4212",
    "payment": {"method": "cod", "cod_amount": "1,290.00"},
    "pickup": {"contact_name": "Venta Warehouse", "contact_phone": "09181234567", "address_1": "5 Shaw Blvd", "city": "Mandaluyong", "state": "Metro Manila"},
    "recipient": {"first_name": "Juan", "phone": "028123456", "address_1": "1 Rizal St", "city": "Makati", "state": "Metro Manila"},
    "items": [{"name": "Blue Tee", "quantity": 1}]
}
```

<!-- fixture: error-invalid-request.response -->
```json
{
    "error": "The request is invalid.",
    "code": "invalid_request",
    "retryable": false,
    "errors": {
        "payment.cod_amount": [
            "The payment.cod amount field must be a number.",
            "The payment.cod amount field must have 0-2 decimal places.",
            "The payment.cod amount field must be greater than 0."
        ],
        "recipient.phone": [
            "Enter a valid PH mobile number (e.g. 09171234567)."
        ]
    }
}
```

Unauthorized:

<!-- fixture: error-unauthorized.response -->
```json
{
    "error": "Unauthorized",
    "code": "unauthorized",
    "retryable": false,
    "errors": {}
}
```

Every code the API can answer:

| HTTP | `code` | `retryable` | When | `errors` |
|---|---|---|---|---|
| 401 | `unauthorized` | false | Missing, unknown, revoked or expired API key | `{}` |
| 403 | `app_disabled` | false | The client app is disabled by the admin | `{}` |
| 404 | `not_found` | false | Unknown route, or a shipment or courier account that does not exist. Another app's shipment is also a 404 | `{}` |
| 405 | `method_not_allowed` | false | Wrong HTTP method for the route (see the `Allow` header) | `{}` |
| 409 | `active_shipment_exists` | false | A live shipment of yours already holds this `client_reference` | `{"shipment": "shp_…"}` (the holder's id, a string) |
| 409 | `not_draft` | false | `POST /shipments/{id}/submit` on a shipment that is not a draft | `{"state": ["booked"]}` (its current state) |
| 409 | `label_not_ready` | false | No waybill yet: the shipment is not `booked`, or has no tracking number. For `POST /labels`: none of the ids is printable | `{}` |
| 409 | `not_trackable` | false | `POST /shipments/{id}/refresh` on a shipment with no booking to track | `{}` |
| 409 | `idempotency_in_progress` | **true** | The first request with this `Idempotency-Key` is still running | `{}` |
| 422 | `invalid_request` | false | The body or query is invalid; `Idempotency-Key` missing or too long; `account_id` and `courier` disagree | field → messages |
| 422 | `pickup_slot_unavailable` | false | `pickup_slot` is refused (not a string ≤ 255, or for SPX not a `value` from `POST /pickup-slots`), and it is the only field in error. Ask `POST /pickup-slots` again and send one of its values. With other errors too, the code is `invalid_request` | `{"pickup_slot": [...]}` |
| 422 | `idempotency_key_reused` | false | This `Idempotency-Key` was used for a different request | `{}` |
| 422 | `account_not_found` | false | No courier account with this `account_id`, or no default account for this `courier` | `{"account_id": [...]}` or `{"courier": [...]}` |
| 422 | `account_misconfigured` | false | The courier account cannot be used: disabled, its courier not installed, or settings missing (e.g. no API keys). On `POST /shipments/{id}/submit`, also a draft whose courier account no longer exists | usually `{"account": ["…", …]}`; `{}` for a draft without an account |
| 429 | `rate_limited` | **true** | Too many requests (see [5](#5-rate-limits)); `Retry-After` header says how many seconds to wait | `{}` |
| 502 | `courier_unavailable` | varies | The courier could not be reached or refused (labels, refresh). `retryable` says whether trying again can help | `{}` |
| 500 | `server_error` | **true** | Unexpected server error | `{}` |
| any other 4xx/5xx | `http_{status}`, e.g. `http_503` | true when ≥ 500 | Any other HTTP-level refusal, e.g. `http_503` while the server is in maintenance mode | `{}` |

Booking outcomes are **not** API errors: `POST /shipments` answers 202 and the courier's verdict arrives later in the shipment's `state` and `error` (see [9](#9-booking)).

---

## 5. Rate limits

Limits are per API key, per minute. Each response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`; a `429 rate_limited` carries `Retry-After` (seconds).

| Bucket | Limit | Applies to |
|---|---|---|
| all requests | 300 / min | every endpoint |
| courier lookups | 60 / min, shared | `POST /coverage`, `POST /quotes`, `POST /pickup-slots` |
| creates | 120 / min, shared | `POST /shipments`, `POST /shipments/{id}/submit` |
| label batches | 10 / min | `POST /labels` |
| refresh | 1 / min **per shipment** | `POST /shipments/{id}/refresh` |

A request counts against every bucket that applies to it.

---

## 6. Endpoints

All paths are relative to `/api/v1`.

| Method and path | What |
|---|---|
| [`GET /ping`](#get-ping) | check your credentials |
| [`GET /couriers`](#get-couriers) | installed couriers and their options |
| [`GET /courier-accounts`](#get-courier-accounts) | courier accounts you can book with |
| [`GET /courier-accounts/{id}`](#get-courier-accountsid) | one courier account |
| [`POST /coverage`](#post-coverage) | will an account deliver from here to there |
| [`POST /quotes`](#post-quotes) | the courier's fee for one parcel, from one account or every account at once |
| [`POST /pickup-slots`](#post-pickup-slots) | when an account can collect |
| [`POST /shipments`](#post-shipments) | create (and book) a shipment |
| [`GET /shipments`](#get-shipments) | list and reconcile |
| [`GET /shipments/{id}`](#get-shipmentsid) | one shipment |
| [`GET /shipments/{id}/events`](#get-shipmentsidevents) | its timeline |
| [`POST /shipments/{id}/refresh`](#post-shipmentsidrefresh) | ask the courier now |
| [`POST /shipments/{id}/submit`](#post-shipmentsidsubmit) | book a draft |
| [`POST /shipments/{id}/cancel`](#post-shipmentsidcancel) | cancel |
| [`GET /shipments/{id}/label`](#get-shipmentsidlabel) | the waybill PDF |
| [`POST /labels`](#post-labels) | many waybills in one file |

### Choosing a courier account

`POST /coverage`, `/quotes`, `/pickup-slots` and `/shipments` each name the account to use, one of two ways:

- `account_id`: a specific courier account (`acc_…`, from `GET /courier-accounts`), or
- `courier`: a courier key (`quadx`, `spx`, `lbc`), meaning that courier's **default** account.

At least one is required. If you send both, the account must belong to that courier (otherwise `422 invalid_request`). An unknown account or a courier without a default account is `422 account_not_found`. A disabled account is `422 account_misconfigured`.

---

### `GET /ping`

Checks your token. No side effects.

<!-- fixture: ping.response -->
```json
{
    "app": {
        "id": "app_01m6ws8szzhc0b70n4zn6wj46g",
        "name": "VentaCart Pro"
    }
}
```

---

### `GET /couriers`

Every installed courier, what it can do, and the option lists you choose `service`, `parcel.type` and `payment.shipping_payment` from. `value` is what you send; `label` is for people.

| Field | Meaning |
|---|---|
| `key` | the courier key used in `courier` |
| `name` | display name |
| `methods` | handover methods it supports (`pickup` today) |
| `cancel` | whether Ventaship can cancel at this courier (LBC: `false`, see [10](#10-cancel-rules)) |
| `pickup_slots` | whether `POST /pickup-slots` returns anything for it |
| `quotes`, `coverage` | whether `POST /quotes` and `POST /coverage` ask the courier |
| `webhooks` / `poll_only` | whether the courier pushes status changes, or Ventaship polls it (LBC is polled every 10 minutes) |
| `label_formats` | the courier's own label formats (informational; the API always serves PDF) |
| `service_types`, `parcel_options`, `shipping_payment_options` | `[{value, label}]` lists |

<!-- fixture: couriers.response -->
```json
{
    "data": [
        {
            "key": "lbc",
            "name": "LBC",
            "methods": [
                "pickup"
            ],
            "cancel": false,
            "pickup_slots": true,
            "quotes": true,
            "coverage": true,
            "webhooks": false,
            "poll_only": true,
            "label_formats": [
                "pdf"
            ],
            "service_types": [
                {
                    "value": "REGULAR",
                    "label": "Rider pickup"
                }
            ],
            "parcel_options": [
                {
                    "value": "16",
                    "label": "Own box"
                },
                {
                    "value": "165",
                    "label": "Kilobox Mini"
                },
                {
                    "value": "6",
                    "label": "Kilobox Small"
                },
                {
                    "value": "164",
                    "label": "Kilobox Slim"
                },
                {
                    "value": "63",
                    "label": "Kilobox Medium"
                },
                {
                    "value": "7",
                    "label": "Kilobox Large"
                },
                {
                    "value": "64",
                    "label": "Kilobox XL"
                },
                {
                    "value": "45",
                    "label": "N-Pack Small"
                },
                {
                    "value": "8",
                    "label": "N-Pack Large"
                },
                {
                    "value": "62",
                    "label": "N-Pouch SS"
                },
                {
                    "value": "3",
                    "label": "N-Pouch Regular"
                },
                {
                    "value": "61",
                    "label": "N-Pouch XL"
                },
                {
                    "value": "9",
                    "label": "X-Pouch"
                },
                {
                    "value": "2",
                    "label": "Plastic"
                },
                {
                    "value": "4",
                    "label": "Crate"
                },
                {
                    "value": "79",
                    "label": "Piece"
                },
                {
                    "value": "80",
                    "label": "Sack"
                }
            ],
            "shipping_payment_options": [
                {
                    "value": "account",
                    "label": "Charged to my LBC account"
                }
            ]
        },
        {
            "key": "quadx",
            "name": "QuadX",
            "methods": [
                "pickup"
            ],
            "cancel": true,
            "pickup_slots": false,
            "quotes": true,
            "coverage": true,
            "webhooks": true,
            "poll_only": false,
            "label_formats": [
                "zebra",
                "fuji"
            ],
            "service_types": [
                {
                    "value": "same_day_pickup",
                    "label": "Same Day Pickup"
                },
                {
                    "value": "next_day",
                    "label": "Next Day Pickup"
                }
            ],
            "parcel_options": [
                {
                    "value": "small-pouch",
                    "label": "White Small Pouch"
                },
                {
                    "value": "medium-pouch",
                    "label": "Blue Medium Pouch"
                },
                {
                    "value": "big-pouch",
                    "label": "Red Large Pouch"
                },
                {
                    "value": "box",
                    "label": "Box"
                },
                {
                    "value": "oversized",
                    "label": "Oversized"
                }
            ],
            "shipping_payment_options": [
                {
                    "value": "cash",
                    "label": "I'll shoulder it: Cash on pickup"
                },
                {
                    "value": "gcash",
                    "label": "I'll shoulder it: GCash at pickup"
                },
                {
                    "value": "net_off",
                    "label": "I'll shoulder it: Deduct from my remittance / bill me"
                }
            ]
        },
        {
            "key": "spx",
            "name": "SPX",
            "methods": [
                "pickup",
                "dropoff"
            ],
            "cancel": true,
            "pickup_slots": true,
            "quotes": true,
            "coverage": true,
            "webhooks": true,
            "poll_only": false,
            "label_formats": [
                "pdf"
            ],
            "service_types": [
                {
                    "value": "standard",
                    "label": "Standard Service"
                }
            ],
            "parcel_options": [
                {
                    "value": "small",
                    "label": "Small (25×20×10cm, up to 3kg)"
                },
                {
                    "value": "medium",
                    "label": "Medium (25×35×15cm, up to 5kg)"
                },
                {
                    "value": "large",
                    "label": "Large (25×45×20cm, up to 10kg)"
                },
                {
                    "value": "custom",
                    "label": "Custom dimensions…"
                }
            ],
            "shipping_payment_options": [
                {
                    "value": "sender_pay",
                    "label": "I'll shoulder it: Sender pays the shipping fee"
                },
                {
                    "value": "receiver_pay",
                    "label": "Customer pays the shipping fee (Receiver pay)"
                }
            ]
        }
    ]
}
```

---

### `GET /courier-accounts`

The courier accounts of this install. Every client app sees all of them. Ordered by courier, default first.

| Field | Meaning |
|---|---|
| `id` | `acc_…` |
| `courier` | courier key |
| `label` | the admin's name for it |
| `mode` | `sandbox` or `production`. `sandbox` means the courier's test system: QuadX staging, SPX UAT. LBC has no test system: in either mode an LBC booking is a real parcel |
| `status` | `active` or `disabled` |
| `is_default` | the account used when you send only `courier` |
| `issues` | what is missing before it can book (empty when ready) |
| `capabilities` | as in `GET /couriers` (without the option lists) |

<!-- fixture: courier-accounts.response -->
```json
{
    "data": [
        {
            "id": "acc_01m6ws8t06hsthfpfmv692k221",
            "courier": "quadx",
            "label": "QuadX main",
            "mode": "sandbox",
            "status": "active",
            "is_default": true,
            "issues": [],
            "capabilities": {
                "methods": [
                    "pickup"
                ],
                "cancel": true,
                "pickup_slots": false,
                "quotes": true,
                "coverage": true,
                "webhooks": true,
                "poll_only": false,
                "label_formats": [
                    "zebra",
                    "fuji"
                ]
            }
        }
    ]
}
```

### `GET /courier-accounts/{id}`

One account, same fields. Unknown id: `404 not_found`.

<!-- fixture: courier-account.response -->
```json
{
    "data": {
        "id": "acc_01m6ws8t06hsthfpfmv692k221",
        "courier": "quadx",
        "label": "QuadX main",
        "mode": "sandbox",
        "status": "active",
        "is_default": true,
        "issues": [],
        "capabilities": {
            "methods": [
                "pickup"
            ],
            "cancel": true,
            "pickup_slots": false,
            "quotes": true,
            "coverage": true,
            "webhooks": true,
            "poll_only": false,
            "label_formats": [
                "zebra",
                "fuji"
            ]
        }
    }
}
```

---

### `POST /coverage`

Asks whether an account will carry parcels from one pickup point to up to **10** destinations. Each destination is checked with the courier (5-second budget per destination; definite answers are cached for a day).

Request:

| Field | Rule |
|---|---|
| `account_id` / `courier` | see [Choosing a courier account](#choosing-a-courier-account) |
| `pickup` | required, an [address](#addresses) |
| `destinations` | required list, 1 to 10 |
| `destinations[].ref` | required string ≤ 64, unique in the request; echoed back |
| `destinations[].recipient` | required, an [address](#addresses) |
| `pickup.contact_name`, `pickup.contact_phone` | optional, as in [`POST /shipments`](#post-shipments). **SPX** checks only with the sender's phone (without it: `unknown`, `missing_pickup_phone`) |
| `destinations[].recipient.first_name`, `.last_name`, `.phone` | optional, as in `POST /shipments`. **SPX** checks only with the customer's phone (without it: `unknown`, `missing_phone`) |
| `parcel` | optional, the parcel to check: `type`, `weight_kg`, `length_cm`, `width_cm`, `height_cm`, each optional, as in `POST /shipments`. When sent, the courier checks this parcel (SPX: its size and weight); when left out, each courier checks its own default parcel, as before |
| `payment.method` | optional, `cod` or `prepaid` (default `prepaid`); some couriers do not offer COD everywhere |

<!-- fixture: coverage.request -->
```json
{
    "courier": "quadx",
    "pickup": {
        "address_1": "5 Shaw Blvd",
        "barangay": "Wack-Wack",
        "city": "Mandaluyong",
        "state": "Metro Manila",
        "postcode": "1550"
    },
    "destinations": [
        {
            "ref": "cart-1",
            "recipient": {
                "address_1": "1 Rizal St",
                "barangay": "Poblacion",
                "city": "Makati",
                "state": "Metro Manila",
                "postcode": "1210"
            }
        }
    ],
    "payment": {"method": "cod"}
}
```

Response, one entry per destination, in request order:

| Field | Meaning |
|---|---|
| `ref` | your `ref` |
| `verdict` | `serviceable`, `not_serviceable`, or `unknown` (the courier could not be asked or gave no clear answer: it is **not** a refusal) |
| `kind` | when recognised, which kind of problem: `destination`, `origin`, `cod`, `postcode`, `parcel` (these come with `not_serviceable`), or `slot`, `their_side`, `unreachable` (these come with `unknown`); otherwise `null` |
| `code` | why, when Ventaship itself knows without asking the courier, else `null`. With `unknown`: `missing_province`, `missing_city`, `missing_barangay`, `missing_postcode`, `missing_phone` (the customer's address) or the same with `pickup_` (`missing_pickup_postcode`, ...) for the sender; add the field and ask again. With `not_serviceable` (SPX): `spx_unlisted_province`, `spx_unlisted_city`, `spx_unlisted_barangay` (SPX's location list does not have the customer's place; `spx_unlisted_pickup_*` for the sender), `spx_no_delivery`, `spx_no_cod`, `spx_no_pickup`, `spx_no_cod_pickup`, and, when SPX itself answers, also `spx_no_route` (SPX has no route to the address), `spx_invalid_postcode`, `spx_invalid_pickup_postcode`. With `unknown` (SPX's answer, still bookable): `spx_too_far`, `spx_too_near` (SPX's distance limit between pickup and delivery), `spx_unmapped_address`, `spx_unmapped_pickup` (SPX could not place the address on its map; check the street and barangay). With `not_serviceable` and `kind` `parcel` (SPX, for the `parcel` you sent): `parcel_too_long`, `parcel_too_wide`, `parcel_too_tall`, `parcel_too_heavy`. A missing postcode is first filled from the PSGC city, so `missing_postcode` only appears when the city cannot be matched |
| `reason` | the courier's own words when it said something (always shown to people when `kind` is `null`; may also accompany a recognised `kind`, e.g. `destination`); for a `parcel_too_*` code, a plain sentence with the courier's limit, e.g. "Too heavy for SPX. Weight is 12 kg. SPX takes up to 10 kg."; otherwise `null` |
| `odz` | `true` when the courier says the destination is outside its normal delivery zone (a remote-area surcharge applies), `false` when it says it is not, `null` when it gave no signal. Information only: it never changes `verdict`. SPX: `true` when its answer carries a `remote_area_fee` above 0 (SPX does not document that field, so most answers are `null`). LBC: its serviceability answer (`false` for a serviced barangay, `true` for one it does not serve). QuadX: always `null` |

<!-- fixture: coverage.response -->
```json
{
    "data": [
        {
            "ref": "cart-1",
            "verdict": "serviceable",
            "kind": null,
            "code": null,
            "reason": null,
            "odz": null
        }
    ]
}
```

A destination SPX does not list, for example barangay Agtangao of Bangued, Abra:

```json
{"ref": "cart-2", "verdict": "not_serviceable", "kind": "destination", "code": "spx_unlisted_barangay", "reason": null, "odz": null}
```

A parcel SPX will not take, asked with `"parcel": {"weight_kg": 12, "length_cm": 20, "width_cm": 15, "height_cm": 10}`:

```json
{"ref": "cart-3", "verdict": "not_serviceable", "kind": "parcel", "code": "parcel_too_heavy", "reason": "Too heavy for SPX. Weight is 12 kg. SPX takes up to 10 kg.", "odz": null}
```

---

### `POST /quotes`

The fee the courier itself quotes for one parcel. Ventaship never estimates a fee of its own: when the courier gives no figure (or cannot be reached), `data` is `null`.

Request: `account_id` / `courier`, `pickup` (address, with optional `contact_name` and `contact_phone`), `recipient` (address, with optional `first_name`, `last_name` and `phone`), `service` (optional), `insure` (optional, as in [`POST /shipments`](#post-shipments)), `parcel` (optional, as in `POST /shipments`), `payment` (required, as in `POST /shipments`). **SPX** quotes only with both phones.

<!-- fixture: quotes.request -->
```json
{
    "courier": "quadx",
    "service": "next_day",
    "insure": false,
    "pickup": {
        "address_1": "5 Shaw Blvd",
        "barangay": "Wack-Wack",
        "city": "Mandaluyong",
        "state": "Metro Manila",
        "postcode": "1550"
    },
    "recipient": {
        "address_1": "1 Rizal St",
        "barangay": "Poblacion",
        "city": "Makati",
        "state": "Metro Manila",
        "postcode": "1210"
    },
    "parcel": {"type": "box", "weight_kg": 1.2},
    "payment": {"method": "cod", "cod_amount": 1290.00, "declared_value": 1290.00}
}
```

<!-- fixture: quotes.response -->
```json
{
    "data": {
        "amount": "145.00",
        "currency": "PHP",
        "label": "₱145.00",
        "odz": null,
        "remote_area_fee": null
    }
}
```

When the courier has no figure because it refuses the shipment (today: SPX), `data` stays `null` and `refusal` says why: `code` is one of the [`POST /coverage`](#post-coverage) codes (`parcel_too_long`, `parcel_too_wide`, `parcel_too_tall`, `parcel_too_heavy`, `spx_no_delivery`, ...) or `courier_refused`, and `reason` is a plain sentence. Without a refusal the answer is `{"data": null}` as before.

```json
{
    "data": null,
    "refusal": {
        "code": "parcel_too_long",
        "reason": "Too long for SPX. Length is 35 cm. SPX takes up to 25 cm."
    }
}
```

`amount` is a 2-decimal string, `currency` is `PHP`, `label` is a display string or `null`. `odz` is as in [`POST /coverage`](#post-coverage) (`true`, `false` or `null`). `remote_area_fee` is the remote-area surcharge the courier itemised, a 2-decimal string (`"0.00"` when it said there is none), or `null` when it did not say; only SPX can give one today. Neither field ever blocks a booking.

#### Every courier at once

Send `"couriers": "all"` instead of `account_id` or `courier`, and every active courier account is asked at the same time. The answer comes back within `budget_ms` (optional, 1000 to 8000, default 2500), counted from when the request arrives: a courier that has not answered by then is reported as `timeout` and does not hold the answer. It counts as one request against the courier lookups limit. The other fields are as above; `service` applies only to a courier that has services (QuadX), and each other courier uses its own default.

```json
{
    "couriers": "all",
    "budget_ms": 2500,
    "pickup": {"contact_name": "Juan Shop", "contact_phone": "09181234567", "address_1": "5 Shaw Blvd", "barangay": "Wack-Wack", "city": "Mandaluyong", "state": "Metro Manila", "postcode": "1550"},
    "recipient": {"first_name": "Jane", "last_name": "Doe", "phone": "09171234567", "address_1": "1 Rizal St", "barangay": "Poblacion", "city": "Makati", "state": "Metro Manila", "postcode": "1210"},
    "parcel": {"type": "small", "weight_kg": 1, "length_cm": 35, "width_cm": 20, "height_cm": 10},
    "payment": {"method": "prepaid"}
}
```

`data` is a list, one entry per account, ordered by courier then account. Each entry has `courier`, `account_id`, `service` (the service asked for, or `null` for a courier without services), `amount`, `currency`, `label`, `odz` and `remote_area_fee` (as above), `serviceable` (`true` with a fee, `false` when the courier refuses, `null` when it is not known), `code` and `reason`. Without a fee, `amount` is `null` and `code` says why: a refusal code as in [`POST /coverage`](#post-coverage) (or `courier_refused`) with a plain `reason`; `timeout` when the courier did not answer within the budget; `no_fee` when it answered without a figure. Ventaship never makes up a fee.

```json
{
    "data": [
        {"courier": "lbc", "account_id": "acc_01jd8l0b2v3m4n5p6q7r8s9t0u", "service": null, "amount": null, "currency": null, "label": null, "odz": null, "remote_area_fee": null, "serviceable": null, "code": "timeout", "reason": null},
        {"courier": "quadx", "account_id": "acc_01jd8l0b2v3m4n5p6q7r8s9t0v", "service": "next_day", "amount": "145.00", "currency": "PHP", "label": "₱145.00", "odz": null, "remote_area_fee": null, "serviceable": true, "code": null, "reason": null},
        {"courier": "spx", "account_id": "acc_01jd8l0b2v3m4n5p6q7r8s9t0w", "service": null, "amount": null, "currency": null, "label": null, "odz": null, "remote_area_fee": null, "serviceable": false, "code": "parcel_too_long", "reason": "Too long for SPX. Length is 35 cm. SPX takes up to 25 cm."}
    ]
}
```

---

### `POST /pickup-slots`

When an account can collect from a pickup point. Pass a slot's `value` back unchanged as `pickup_slot` when you create the shipment. SPX answers every pickup window it offers, earliest first (its "Now" first within a day); its `label` reads like `Tue, Nov 10 · 2:00pm-4:00pm`. `date` is the slot's calendar day (`YYYY-MM-DD`) when known, else `null`. Couriers without slots (QuadX) answer an empty list; so does a courier that cannot be reached.

Request: `account_id` / `courier`, `pickup` (address), `parcel` (optional).

<!-- fixture: pickup-slots.request -->
```json
{
    "courier": "lbc",
    "pickup": {
        "address_1": "5 Shaw Blvd",
        "barangay": "Wack-Wack",
        "city": "Mandaluyong",
        "state": "Metro Manila",
        "postcode": "1550"
    },
    "parcel": {"type": "box", "weight_kg": 1.2}
}
```

<!-- fixture: pickup-slots.response -->
```json
{
    "data": [
        {
            "value": "2026-11-09",
            "label": "Mon, Nov 9",
            "date": "2026-11-09"
        },
        {
            "value": "2026-11-10",
            "label": "Tue, Nov 10",
            "date": "2026-11-10"
        }
    ]
}
```

---

### `POST /shipments`

Creates a shipment and, unless it is a draft, queues it for booking. **No courier is called during this request**: the answer is `202` with the shipment in state `queued`, and the booking happens a moment later (see [9](#9-booking)). Requires `Idempotency-Key`.

Query: `?wait=N` (whole seconds; values above 20 are treated as 20, negative values and anything that is not a whole number as 0) holds the answer until the booking settles (leaves `queued`/`booking`) or N seconds pass. The status is still `202`; read `data.state`.

Request fields:

| Field | Rule |
|---|---|
| `account_id` / `courier` | see [Choosing a courier account](#choosing-a-courier-account) |
| `client_reference` | **required**, string ≤ 64, no control characters. Your id for this shipment; see [9.2](#92-client_reference) |
| `order_label` | optional, string ≤ 64, a display label (e.g. `#4211`) |
| `submit` | optional boolean, default `true`. `false` saves a **draft** (`201`), booked later with `POST /shipments/{id}/submit` |
| `method` | optional, `pickup` (default) or `dropoff`. **SPX** books exactly this. A `pickup` without a `pickup_slot` is booked on the earliest pickup time SPX offers when it books (SPX 1.1), and that slot is stored on the shipment (`pickup_slot`); when SPX offers none the booking fails `courier_refused` "SPX has no pickup time available for this address." A pickup is never booked as a drop-off. With `dropoff`, `pickup_slot` is ignored. QuadX and LBC always collect |
| `pickup_date` | optional, `YYYY-MM-DD`, today or later (Manila). Anything else (`11/12/2026`, an impossible date, a past date) is refused with `422`; it is never replaced by another date. **LBC** books its pickup on this date when no `pickup_slot` is sent. For an LBC account the date must also be a Monday to Saturday, no earlier than the next business day and no later than the 10th business day from it; a Sunday, today, or a date outside that range is refused with `422` on `pickup_date`. When omitted, LBC books the next business day. Other couriers ignore it |
| `pickup_slot` | optional string ≤ 255: a `value` from `POST /pickup-slots`. For **SPX** anything else is refused with `422` on `pickup_slot`, code `pickup_slot_unavailable` when it is the only field in error. A slot whose day is over, or one SPX refuses as past its cutoff (SPX 10080), is replaced by the next pickup time SPX offers (SPX is asked again once after a cutoff refusal); the shipment then shows the slot it was booked on. When SPX refuses that one too, the booking fails `courier_refused` |
| `service` | optional string ≤ 64: a `service_types` value from `GET /couriers`; the account's default when omitted |
| `insure` | optional boolean, default `false`. `true` asks the courier to insure the parcel at `payment.declared_value` (or `total`). `false` sends no insurance, except at **LBC**, which always insures a COD parcel |
| `parcel` | optional object |
| `parcel.type` | optional string ≤ 64: a `parcel_options` value; the account's default when omitted |
| `parcel.category` | optional, one of `Food and beverage`, `Clothing`, `Electronics`, `Medical`, `Groceries`, `General merchandise`, `Fragile`, spelled exactly. Any other value is refused with `422`. Used by **SPX** as the item type; `General merchandise` when omitted. Other couriers ignore it |
| `parcel.weight_kg`, `parcel.length_cm`, `parcel.width_cm`, `parcel.height_cm` | optional numbers, > 0, ≤ 1000 |
| `payment` | **required** object |
| `payment.method` | **required**, `cod` or `prepaid` |
| `payment.cod_amount` | **required for `cod`**, money > 0, ≤ 2 decimals. **Must not be sent for `prepaid`** (422). This is exactly what the courier collects from the recipient |
| `payment.declared_value` | optional money ≥ 0: the insured value when the parcel is insured (`insure: true`, or any LBC COD parcel). When omitted, `total` is used |
| `payment.shipping_payment` | optional string ≤ 32: a `shipping_payment_options` value (who pays the courier's fee, and how) |
| `pickup` | **required**: `contact_name` (required, ≤ 255), `contact_phone` (required, [PH mobile](#phone-numbers)) and an [address](#addresses) |
| `recipient` | **required**: `first_name` (required, ≤ 255), `last_name` (≤ 255), `phone` (required, [PH mobile](#phone-numbers)), `email` (valid email, ≤ 255) and an [address](#addresses). |

**SPX field limits.** For an SPX account these are also refused with `422`: `recipient.first_name` and `recipient.last_name` together (joined with a space) longer than 64 characters (error on `recipient.first_name`); `pickup.contact_name` longer than 64; `address_1` and `address_2` together (joined with ", ") longer than 256, for either party (error on its `address_1`). SPX receives `address_2` after `address_1` in its detail address.
| `items` | **required** list, 1 to 100 |
| `items[].name` | required string ≤ 255 |
| `items[].quantity` | required integer, 1 to 100000 |
| `items[].price` | optional money ≥ 0 (per unit, informational) |
| `items[].weight_kg` | optional number > 0, ≤ 1000 |
| `total`, `subtotal` | optional money ≥ 0 (the order's amounts; `total` is the fallback declared value). QuadX requires `total` ≥ 1 on prepaid, otherwise the booking fails `courier_refused` "Parcel value is required." |
| `note` | optional string ≤ 500 (≤ 256 for **SPX**), passed to the courier as delivery instructions |
| `metadata` | optional object of up to 20 keys; keys are strings ≤ 40 characters; values are strings, numbers, booleans or null (no nesting); ≤ 4096 bytes as JSON. Echoed back unchanged on every answer and webhook |

**QuadX items.** QuadX receives the items with their prices when every `items[].price` is present and the lines add up to `total`. When a price is missing, or the lines do not add up, QuadX receives one consolidated item line. The items are compared to, and that line's amount is, `total` on prepaid or `payment.cod_amount` on COD, so QuadX's order total always matches.

**SPX limits.** SPX takes the weight from `parcel.weight_kg`, or from `items[].weight_kg` when every line has one (a booking with neither is refused). SPX takes a parcel up to 25 cm long (`length_cm`), 45 cm wide (`width_cm`) and 30 cm tall (`height_cm`), weighing up to 10 kg, with `payment.cod_amount` in whole pesos up to 50,000. A shipment SPX refuses ends `failed` with `error.code` `courier_refused` and a plain `error.message`, such as "Too long for SPX. Length is 35 cm. SPX takes up to 25 cm."

Money rules worth repeating: the courier collects `payment.cod_amount`, never the items' sum or `total`. Item prices do not need to add up to it. **SPX** collects whole pesos only: an SPX COD shipment with centavos (`1290.50`) is refused at booking (state `failed`, `error.code` `courier_refused`).

Example request:

<!-- fixture: create-shipment.request -->
```json
{
    "courier": "quadx",
    "client_reference": "4211",
    "order_label": "#4211",
    "method": "pickup",
    "service": "next_day",
    "insure": false,
    "parcel": {
        "type": "box",
        "weight_kg": 1.2,
        "length_cm": 20,
        "width_cm": 15,
        "height_cm": 10
    },
    "payment": {
        "method": "cod",
        "cod_amount": 1290.00,
        "declared_value": 1290.00
    },
    "pickup": {
        "contact_name": "Venta Warehouse",
        "contact_phone": "09181234567",
        "address_1": "5 Shaw Blvd",
        "barangay": "Wack-Wack",
        "city": "Mandaluyong",
        "state": "Metro Manila",
        "postcode": "1550",
        "country": "PH"
    },
    "recipient": {
        "first_name": "Juan",
        "last_name": "Dela Cruz",
        "phone": "+63 917 123 4567",
        "email": "juan@example.com",
        "address_1": "1 Rizal St",
        "barangay": "Poblacion",
        "city": "Makati",
        "state": "Metro Manila",
        "postcode": "1210",
        "country": "PH"
    },
    "items": [
        {"name": "Blue Tee", "quantity": 2, "price": 500.00, "weight_kg": 0.4},
        {"name": "Cap", "quantity": 1, "price": 200.00, "weight_kg": 0.4}
    ],
    "total": 1290.00,
    "subtotal": 1200.00,
    "note": "Call before delivery",
    "metadata": {"shop_id": "501", "order_id": 4211}
}
```

Response `202 Accepted`:

<!-- fixture: create-shipment.response -->
```json
{
    "data": {
        "id": "shp_01m6ws8t67jsavfxwwv98f8a12",
        "client_reference": "4211",
        "order_label": "#4211",
        "source": "api",
        "account": {
            "id": "acc_01m6ws8t5jz37691krkab4ph1f",
            "courier": "quadx",
            "label": "QuadX main"
        },
        "courier": "quadx",
        "state": "queued",
        "cancel_state": null,
        "phase": null,
        "courier_status": null,
        "courier_status_label": null,
        "tracking_number": null,
        "tracking_url": null,
        "label_available": false,
        "method": "pickup",
        "service": "next_day",
        "pickup_date": null,
        "pickup_slot": null,
        "pickup_slot_label": null,
        "fee": null,
        "payment_method": "cod",
        "payment_state": "cod_unpaid",
        "cod_amount": "1290.00",
        "declared_value": "1290.00",
        "insure": false,
        "items_count": 3,
        "items_summary": "Blue Tee ×2, Cap ×1",
        "notice": null,
        "error": null,
        "needs_attention": false,
        "attention_reason": null,
        "created_at": "2026-11-08T09:00:00+08:00",
        "booked_at": null,
        "updated_at": "2026-11-08T09:00:00+08:00",
        "metadata": {
            "shop_id": "501",
            "order_id": 4211
        }
    }
}
```

Statuses: `202` queued for booking; `201` draft saved; `409 active_shipment_exists`; `422 invalid_request`, `pickup_slot_unavailable`, `account_not_found`, `account_misconfigured` (an account that cannot book is refused here, before anything is stored); `422 idempotency_key_reused`; `409 idempotency_in_progress`.

<!-- fixture: error-active-shipment-exists.response -->
```json
{
    "error": "A live shipment already uses this client_reference.",
    "code": "active_shipment_exists",
    "retryable": false,
    "errors": {
        "shipment": "shp_01m6ws8tb1bwpeqzhnjjn1swmt"
    }
}
```

---

### `GET /shipments`

Your shipments, newest first, 50 per page. Every filter is optional; they combine with AND.

| Query | Rule |
|---|---|
| `ids` | comma-separated shipment ids, at most 50 |
| `client_reference` | exact match (all shipments that ever used it, including failed and cancelled ones) |
| `state` | one of `draft`, `queued`, `booking`, `booked`, `unknown`, `failed`, `cancelled` |
| `needs_attention` | `true`, `false`, `1` or `0` |
| `updated_since` | a timestamp; shipments whose `updated_at` is at or after it. Send ISO-8601 with an offset (`2026-11-08T13:00:00+08:00`) |
| `cursor` | the `meta.next_cursor` of the previous page |

Pagination is by cursor: follow `links.next` (or pass `meta.next_cursor` as `cursor`) until it is `null`. `links.next` is an absolute URL: request it as is, with the same `Authorization` header. Percent-encode `updated_since` (`+08:00` is `%2B08:00`).

For reconciliation (say, after your webhook endpoint was down), list with `updated_since` set to the last time you were sure you were in sync.

<!-- fixture: shipments-list.response -->
```json
{
    "data": [
        {
            "id": "shp_01m6ws8t67jsavfxwwv98f8a12",
            "client_reference": "4211",
            "order_label": "#4211",
            "source": "api",
            "account": {
                "id": "acc_01m6ws8t5jz37691krkab4ph1f",
                "courier": "quadx",
                "label": "QuadX main"
            },
            "courier": "quadx",
            "state": "booked",
            "cancel_state": null,
            "phase": "in_transit",
            "courier_status": "in_transit",
            "courier_status_label": "In transit",
            "tracking_number": "QX-TEST-0001",
            "tracking_url": "https://app.staging.gogoxpress.com/track/QX-TEST-0001",
            "label_available": true,
            "method": "pickup",
            "service": "next_day",
            "pickup_date": null,
            "pickup_slot": null,
            "pickup_slot_label": null,
            "fee": {
                "amount": "77.00",
                "currency": "PHP"
            },
            "payment_method": "cod",
            "payment_state": "cod_unpaid",
            "cod_amount": "1290.00",
            "declared_value": "1290.00",
            "insure": false,
            "items_count": 3,
            "items_summary": "Blue Tee ×2, Cap ×1",
            "notice": null,
            "error": null,
            "needs_attention": false,
            "attention_reason": null,
            "created_at": "2026-11-08T09:00:00+08:00",
            "booked_at": "2026-11-08T09:00:02+08:00",
            "updated_at": "2026-11-08T13:05:00+08:00",
            "metadata": {
                "shop_id": "501",
                "order_id": 4211
            }
        }
    ],
    "links": {
        "first": null,
        "last": null,
        "prev": null,
        "next": null
    },
    "meta": {
        "path": "http://localhost:8040/api/v1/shipments",
        "per_page": 50,
        "next_cursor": null,
        "prev_cursor": null
    }
}
```

---

### `GET /shipments/{id}`

One shipment. `?include=parties` adds the personal data: `pickup`, `recipient`, `items`, `note` and `parcel`. Without it (and in every webhook) they are left out. Another app's shipment is `404`.

<!-- fixture: shipment.response -->
```json
{
    "data": {
        "id": "shp_01m6ws8t67jsavfxwwv98f8a12",
        "client_reference": "4211",
        "order_label": "#4211",
        "source": "api",
        "account": {
            "id": "acc_01m6ws8t5jz37691krkab4ph1f",
            "courier": "quadx",
            "label": "QuadX main"
        },
        "courier": "quadx",
        "state": "booked",
        "cancel_state": null,
        "phase": "in_transit",
        "courier_status": "in_transit",
        "courier_status_label": "In transit",
        "tracking_number": "QX-TEST-0001",
        "tracking_url": "https://app.staging.gogoxpress.com/track/QX-TEST-0001",
        "label_available": true,
        "method": "pickup",
        "service": "next_day",
        "pickup_date": null,
        "pickup_slot": null,
        "pickup_slot_label": null,
        "fee": {
            "amount": "77.00",
            "currency": "PHP"
        },
        "payment_method": "cod",
        "payment_state": "cod_unpaid",
        "cod_amount": "1290.00",
        "declared_value": "1290.00",
        "insure": false,
        "items_count": 3,
        "items_summary": "Blue Tee ×2, Cap ×1",
        "notice": null,
        "error": null,
        "needs_attention": false,
        "attention_reason": null,
        "created_at": "2026-11-08T09:00:00+08:00",
        "booked_at": "2026-11-08T09:00:02+08:00",
        "updated_at": "2026-11-08T13:05:00+08:00",
        "metadata": {
            "shop_id": "501",
            "order_id": 4211
        }
    }
}
```

With `?include=parties`:

<!-- fixture: shipment-parties.response -->
```json
{
    "data": {
        "id": "shp_01m6ws8t67jsavfxwwv98f8a12",
        "client_reference": "4211",
        "order_label": "#4211",
        "source": "api",
        "account": {
            "id": "acc_01m6ws8t5jz37691krkab4ph1f",
            "courier": "quadx",
            "label": "QuadX main"
        },
        "courier": "quadx",
        "state": "booked",
        "cancel_state": null,
        "phase": "in_transit",
        "courier_status": "in_transit",
        "courier_status_label": "In transit",
        "tracking_number": "QX-TEST-0001",
        "tracking_url": "https://app.staging.gogoxpress.com/track/QX-TEST-0001",
        "label_available": true,
        "method": "pickup",
        "service": "next_day",
        "pickup_date": null,
        "pickup_slot": null,
        "pickup_slot_label": null,
        "fee": {
            "amount": "77.00",
            "currency": "PHP"
        },
        "payment_method": "cod",
        "payment_state": "cod_unpaid",
        "cod_amount": "1290.00",
        "declared_value": "1290.00",
        "insure": false,
        "items_count": 3,
        "items_summary": "Blue Tee ×2, Cap ×1",
        "notice": null,
        "error": null,
        "needs_attention": false,
        "attention_reason": null,
        "created_at": "2026-11-08T09:00:00+08:00",
        "booked_at": "2026-11-08T09:00:02+08:00",
        "updated_at": "2026-11-08T13:05:00+08:00",
        "metadata": {
            "shop_id": "501",
            "order_id": 4211
        },
        "pickup": {
            "contact_name": "Venta Warehouse",
            "contact_phone": "09181234567",
            "address_1": "5 Shaw Blvd",
            "barangay": "Wack-Wack",
            "city": "Mandaluyong",
            "state": "Metro Manila",
            "postcode": "1550",
            "country": "PH",
            "codes": {}
        },
        "recipient": {
            "first_name": "Juan",
            "last_name": "Dela Cruz",
            "phone": "09171234567",
            "email": "juan@example.com",
            "address_1": "1 Rizal St",
            "barangay": "Poblacion",
            "city": "Makati",
            "state": "Metro Manila",
            "postcode": "1210",
            "country": "PH",
            "codes": {}
        },
        "items": [
            {
                "name": "Blue Tee",
                "quantity": 2,
                "price": "500.00",
                "weight_kg": 0.4
            },
            {
                "name": "Cap",
                "quantity": 1,
                "price": "200.00",
                "weight_kg": 0.4
            }
        ],
        "note": "Call before delivery",
        "parcel": {
            "type": "box",
            "weight_kg": 1.2,
            "length_cm": 20,
            "width_cm": 15,
            "height_cm": 10
        }
    }
}
```

---

### `GET /shipments/{id}/events`

The shipment's courier timeline, oldest first.

| Field | Meaning |
|---|---|
| `courier_status` | the courier's own status code (`picked_up`, `1001`, …) |
| `label` | its display wording |
| `phase` | the [phase](#82-phase) it maps to |
| `occurred_at` | when the courier says it happened |
| `remarks` | the courier's remark, or `null` |
| `source` | how Ventaship learned of it: `system` (the booking answer), `webhook`, `poll`, or `refresh` |

<!-- fixture: shipment-events.response -->
```json
{
    "data": [
        {
            "courier_status": "for_pickup",
            "label": "Ready for pickup",
            "phase": "to_handover",
            "occurred_at": "2026-11-08T09:00:02+08:00",
            "remarks": null,
            "source": "system"
        },
        {
            "courier_status": "picked_up",
            "label": "Picked up",
            "phase": "in_transit",
            "occurred_at": "2026-11-08T10:00:00+08:00",
            "remarks": "Picked up by rider",
            "source": "webhook"
        },
        {
            "courier_status": "in_transit",
            "label": "In transit",
            "phase": "in_transit",
            "occurred_at": "2026-11-08T13:00:00+08:00",
            "remarks": "Departed sorting hub",
            "source": "webhook"
        }
    ]
}
```

---

### `POST /shipments/{id}/refresh`

Asks the courier for the shipment's tracking now and records anything new (each new event also produces a `shipment.status_changed` webhook). Allowed once a minute per shipment. Answers the shipment plus its whole timeline under `events`. No body.

Statuses: `200`; `409 not_trackable` (no tracking number yet, or state not `booked`/`booking`); `429 rate_limited` (with `Retry-After`); `502 courier_unavailable` (`retryable: true`).

You rarely need this: couriers' webhooks and Ventaship's polling keep shipments current on their own.

<!-- fixture: refresh.response -->
```json
{
    "data": {
        "id": "shp_01m6ws8t67jsavfxwwv98f8a12",
        "client_reference": "4211",
        "order_label": "#4211",
        "source": "api",
        "account": {
            "id": "acc_01m6ws8t5jz37691krkab4ph1f",
            "courier": "quadx",
            "label": "QuadX main"
        },
        "courier": "quadx",
        "state": "booked",
        "cancel_state": null,
        "phase": "in_transit",
        "courier_status": "out_for_delivery",
        "courier_status_label": "Out for delivery",
        "tracking_number": "QX-TEST-0001",
        "tracking_url": "https://app.staging.gogoxpress.com/track/QX-TEST-0001",
        "label_available": true,
        "method": "pickup",
        "service": "next_day",
        "pickup_date": null,
        "pickup_slot": null,
        "pickup_slot_label": null,
        "fee": {
            "amount": "77.00",
            "currency": "PHP"
        },
        "payment_method": "cod",
        "payment_state": "cod_unpaid",
        "cod_amount": "1290.00",
        "declared_value": "1290.00",
        "insure": false,
        "items_count": 3,
        "items_summary": "Blue Tee ×2, Cap ×1",
        "notice": null,
        "error": null,
        "needs_attention": false,
        "attention_reason": null,
        "created_at": "2026-11-08T09:00:00+08:00",
        "booked_at": "2026-11-08T09:00:02+08:00",
        "updated_at": "2026-11-08T13:05:00+08:00",
        "metadata": {
            "shop_id": "501",
            "order_id": 4211
        }
    },
    "events": [
        {
            "courier_status": "for_pickup",
            "label": "Ready for pickup",
            "phase": "to_handover",
            "occurred_at": "2026-11-08T09:00:02+08:00",
            "remarks": null,
            "source": "system"
        },
        {
            "courier_status": "picked_up",
            "label": "Picked up",
            "phase": "in_transit",
            "occurred_at": "2026-11-08T10:00:00+08:00",
            "remarks": "Picked up by rider",
            "source": "webhook"
        },
        {
            "courier_status": "in_transit",
            "label": "In transit",
            "phase": "in_transit",
            "occurred_at": "2026-11-08T13:00:00+08:00",
            "remarks": "Departed sorting hub",
            "source": "webhook"
        },
        {
            "courier_status": "out_for_delivery",
            "label": "Out for delivery",
            "phase": "in_transit",
            "occurred_at": "2026-11-08T13:04:00+08:00",
            "remarks": null,
            "source": "refresh"
        }
    ]
}
```

---

### `POST /shipments/{id}/submit`

Books a draft: the draft becomes `queued` and is booked exactly as a normal create. Requires `Idempotency-Key`. No body.

Statuses: `202` with the shipment (state `queued`); `409 not_draft` for anything but a draft (so a second submit can never book twice); `422 account_misconfigured` when the account cannot book now (the draft stays a draft).

---

### `POST /shipments/{id}/cancel`

Asks to cancel. Requires `Idempotency-Key`. No body. Answers the shipment:

- `202` while the cancel is still in flight (`cancel_state` `requested`): the booking must finish first, or the courier must be told. The outcome arrives as a `shipment.cancelled` or `shipment.cancel_failed` webhook.
- `200` once it is settled: cancelled, refused, unsupported, or nothing to do.

Cancelling is idempotent by state: asking again returns the shipment unchanged. The full rules are in [10](#10-cancel-rules). Example: a cancel refused because the parcel is already out for delivery:

<!-- fixture: cancel.response -->
```json
{
    "data": {
        "id": "shp_01m6ws8t67jsavfxwwv98f8a12",
        "client_reference": "4211",
        "order_label": "#4211",
        "source": "api",
        "account": {
            "id": "acc_01m6ws8t5jz37691krkab4ph1f",
            "courier": "quadx",
            "label": "QuadX main"
        },
        "courier": "quadx",
        "state": "booked",
        "cancel_state": "refused",
        "phase": "in_transit",
        "courier_status": "out_for_delivery",
        "courier_status_label": "Out for delivery",
        "tracking_number": "QX-TEST-0001",
        "tracking_url": "https://app.staging.gogoxpress.com/track/QX-TEST-0001",
        "label_available": true,
        "method": "pickup",
        "service": "next_day",
        "pickup_date": null,
        "pickup_slot": null,
        "pickup_slot_label": null,
        "fee": {
            "amount": "77.00",
            "currency": "PHP"
        },
        "payment_method": "cod",
        "payment_state": "cod_unpaid",
        "cod_amount": "1290.00",
        "declared_value": "1290.00",
        "insure": false,
        "items_count": 3,
        "items_summary": "Blue Tee ×2, Cap ×1",
        "notice": null,
        "error": null,
        "needs_attention": true,
        "attention_reason": "This shipment can no longer be cancelled (courier status: out_for_delivery)",
        "created_at": "2026-11-08T09:00:00+08:00",
        "booked_at": "2026-11-08T09:00:02+08:00",
        "updated_at": "2026-11-08T13:05:00+08:00",
        "metadata": {
            "shop_id": "501",
            "order_id": 4211
        }
    }
}
```

---

### `GET /shipments/{id}/label`

The waybill as a PDF (`Content-Type: application/pdf`, `Content-Disposition: attachment; filename="shp_….pdf"`). Fetched from the courier the first time, then served from Ventaship's copy.

Statuses: `200`; `409 label_not_ready` (not `booked` yet, or no tracking number: check `label_available`); `502 courier_unavailable` (`retryable` says whether a retry can help).

---

### `POST /labels`

Many waybills in one download: one merged PDF, or a ZIP of one PDF per shipment (named `{id}.pdf`) when the server cannot merge PDFs (check `Content-Type`). One printable id gives that one PDF.

Request: `shipment_ids`, a list of 1 to 50 distinct shipment ids.

<!-- fixture: labels.request -->
```json
{
    "shipment_ids": ["shp_01jc5x7k2m8n4p6q9r3s5t7v9w", "shp_01jc5x7k2m8n4p6q9r3s5t7v9x"]
}
```

Ids that are unknown, someone else's, not booked yet, or whose label cannot be fetched are skipped, not errors. The response header `Ventaship-Skipped` lists them, comma-separated. If none is printable: `409 label_not_ready`, or `502 courier_unavailable` when the courier failed for at least one of them.

---

## 7. The Shipment resource

The same object in every answer and in every shipment webhook (`data.shipment`), so one parser handles both.

| Field | Type | Meaning |
|---|---|---|
| `id` | string | `shp_…` |
| `client_reference` | string | your reference |
| `order_label` | string\|null | your display label |
| `source` | string | `api` (created through this API); `manual` and `csv` are created by the admin and never belong to a client app |
| `account` | object\|null | `{id, courier, label}` of the courier account |
| `courier` | string | courier key (`quadx`, `spx`, `lbc`) |
| `state` | string | the booking's lifecycle, see [8.1](#81-state) |
| `cancel_state` | string\|null | see [8.4](#84-cancel_state) |
| `phase` | string\|null | where the parcel is, see [8.2](#82-phase); `null` until the courier reports a status |
| `courier_status` | string\|null | the courier's latest status code |
| `courier_status_label` | string\|null | its display wording. Labels are the courier's official wording and may change; map from `courier_status`. |
| `tracking_number` | string\|null | set once booked |
| `tracking_url` | string\|null | the courier's public tracking page, when it has one |
| `label_available` | bool | `true` when `GET /shipments/{id}/label` can answer a PDF (state `booked` with a tracking number) |
| `method` | string | `pickup` or `dropoff` |
| `pickup_slot` | string\|null | the pickup slot the shipment is booked on: the `value` you sent, or for SPX the one Ventaship chose or moved to (see `pickup_slot` in [`POST /shipments`](#post-shipments)) |
| `pickup_slot_label` | string\|null | `pickup_slot` as people read it, Manila: `Tue, Nov 10 · 08:00-12:00` (SPX), `Tue, Nov 10` (LBC), the same wording as the slot's `label` in `POST /pickup-slots`. `null` when there is no slot, for an SPX drop-off, and for couriers without slots. Display only: send `pickup_slot`, never this |
| `service` | string\|null | the service requested |
| `pickup_date` | string\|null | `YYYY-MM-DD`, Manila: the day of `pickup_slot` when there is one (SPX, LBC), else the `pickup_date` you sent; `null` when neither is set. `null` for an SPX drop-off without a sent date |
| `fee` | object\|null | `{amount, currency}`: the fee the courier reported when it booked; `null` if it reported none. SPX: its estimated shipping fee for the booking (after vouchers), also when the booking settled late; the fee SPX finally bills can differ |
| `payment_method` | string | `cod` or `prepaid` |
| `payment_state` | string | see [8.3](#83-payment_state) |
| `cod_amount` | string\|null | what the courier collects (`null` for prepaid) |
| `declared_value` | string\|null | the insured value you sent (`null` if you sent none) |
| `insure` | bool | whether you asked for insurance |
| `items_count` | int | total quantity |
| `items_summary` | string\|null | `"Blue Tee ×2, Cap ×1"`, at most 255 characters |
| `notice` | string\|null | a note from the courier about this booking, to show the operator |
| `error` | object\|null | `{code, message}` when booking failed, see [9.4](#94-booking-error-codes) |
| `needs_attention` | bool | a person must look at this shipment (at the courier portal) |
| `attention_reason` | string\|null | why |
| `created_at` | string | ISO-8601 |
| `booked_at` | string\|null | ISO-8601, when booked |
| `updated_at` | string | ISO-8601, last change |
| `metadata` | object | your `metadata`, or `{}` |

Only with `GET /shipments/{id}?include=parties`: `pickup`, `recipient` (as stored: phones normalised to `09XXXXXXXXX`, `codes` always an object), `items` (`name`, `quantity`, `price` as a 2-decimal string or `null`, `weight_kg`), `note`, `parcel` (or `null`).

Ventaship keeps customer details (`pickup`, `recipient`, `note` and each event's `remarks`) on shipments indefinitely; there is no retention purge. Shipments cleared by an earlier version keep `null` in those fields.

---

## 8. States, phases and payment states

A shipment has four independent status fields. Read them together.

### 8.1 `state`

The booking's lifecycle: did Ventaship get the parcel booked?

| State | Meaning | Holds the `client_reference` |
|---|---|---|
| `draft` | saved with `submit: false`; nothing sent to the courier | yes |
| `queued` | waiting for the booking job | yes |
| `booking` | the courier is being asked (SPX may stay here briefly while it issues the tracking number) | yes |
| `booked` | the courier accepted it; `tracking_number` is set. Stays `booked` for the rest of the parcel's journey (follow `phase`) unless it is cancelled | yes |
| `unknown` | the courier call failed in a way that leaves the outcome unknown; `needs_attention` is `true`. See [9.3](#93-unknown-never-resubmit) | yes |
| `failed` | not booked; `error` says why | **no** (released) |
| `cancelled` | cancelled before or after booking, at your request or by the courier on its own | **no** (released) |

Transitions:

```
draft ──submit──▶ queued ──▶ booking ──▶ booked
                    │            ├──▶ failed
                    │            └──▶ unknown ──(admin checks the courier portal)──▶ booked | failed
                    └──(not started within 30 min)──▶ failed

draft | queued ──cancel──▶ cancelled
booked ──cancelled at the courier (asked for or not)──▶ cancelled
```

### 8.2 `phase`

Where the parcel is, on one scale shared by every courier. `null` until the courier reports its first status. Phases come from the courier's status (the admin may re-map a courier status to another phase), so a parcel can move backwards (e.g. `in_transit` after a failed delivery attempt).

| Phase | Meaning |
|---|---|
| `draft` | not booked (reserved; `phase` is `null` before booking) |
| `booking` | the courier accepted the request but has not issued the tracking number yet: SPX's `creating` status maps here. A shipment waiting on SPX is recognisable by `state` `booking` with `courier_status` `creating`; its `phase` may still be `null`, because that interim status is not recorded as a timeline event |
| `attention` | the courier has the parcel but is holding it (SPX `3001` On Hold); also reached when the Ventaship admin maps a courier status to it (a status no one knows becomes `in_transit`). Not final |
| `to_handover` | booked, waiting for the courier to collect it (or for drop-off) |
| `in_transit` | the courier has it, on its way (includes out for delivery) |
| `delivered` | delivered to the recipient |
| `failed` | a failed attempt or exception (failed pickup, failed delivery, rejected, lost or damaged per some couriers) |
| `lost_damaged` | lost or damaged. SPX `5002` Damaged and `5003` Lost map here (final). QuadX `lost`/`damaged` and LBC damaged statuses are still `failed`; an admin status mapping can move them |
| `returning` | coming back to the sender, or returned |
| `cancelled` | cancelled |

Show the parcel's progress from `phase` and the raw details from `courier_status` / `courier_status_label`. A courier status in phase `cancelled` on a booked shipment always settles it: state `cancelled`, `cancel_state` `cancelled`, reference freed, COD → `cod_void`, and a `shipment.cancelled` webhook after the `shipment.status_changed`. This happens whether or not you asked to cancel, and whatever `cancel_state` it had (a `refused` or `failed` cancel included; the attention that cancel raised is cleared).

### 8.3 `payment_state`

Where the cash stands. Ventaship records it; it never holds money.

| Payment state | Meaning |
|---|---|
| `prepaid` | nothing to collect (`payment_method` `prepaid`) |
| `cod_unpaid` | COD, not yet collected |
| `cod_collected` | the parcel was delivered, so the courier collected `cod_amount` |
| `cod_remitted` | the courier paid the money over (reserved: not set by this release) |
| `cod_void` | will never be collected: cancelled, returning, or lost/damaged before collection |

It only moves forward: `cod_unpaid → cod_collected → cod_remitted`, and `cod_unpaid → cod_void`. `cod_void` is final, and so is `cod_collected` against later bad news (a delivered parcel stays `cod_collected`). Each change produces a `shipment.payment_state_changed` webhook.

`cod_void` follows the phases `cancelled`, `returning` and `lost_damaged`. A lost or damaged SPX COD parcel becomes `cod_void`. QuadX and LBC report lost or damaged parcels as phase `failed`, so theirs stay `cod_unpaid` until the courier reports them returning or cancelled, or the admin re-maps the status.

### 8.4 `cancel_state`

| Value | Meaning |
|---|---|
| `null` | no cancel asked |
| `requested` | asked; waiting for the booking to finish or for the courier's answer |
| `cancelled` | done |
| `refused` | the courier will not cancel (e.g. it already has the parcel); `needs_attention` is `true` |
| `unsupported` | this courier cannot be cancelled through Ventaship (LBC); `needs_attention` is `true` and `attention_reason` says what to do |
| `failed` | the cancel call failed and its outcome is unknown; `needs_attention` is `true`. Never retried automatically |

---

## 9. Booking

### 9.1 Asynchronous by design

`POST /shipments` stores the shipment and answers `202` at once; a worker books it with the courier right after. Follow the result by webhook (`shipment.booked`, `shipment.booking_failed`, `shipment.unknown`), by `GET /shipments/{id}`, or by waiting in the create call itself with `?wait=N` (N ≤ 20 seconds).

A shipment that has not started booking within 30 minutes (workers down) becomes `failed` with `error.code` `not_started`. One stuck in `booking` for more than 10 minutes becomes `unknown`, except an SPX shipment waiting for its tracking number (`courier_status` `creating`), which Ventaship keeps polling. One still `creating` after 6 hours stays `booking` but gets `needs_attention` ("SPX has not confirmed the booking after 6 hours. Check the SPX portal"), cleared when SPX confirms it.

### 9.2 `client_reference`

Your reference is held by at most **one live shipment** per client app (every state except `failed` and `cancelled`, drafts included). A second create with the same reference is `409 active_shipment_exists`, and `errors.shipment` is the id of the shipment that holds it. Once that shipment is `failed` or `cancelled`, the reference is free again and you may create a new shipment with it (under a new `Idempotency-Key`).

### 9.3 `unknown`: never resubmit

If the courier times out, cannot be reached, or answers with a server error while booking, the parcel **may or may not** have been booked. Ventaship never retries a booking automatically (a second attempt could book the parcel twice). The shipment becomes `unknown` with `needs_attention: true`, and keeps holding its `client_reference`.

**Do not try to book the same order again while a shipment is `unknown`.** The Ventaship admin checks the courier portal and resolves it, which sends `shipment.resolved`:

- found at the courier: state `booked` with its tracking number;
- not found: state `failed` with `error.code` `not_booked`; the reference is released and you may create it again.

**SPX settles some of these itself.** When an SPX booking is retried and SPX answers that it already has the order (its error 13103), Ventaship looks the order up and books the shipment with it (`shipment.booked`, with `needs_attention` asking the admin to check its details at SPX); when it cannot be found the shipment is `unknown` with the reason "SPX already has order VS…: find its tracking number in the SPX portal and record it". And when SPX reports a parcel (by webhook) for a shipment that is `failed` or `unknown` here, the shipment becomes `booked` with that tracking number and `shipment.booked` is sent: a client must accept `shipment.booked` after `shipment.booking_failed` or `shipment.unknown`. If another live shipment holds the reference by then, both are kept and the recovered one gets `needs_attention`.

When both are kept, you may then cancel the older duplicate with `POST /shipments/{id}/cancel` and keep the newer one; the admin sees those cancels in Ventaship, and the usual [cancel rules](#10-cancel-rules) apply.

### 9.4 Booking error codes

When `state` is `failed`, `error` is `{code, message}`. `message` is for people (often the courier's own words). An SPX refusal reads as a sentence first, then SPX's code and its specific reason in brackets, e.g. `The pickup slot is past SPX's cutoff. Choose another pickup date. (SPX 10080: now_time over cutoff_time)`; show it whole, do not parse it.

| `error.code` | Meaning |
|---|---|
| `courier_refused` | the courier refused the booking (bad address, unserviceable, invalid data, SPX centavos…) |
| `account_misconfigured` | the account became unusable between create and booking (disabled, credentials removed) |
| `not_started` | the booking job did not start within 30 minutes |
| `not_booked` | the admin resolved an `unknown` booking as not booked |

### 9.5 Drafts

`submit: false` saves a draft (`201`, state `draft`). A draft is validated like any create, holds its `client_reference`, and calls no courier. Book it with `POST /shipments/{id}/submit`, or cancel it with `POST /shipments/{id}/cancel`. Drafts cannot be edited: cancel and create again (the reference is freed by the cancel).

---

## 10. Cancel rules

What `POST /shipments/{id}/cancel` does, by the shipment's state when you ask:

| State when asked | What happens | Answer | Webhook |
|---|---|---|---|
| `draft`, `queued` | cancelled at once (nothing was sent to the courier); reference freed | `200`, state `cancelled`, `cancel_state` `cancelled` | `shipment.cancelled` |
| `booking` | `cancel_state` `requested`. When the booking finishes: if booked, the cancel is sent to the courier (next rows); if refused, the cancel is simply done (state stays `failed`) | `202` | later `shipment.cancelled` / `shipment.cancel_failed` |
| `unknown` | `cancel_state` `requested`, `needs_attention`. Waits for the admin to resolve the booking: resolved booked → sent to the courier; resolved not booked → cancel done (state `failed`) | `202` | later |
| `booked`, the courier already reports it cancelled | cancelled at once, the courier is not called; reference freed | `200`, state `cancelled`, `cancel_state` `cancelled` | `shipment.cancelled` |
| `booked`, courier status still cancelable (or none yet) | `cancel_state` `requested`; Ventaship asks the courier | `202` | then one of the four below |
| … the courier cancels | state `cancelled`, phase `cancelled`, COD → `cod_void`, reference freed | | `shipment.cancelled` |
| … the courier refuses | `cancel_state` `refused`, `needs_attention`, state stays `booked` | | `shipment.cancel_failed` |
| … the call fails or times out | `cancel_state` `failed`, `needs_attention` ("Cancel outcome unknown. Check the courier portal"). Never retried | | `shipment.cancel_failed` |
| `booked`, courier status no longer cancelable (e.g. picked up) | refused at once, the courier is not called; `needs_attention` | `200`, `cancel_state` `refused` | `shipment.cancel_failed` |
| `booked`, its courier account deleted | refused at once: `cancel_state` `refused`, `needs_attention` ("This shipment's courier account no longer exists. Cancel it at the courier portal") | `200` | `shipment.cancel_failed` |
| `booked`, the cancel cannot be prepared (account settings unreadable) | `cancel_state` `failed`, `needs_attention` ("Cancel could not be prepared. Check the account settings"); the courier is not called | `200` | `shipment.cancel_failed` |
| `booked` at **LBC** | LBC has no cancel API: `cancel_state` `unsupported`, `needs_attention`, `attention_reason` "LBC has no cancel API. Call LBC to void this booking." The shipment stays `booked` | `200` | `shipment.cancel_failed` |
| `failed`, `cancelled` | nothing to do; returned unchanged | `200` | none |
| any, with `cancel_state` already set | returned unchanged (a cancel is asked once) | `202` if `requested`, else `200` | none |

Whatever the cancel did, a later courier report in phase `cancelled` settles the shipment (see [8.2](#82-phase)).

A cancel left `requested` for more than 30 minutes on a booked shipment is flagged `needs_attention` ("Cancel request stuck. Check and retry").

Cancelable courier statuses:

| Courier | Cancelable while `courier_status` is |
|---|---|
| QuadX | `pending`, `confirmed`, `for_pickup`, `searching_for_rider`, `failed_pickup_attempt`, `for_acceptance`, `for_consolidation`, `for_dropoff`, `for_payment`, `paid` |
| SPX | `1001` |
| LBC | (unsupported, see above) |

---

## 11. Outgoing webhooks

Ventaship POSTs an event to your client app's webhook URL for every change to one of your shipments. The URL, the webhook secret (`whsec_…`, shown once) and an optional event-type filter are set by the Ventaship admin. Shipments that do not belong to a client app (created by the admin by hand or by CSV) produce no webhooks.

### 11.1 Event types

| Type | When | `data.event` |
|---|---|---|
| `shipment.booked` | the courier booked it | `null` |
| `shipment.booking_failed` | the courier refused, or the booking could not start | `null` |
| `shipment.unknown` | the booking outcome is unknown ([9.3](#93-unknown-never-resubmit)) | `null` |
| `shipment.resolved` | the admin resolved an `unknown` shipment: read `data.shipment.state` (`booked` or `failed`) | `null` |
| `shipment.status_changed` | a new courier status was recorded (webhook, poll, refresh, or the booking answer) | the status, see below |
| `shipment.payment_state_changed` | `payment_state` changed | `null` |
| `shipment.cancelled` | the cancel is done (also when the courier cancelled it on its own, see [8.2](#82-phase)) | `null` |
| `shipment.cancel_failed` | the cancel was refused, unsupported or its outcome is unknown | `null` |
| `ping` | the admin sent a test (`ventaship:app-ping`) | (no shipment: `data` is `{"message": "ping"}`) |

A booking that the courier answers with a status records that status first: the client receives `shipment.status_changed` (e.g. `for_pickup`) immediately followed by `shipment.booked`, so the booked snapshot already carries `courier_status` and `phase`. A delivery to a COD recipient produces `shipment.status_changed` (delivered) followed by `shipment.payment_state_changed` (`cod_collected`).

### 11.2 Envelope

```
{
  "id":         "evt_…",           unique per event: dedupe on it
  "type":       "shipment.booked",
  "created_at": "…+08:00",          when the change happened (not when this delivery was sent)
  "sequence":   2,                  per shipment, increasing; 0 for events without a shipment (ping)
  "app":        "app_…",            your client app
  "data":       { … }
}
```

For every `shipment.*` type, `data` is:

- `shipment`: the [Shipment resource](#7-the-shipment-resource) as it was right after the change (no personal data), exactly what `GET /shipments/{id}` answered at that moment;
- `event`: for `shipment.status_changed`, the recorded status `{courier_status, label, phase, occurred_at, remarks}`; `null` for every other type.

<!-- fixture: webhook-shipment-booked -->
```json
{
    "id": "evt_01m6ws8t78527apmvv67den8je",
    "type": "shipment.booked",
    "created_at": "2026-11-08T09:00:02+08:00",
    "sequence": 2,
    "app": "app_01m6ws8t5ha0d1kyd1c8qxq4sq",
    "data": {
        "shipment": {
            "id": "shp_01m6ws8t67jsavfxwwv98f8a12",
            "client_reference": "4211",
            "order_label": "#4211",
            "source": "api",
            "account": {
                "id": "acc_01m6ws8t5jz37691krkab4ph1f",
                "courier": "quadx",
                "label": "QuadX main"
            },
            "courier": "quadx",
            "state": "booked",
            "cancel_state": null,
            "phase": "to_handover",
            "courier_status": "for_pickup",
            "courier_status_label": "Ready for pickup",
            "tracking_number": "QX-TEST-0001",
            "tracking_url": "https://app.staging.gogoxpress.com/track/QX-TEST-0001",
            "label_available": true,
            "method": "pickup",
            "service": "next_day",
            "pickup_date": null,
            "pickup_slot": null,
            "pickup_slot_label": null,
            "fee": {
                "amount": "77.00",
                "currency": "PHP"
            },
            "payment_method": "cod",
            "payment_state": "cod_unpaid",
            "cod_amount": "1290.00",
            "declared_value": "1290.00",
            "insure": false,
            "items_count": 3,
            "items_summary": "Blue Tee ×2, Cap ×1",
            "notice": null,
            "error": null,
            "needs_attention": false,
            "attention_reason": null,
            "created_at": "2026-11-08T09:00:00+08:00",
            "booked_at": "2026-11-08T09:00:02+08:00",
            "updated_at": "2026-11-08T09:00:02+08:00",
            "metadata": {
                "shop_id": "501",
                "order_id": 4211
            }
        },
        "event": null
    }
}
```

<!-- fixture: webhook-shipment-status-changed -->
```json
{
    "id": "evt_01m6ws8t940gfd5yyhjwg6563k",
    "type": "shipment.status_changed",
    "created_at": "2026-11-08T13:05:00+08:00",
    "sequence": 5,
    "app": "app_01m6ws8t5ha0d1kyd1c8qxq4sq",
    "data": {
        "shipment": {
            "id": "shp_01m6ws8t67jsavfxwwv98f8a12",
            "client_reference": "4211",
            "order_label": "#4211",
            "source": "api",
            "account": {
                "id": "acc_01m6ws8t5jz37691krkab4ph1f",
                "courier": "quadx",
                "label": "QuadX main"
            },
            "courier": "quadx",
            "state": "booked",
            "cancel_state": null,
            "phase": "in_transit",
            "courier_status": "out_for_delivery",
            "courier_status_label": "Out for delivery",
            "tracking_number": "QX-TEST-0001",
            "tracking_url": "https://app.staging.gogoxpress.com/track/QX-TEST-0001",
            "label_available": true,
            "method": "pickup",
            "service": "next_day",
            "pickup_date": null,
            "pickup_slot": null,
            "pickup_slot_label": null,
            "fee": {
                "amount": "77.00",
                "currency": "PHP"
            },
            "payment_method": "cod",
            "payment_state": "cod_unpaid",
            "cod_amount": "1290.00",
            "declared_value": "1290.00",
            "insure": false,
            "items_count": 3,
            "items_summary": "Blue Tee ×2, Cap ×1",
            "notice": null,
            "error": null,
            "needs_attention": false,
            "attention_reason": null,
            "created_at": "2026-11-08T09:00:00+08:00",
            "booked_at": "2026-11-08T09:00:02+08:00",
            "updated_at": "2026-11-08T13:05:00+08:00",
            "metadata": {
                "shop_id": "501",
                "order_id": 4211
            }
        },
        "event": {
            "courier_status": "out_for_delivery",
            "label": "Out for delivery",
            "phase": "in_transit",
            "occurred_at": "2026-11-08T13:04:00+08:00",
            "remarks": null
        }
    }
}
```

<!-- fixture: webhook-ping -->
```json
{
    "id": "evt_01m6ws8t9bgevr6xvc1gqzywe4",
    "type": "ping",
    "created_at": "2026-11-08T13:05:00+08:00",
    "sequence": 0,
    "app": "app_01m6ws8t5ha0d1kyd1c8qxq4sq",
    "data": {
        "message": "ping"
    }
}
```

### 11.3 Request headers

```
POST {your webhook URL}
Content-Type: application/json
User-Agent: Ventaship-Webhooks/1
X-Event-Id: evt_…
X-Event-Type: shipment.booked
X-Timestamp: 1794099602
X-Signature: sha256=…
```

### 11.4 Verifying the signature

```
X-Signature = "sha256=" + hex( HMAC_SHA256( webhook_secret, "{X-Timestamp}.{raw body}" ) )
```

Compute it over the **exact bytes received**, before any JSON parsing, and compare in constant time. `X-Timestamp` is the time of this delivery attempt (each retry is signed afresh); reject deliveries more than 5 minutes off your clock to limit replays.

PHP:

```php
$raw = file_get_contents('php://input');
$ts = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
$given = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$expected = 'sha256='.hash_hmac('sha256', $ts.'.'.$raw, $webhookSecret);

if (! ctype_digit($ts) || abs(time() - (int) $ts) > 300 || ! hash_equals($expected, $given)) {
    http_response_code(401);
    exit;
}
$event = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
```

Node (Express; keep the raw body):

```js
import crypto from 'node:crypto';
import express from 'express';

const app = express();
app.post('/ventaship/webhooks', express.raw({ type: 'application/json' }), (req, res) => {
  const raw = req.body; // Buffer
  const ts = req.get('X-Timestamp') ?? '';
  const given = Buffer.from(req.get('X-Signature') ?? '');
  const expected = Buffer.from('sha256=' + crypto.createHmac('sha256', process.env.VENTASHIP_WEBHOOK_SECRET)
    .update(`${ts}.`).update(raw).digest('hex'));

  if (!/^\d+$/.test(ts) || Math.abs(Date.now() / 1000 - Number(ts)) > 300
      || given.length !== expected.length || !crypto.timingSafeEqual(given, expected)) {
    return res.sendStatus(401);
  }
  const event = JSON.parse(raw.toString('utf8'));
  // … store event.id, process, then:
  res.sendStatus(200);
});
```

### 11.5 Answering, retries and giving up

- Answer any **2xx** within **10 seconds**. Anything else (a 3xx is not followed, a 4xx, a 5xx, a timeout, a refused connection) is a failed attempt.
- Do the work after answering, or make it quick: store the event, answer 200, process it.
- A failed event is retried after **1 min, 5 min, 15 min, 30 min, 1 h, 2 h, 4 h, then every 6 h**, until **72 hours** have passed since it first became deliverable. Then it is given up (the admin can resend it by hand).
- After a connection failure or timeout, deliveries to your app pause for a minute.

### 11.6 Ordering, duplicates and gaps

- **Ordering.** Events of one shipment are delivered strictly in `sequence` order: a later event is not sent while an earlier one of the same shipment is still pending. Events are sent right after the change that produced them, in order, so a burst of changes to one shipment (for example `status_changed` + `booked`) arrives within seconds. Retries (11.5) run on Ventaship's scheduler. A failing endpoint therefore holds back the later events of that shipment until the earlier one is delivered or given up. Events of different shipments are independent.
- **Duplicates.** The same event may be delivered more than once (a lost acknowledgement, a worker restart, an admin resend). **Dedupe on `id`.**
- **Stale events.** Each event carries the full shipment snapshot of its moment. Keep the highest `sequence` you have applied per shipment and ignore an event with a lower or equal `sequence` for state purposes: the snapshot you already have is newer.
- **Gaps.** `sequence` can skip numbers (for example when the admin filters out an event type for your app). A gap is not an error. If you suspect you missed something, `GET /shipments/{id}` is always current.

### 11.7 What is not delivered

These events are marked skipped and never sent (and never retried): the client app is disabled; it has no webhook URL (or an invalid one); it has no webhook secret; or the event type is not in the app's event filter (an empty filter means every type; `ping` is always sent).

---

## 12. Quickstart

**1. Get credentials from the Ventaship admin.** They run:

```bash
php artisan ventaship:app-create "My Shop" --webhook-url=https://shop.example.com/ventaship/webhooks
#   App:            app_…
#   Webhook secret: whsec_…           (shown once)
php artisan ventaship:key-issue app_… --name=production
#   12|vsk_…
```

and give you the app id, webhook secret and API key. Store the secrets as secrets.

**2. Ping.**

```php
$vs = new VentashipClient('http://localhost:8040', '12|vsk_…');
$r = $vs->request('GET', '/api/v1/ping');   // 200 {"app":{"id":"app_…","name":"My Shop"}}
```

**3. See what you can book with.**

```php
$vs->request('GET', '/api/v1/courier-accounts');  // pick an account, or just send "courier": "quadx"
$vs->request('GET', '/api/v1/couriers');          // service, parcel and shipping-payment values
```

**4. Create a shipment.** Use a stable `Idempotency-Key` per order so a retry after a timeout cannot double-book:

```php
$r = $vs->request('POST', '/api/v1/shipments?wait=10', $payload, ['Idempotency-Key' => 'order-4211-create']);
// 202; $data = json_decode($r['body'], true)['data']; $data['state'] is queued, booking, booked, failed or unknown
```

- `booked`: show `tracking_number` / `tracking_url`, print `GET /api/v1/shipments/{id}/label`.
- `failed`: show `error.message`, fix and create again (new `Idempotency-Key`).
- `unknown`: show "needs attention", and **do not** book this order again ([9.3](#93-unknown-never-resubmit)).
- `queued` / `booking`: the result arrives by webhook.

**5. Receive webhooks.** Verify `X-Signature` over the raw body, store `id` (ignore ones you have), answer 200 fast, then apply `data.shipment` if its `sequence` is newer than the last you applied for that shipment. Ask the admin to send a test (`ventaship:app-ping app_…`): you receive a `ping`.

**6. Reconcile now and then.** `GET /api/v1/shipments?updated_since=…` catches anything your endpoint missed while it was down.
