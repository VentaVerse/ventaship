# SPX (Shopee Xpress) Open API — API Reference

Source: https://spx.ph/integration/en/api/c27c8517d55c4fea96bcb426cfcaf140
Harvested: 2026-08-08 (verbatim `document.querySelector('div.doc').innerText` extraction per endpoint)

All endpoints use `{{host}}` as a placeholder — replace with the base URL for the target environment/region (see `guide.md` → "API address" section, and `integration-brief.md`). All endpoints are **POST**, JSON body, UTF-8, `Content-Type: application/json`, and all require the four standard signed headers (`app-id`, `check-sign`, `timestamp`, `random-num`) — see `guide.md` → "How to call the open API" / "API authentication guide" for the full header spec and the HMAC-SHA256 signing algorithm.

---

## 1. Order API

### 1.1 Get Pickup Timeslot

*(URL: https://spx.ph/integration/en/api/c27c8517d55c4fea96bcb426cfcaf140)*

Create Time: 08/06/2022 · Last Updated: 16/10/2024

Used for getting the available pickup time supported by the current service.

**HTTP URL:** `{{host}}/open/api/v1/order/get_pickup_time`
**HTTP Method:** POST
**Content-Type:** application/JSON, UTF-8 encoding

**Request header:** (standard 4 headers — app-id, check-sign, timestamp, random-num — see above)

**Request body:**

| Parameters | Type | Mandatory | Description |
|---|---|---|---|
| user_id | integer | Y | Unique user identification. Generated via 3.1 Create Account API or provided by open platform. |
| user_secret | string | Y | The user secret corresponding to the user id. Generated via 3.1 Create Account API or provided by open platform. |
| service_type | integer | Y | The service type. Optional value: 1: standard service |

Example request:
```json
{
    "user_id": xxxxx,
    "user_secret":  "CSFyQ8_JST",
    "service_type": 1
}
```

**Response:**

| Parameters | Type | Description |
|---|---|---|
| ret_code | integer | Response code. Data Type: unsigned 32-bit integers |
| message | string | Response message |
| data | object | — |
| data[].date | string | — |
| data[].pickup_time | string | date in timestamp format, can be used as request parameter in Create Order interface → fulfillment_info → pickup_time. Data Type: unsigned 32-bit integers |
| data[].slots | object | Available pickup time of the day |
| data[].slots[].pickup_time_range_id | integer | The id corresponding to the pickup time period, can be used as request parameter in 1.2 Create Order request body → fulfillment_info → pickup_time_range_id. Data Type: unsigned 32-bit integers |
| data[].slots[].pickup_time_range | string | The pickup time period string, can be used as request parameter in 1.2 Create Order request body → fulfillment_info → pickup_time_range. Data Type: string |

Example response:
```json
{
    "ret_code": 0,
    "message": "success.",
    "data": [
        {
            "date": "2022-02-17",
            "pickup_time": 1645088400,
            "slots": [
                { "pickup_time_range_id": 0, "pickup_time_range": "Now" },
                { "pickup_time_range_id": 2, "pickup_time_range": "2:00pm-4:00pm" },
                { "pickup_time_range_id": 3, "pickup_time_range": "4:00pm-6:00pm" },
                { "pickup_time_range_id": 4, "pickup_time_range": "6:00pm-8:00pm" }
            ]
        },
        {
            "date": "2022-02-18",
            "pickup_time": 1645174800,
            "slots": [
                { "pickup_time_range_id": 1, "pickup_time_range": "10:00am-12:00am" },
                { "pickup_time_range_id": 2, "pickup_time_range": "2:00pm-4:00pm" },
                { "pickup_time_range_id": 3, "pickup_time_range": "4:00pm-6:00pm" },
                { "pickup_time_range_id": 4, "pickup_time_range": "6:00pm-8:00pm" }
            ]
        }
    ]
}
```

---

### 1.2 Track Order

*(URL: https://spx.ph/integration/en/api/4bd8b0f429154d8d8877b14fdd198b02)*

Create Time: 08/06/2022 · Last Updated: 09/05/2026

Used for query details of orders (except information related to fees).

**NOTE:** Order information returned by this API may require a short time to become fully available after order creation. If this API is called immediately after Create Order succeeds, especially within 1 second, some newly created orders may temporarily return incomplete information (e.g. empty `sort_code_info`), or appear in `fail_list` with `ret_code=13251` (e.g. "order id cannot be found" or "tracking no cannot be found"). Wait for a short period and retry (e.g. after 1-3 seconds with backoff). Do not use Track Order immediately after Create Order as a strong consistency validation.

**HTTP URL:** `{{host}}/open/api/v1/order/batch_search_order`
**HTTP Method:** POST

**Request body:**

| Parameters | Type | Mandatory | Description |
|---|---|---|---|
| user_id | integer | Y | Unique user identification. Generated via Create Account API or provided by open platform. |
| user_secret | string | Y | The user secret corresponding to the user id. Generated via the Create Account API or provided by open platform. |
| tracking_no_list | list | Y | the list of tracking number (string) obtained from 1.2 Create order. |

Example request:
```json
{
    "user_id":46345543648695,
    "user_secret":"4196c158-b7cd-41c3-a87a-058aef09ea20",
    "tracking_no_list":["SPEPH041655957558", "SPEPH047082279439"]
}
```

**Response:**

| Parameters | Type | Description |
|---|---|---|
| ret_code | int | Response code |
| message | string | Response message |
| data | list | Order detail information |
| data.orders | — | array of order objects (fields below) |
| orders[].tracking_no | string | tracking number |
| orders[].status | string | Current parcel status |
| orders[].status_code | string | Current parcel status code |
| orders[].base_info.service_type | integer | service type — 1: standard service |
| orders[].sender_info.sender_state / sender_city / sender_district / sender_street / sender_post_code / sender_longitude / sender_latitude / sender_name / sender_phone / sender_detail_address | string | sender address fields |
| orders[].fulfillment_info.payment_role | integer | payment role: 1: sender pay, 2: receiver pay |
| orders[].fulfillment_info.cod_collection | integer | 0: no, 1: yes |
| orders[].fulfillment_info.cod_amount | integer | COD amount, mandatory when cod_collection=1. PH COD Max 50,000 Peso |
| orders[].fulfillment_info.collect_type | integer | 1: pickup; 2: drop off |
| orders[].fulfillment_info.pickup_time | integer | Scheduled pickup time |
| orders[].fulfillment_info.pickup_time_range_id | integer | Sequence number |
| orders[].fulfillment_info.deliver_type | integer | deliver type |
| orders[].fulfillment_info.voucher_code | string | voucher code applied for this order |
| orders[].deliver_info.deliver_state / deliver_city / deliver_district / deliver_street / deliver_post_code / deliver_longitude / deliver_latitude / deliver_name / deliver_phone / deliver_detail_address / deliver_instruction | string | receiver address fields |
| orders[].parcel_info.parcel_weight / parcel_length / parcel_width / parcel_height | number | Units: kg / cm / cm / cm |
| orders[].parcel_info.parcel_item_name | string | item name |
| orders[].parcel_info.parcel_item_quantity | integer | Item Quantity |
| orders[].parcel_info.express_insured_value | number | express insured value |
| orders[].parcel_info.parcel_item_type | string | Food and beverage / Clothing / Electronics / Medical / Groceries / General merchandise / Fragile |
| orders[].routes[].status / status_code / message / timestamp | string/string/string/integer | route event |
| orders[].edd_info.edd_min / edd_max | integer | Estimated Delivery Time range (min/max days), shown before parcel pickup at order creation |
| data.fail_list[].ret_code | integer | error code |
| data.fail_list[].message | string | error message |
| data.fail_list[].tracking_no | string | Failed tracking number |

Example response (abridged fields shown, full nested object captured):
```json
{
    "ret_code": 0,
    "message": "success. ",
    "data": {
        "orders": [
            {
                "order_type": 1,
                "tracking_no": "SPEPH053855676287",
                "reverse_tracking_no": "",
                "order_id": "",
                "status": "Pending Pickup",
                "status_code": "1001",
                "tracking_link": "https://uat.spx.ph/track?SPEPH053855676287",
                "base_info": { "service_type": 1, "product_id": null },
                "sender_info": {
                    "sender_state": "Metro Manila",
                    "sender_city": "Metro Manila",
                    "sender_district": "Binondo",
                    "sender_street": "Barangay 287",
                    "sender_post_code": "",
                    "sender_longitude": "",
                    "sender_latitude": "",
                    "sender_name": "cc",
                    "sender_phone": "631112223933",
                    "sender_email": "",
                    "sender_detail_address": "1122",
                    "sender_unit_no": null
                },
                "fulfillment_info": {
                    "payment_role": 1,
                    "cod_collection": 0,
                    "cod_amount": 0,
                    "insurance_collection": 1,
                    "collect_type": 2,
                    "pickup_time": 0,
                    "pickup_time_range_id": 0,
                    "pickup_time_range": "",
                    "allow_mutual_check": null,
                    "order_create_time": null,
                    "cb_flag": null,
                    "whs_id": null,
                    "return_whs_id": null,
                    "page_enter_time": null,
                    "allow_try_on": 0,
                    "voucher_code": "rosey normal-v2",
                    "allow_partial_delivery": 0
                },
                "deliver_info": {
                    "deliver_state": "Metro Manila",
                    "deliver_city": "Metro Manila",
                    "deliver_district": "Binondo",
                    "deliver_street": "Barangay 287",
                    "deliver_post_code": "",
                    "deliver_longitude": "",
                    "deliver_latitude": "",
                    "deliver_name": "1232",
                    "deliver_phone": "634455566677",
                    "deliver_email": null,
                    "deliver_detail_address": "sdfsdf",
                    "deliver_unit_no": null,
                    "deliver_instruction": "sdfsdf",
                    "kyc_name": null,
                    "kyc_phone": null,
                    "spx_branch_id": null,
                    "sls_branch_id": null,
                    "slot_id": null,
                    "branch_name": null,
                    "proof_of_delivery_list": null
                },
                "parcel_info": {
                    "parcel_weight": 1,
                    "parcel_length": 1,
                    "parcel_width": 1,
                    "parcel_height": 1,
                    "parcel_item_name": "Clothing",
                    "parcel_item_quantity": 1,
                    "express_insured_value": 150,
                    "size_id": null,
                    "parcel_value": null,
                    "item_list": [
                        {
                            "item_id": null,
                            "item_name": "Clothing",
                            "item_weight": "1000",
                            "item_price": null,
                            "item_picture": null,
                            "item_quantity": 1
                        }
                    ],
                    "parcel_item_type": "Clothing",
                    "delivered_item_list": null
                },
                "routes": [
                    {
                        "status": "Pending Pickup",
                        "status_code": "1001",
                        "message": " Sender is preparing to ship your parcel.",
                        "timestamp": 1753171999,
                        "driver_name": "",
                        "driver_phone_number": ""
                    }
                ],
                "vas_ino": {},
                "sort_code_info": {
                    "r_first_sort_code": "B-003-MKT-bsc-GMA-6001",
                    "r_third_sort_code": "",
                    "return_first_sort_code": "B-003-MKT-bsc-GMA-6001"
                },
                "edd_info": { "edd_min": 0, "edd_max": 0 }
            }
        ],
        "fail_list": []
    }
}
```

---

### 1.3 Cancel Order

*(URL: https://spx.ph/integration/en/api/bb53ea49018e4575ae1e3872a537f7a9)*

Create Time: 08/06/2022 · Last Updated: 16/10/2024

Used for batch cancel order which have been successfully created.

**HTTP URL:** `{{host}}/open/api/v1/order/batch_cancel_order`
**HTTP Method:** POST

**Request body:**

| Parameters | Type | Mandatory | Description |
|---|---|---|---|
| user_id | integer | Y | Unique user identification. |
| user_secret | string | Y | The user secret corresponding to the user id. |
| tracking_no_list | list | Y | the tracking number (string) obtained from 1.2 Create order |

Example request:
```json
{
    "user_id": 195216188605877,
    "user_secret": "6e1c50c0-93f2-4b66-affe-0c79f9dbe1a3",
    "tracking_no_list":["SPEPH041655957558"]
}
```

**Response:**

| Parameters | Type | Description |
|---|---|---|
| ret_code | int | Response code |
| message | string | Response message |
| data.tracking_no_list | list (string) | the successfully canceled tracking numbers |
| data.fail_list[].ret_code | int | error code |
| data.fail_list[].message | string | error message |
| data.fail_list[].tracking_no | string | the failed tracking number |

> Recall (`guide.md` order error table): `13002 OrderUnableCancelCode` — "Only those orders with pending pickup/dropoff status can be cancelled."

Example response:
```json
{
    "ret_code": 0,
    "message": "success.",
    "data": {
        "tracking_no_list": [],
        "fail_list": [
            {
                "ret_code": 13000,
                "message": "Cancel Order Failed. ",
                "tracking_no": "SPEPH041655957558"
            }
        ]
    }
}
```

---

### 1.4 Create Order V2

*(URL: https://spx.ph/integration/en/api/8c5233900b5b40b6af0348d13de172b1)*

Create Time: 14/09/2022 · Last Updated: 20/05/2026

Used for batch creating orders. Once successfully created, it returns the `batch_no` of these orders. **Note: at most 100 per batch.** This is asynchronous — call **1.5 Get Order Create Result V2** with the returned `batch_no` to get the actual tracking numbers / failures.

**HTTP URL:** `{{host}}/open/api/v2/order/batch_create_order`
**HTTP Method:** POST

**Request body:**

| Parameters | Type | Mandatory | Description |
|---|---|---|---|
| user_id | integer | Y | Unique user identification. |
| user_secret | string | Y | The user secret corresponding to the user id. |
| orders | list | Y | Order details. Max 100 orders. |
| orders[].order_id | string | N | Customer system order id. Max length: 32 |
| orders[].base_info.service_type | integer | Y | 1: standard service |
| orders[].sender_info.sender_state | string | Y | max length 64 |
| orders[].sender_info.sender_city | string | Y | max length 64 |
| orders[].sender_info.sender_district | string | Y | For PH: Mandatory. Max length 64 |
| orders[].sender_info.sender_street | string | Y | For PH: Mandatory. Max length 64 |
| orders[].sender_info.sender_post_code | string | N | For PH: Mandatory. Max length 16 |
| orders[].sender_info.sender_longitude | string | N | max length 16 |
| orders[].sender_info.sender_latitude | string | N | max length 16 |
| orders[].sender_info.sender_name | string | Y | max length 64 |
| orders[].sender_info.sender_phone | string | Y | PH: 11 digits starting with 0, or 12 digits starting with country code (63) |
| orders[].sender_info.sender_detail_address | string | Y | max length 256 |
| orders[].fulfillment_info.payment_role | integer | Y | 1: sender pay; 2: receiver pay |
| orders[].fulfillment_info.cod_collection | integer | Y | 0: no; 1: yes |
| orders[].fulfillment_info.cod_amount | integer | N | mandatory when cod_collection=1. PH COD Max 50,000 Peso |
| orders[].fulfillment_info.collect_type | integer | Y | 1: pickup; 2: drop off |
| orders[].fulfillment_info.pickup_time | integer | N | Expected pickup time (timestamp). Obtained via 1.1 Get the available pick up time. Mandatory only when collect_type is pickup. |
| orders[].fulfillment_info.pickup_time_range_id | integer | N | Pick up time slot id, from 1.1. Mandatory only when collect_type is pickup. |
| orders[].fulfillment_info.pickup_time_range | integer *(sic — documented as integer but example shows a string like "09:00 - 13:00")* | N | Pick up time range, e.g. "09:00 - 13:00". Mandatory only when collect_type is pickup. |
| orders[].fulfillment_info.voucher_code | string | N | voucher code to apply, from 1.8 Get voucher list |
| orders[].deliver_info.deliver_state | string | Y | max length 64 |
| orders[].deliver_info.deliver_city | string | Y | max length 64 |
| orders[].deliver_info.deliver_district | string | Y | For PH: Mandatory. Max length 64 |
| orders[].deliver_info.deliver_street | string | Y | For PH: Mandatory. Max length 64 |
| orders[].deliver_info.deliver_post_code | string | N | For PH: Mandatory. Max length 16 |
| orders[].deliver_info.deliver_longitude | string | N | max length 16 |
| orders[].deliver_info.deliver_latitude | string | N | max length 16 |
| orders[].deliver_info.deliver_name | string | Y | receiver's name, max length 64 |
| orders[].deliver_info.deliver_phone | string | Y | PH: 11 digits starting with 0, or 12 digits starting with country code (63) |
| orders[].deliver_info.deliver_detail_address | string | Y | receiver's detail address, max length 256 |
| orders[].deliver_info.deliver_instruction | string | N | receiver's remark, max length 256 |
| orders[].parcel_info.parcel_weight | number | Y | Unit: kg |
| orders[].parcel_info.parcel_length | number | Y | Unit: cm |
| orders[].parcel_info.parcel_width | number | Y | Unit: cm |
| orders[].parcel_info.parcel_height | number | Y | Unit: cm |
| orders[].parcel_info.parcel_item_name | string | Y | max length 64 |
| orders[].parcel_info.parcel_item_quantity | integer | N | item quantity |
| orders[].parcel_info.express_insured_value | number | Y | input for Parcel value |
| orders[].parcel_info.parcel_item_type | string | Y | Food and beverage / Clothing / Electronics / Medical / Groceries / General merchandise / Fragile |
| orders[].parcel_info.item_list[].item_id | integer | N | pass if known, else SPX auto-generates one |
| orders[].parcel_info.item_list[].item_name | string | Y | — |
| orders[].parcel_info.item_list[].item_weight | string | Y | unit: g |
| orders[].parcel_info.item_list[].item_price | string | Y | — |
| orders[].parcel_info.item_list[].item_picture | string | N | — |
| orders[].parcel_info.item_list[].item_quantity | integer | Y | — |
| orders[].parcel_info.item_list[].item_category | string | Y | same enum as parcel_item_type |

Example request (for 10 orders, showing 1):
```json
{
    "user_id":46345543648695,
    "user_secret":"4196c158-b7cd-41c3-a87a-058aef09ea20",
    "orders": [
       {
           "order_id": "GW123456789",
           "base_info": { "service_type": 1 },
           "sender_info": {
                "sender_state": "Metro Manila",
                "sender_city": "Metro Manila",
                "sender_district": "Binondo",
                "sender_street": "Barangay 294",
                "sender_post_code": "115",
                "sender_name": "sender test",
                "sender_phone": "639876543333",
                "sender_detail_address": "1"
            },
           "fulfillment_info": {
               "payment_role": 1,
               "cod_collection": 1,
               "cod_amount": 100,
               "collect_type": 2,
               "pickup_time": 1753430400,
               "pickup_time_range_id": 1,
               "pickup_time_range": "09:00 - 13:00",
               "voucher_code": "Newseller1"
           },
           "deliver_info": {
                   "deliver_longitude": "",
                   "deliver_latitude": "",
                   "deliver_country": "PH",
                   "deliver_detail_address": "sdfsdf",
                   "deliver_name": "1232",
                   "deliver_post_code": "38000",
                   "deliver_phone": "6676112222",
                   "deliver_state": "x",
                   "deliver_city": "x",
                   "deliver_town": "x",
                   "deliver_instruction": "sdfsdf"
           },
           "parcel_info": {
               "parcel_weight": 1,
               "parcel_item_name": "Clothing",
               "parcel_item_quantity": 1,
               "parcel_length": 1,
               "parcel_width": 1,
               "parcel_height": 1,
               "express_insured_value": "150",
               "parcel_item_type": "Clothing"
           }
       }
    ]
}
```
> Note: the harvested example has a syntax error (missing comma after `"pickup_time_range": "09:00 - 13:00"` before `"voucher_code"`) and uses `deliver_country`/`deliver_town` fields not documented in the request-body table above — treat the table as authoritative for field names, the example as illustrative only, and fix the JSON before using it verbatim.

**Response:**

| Parameters | Type | Description |
|---|---|---|
| ret_code | int | Response code |
| message | string | Response message |
| data.batch_no | integer | Batch number of these orders |

Example response:
```json
{
    "ret_code": 0,
    "message": "success. ",
    "data": { "batch_no": 207982800897461 }
}
```

---

### 1.5 Get Order Create Result V2

*(URL: https://spx.ph/integration/en/api/bc0e6b62beae429cacd80b70952f979b)*

Create Time: 14/09/2022 · Last Updated: 20/01/2025

Used to get order create result after calling 1.4 Create Order V2. Once order create status is done, it returns the tracking number(s). **Note: only supports one batch_no per call.**

**HTTP URL:** `{{host}}/open/api/v2/order/get_order_create_result`
**HTTP Method:** POST

**Request body:**

| Parameters | Type | Mandatory | Description |
|---|---|---|---|
| user_id | integer | Y | — |
| user_secret | string | Y | — |
| batch_no | integer | Y | Batch no of these orders (from 1.4) |

Example request:
```json
{
   "user_id": 219216466249141,
   "user_secret": "704c0865-eee7-47bd-9a76-a3bb73d60e8b",
   "batch_no": 207982800897461
}
```

**Response:**

| Parameters | Type | Description |
|---|---|---|
| data.batch_no | integer | Batch number |
| data.task_status | integer | 1: New, 2: Waiting, 3: Running, 4: Done, 5: Failed |
| data.description | string | Task status (English description) |
| data.total_count | integer | Total count of this batch |
| data.success_count | integer | Success count |
| data.fail_count | integer | Fail count |
| data.progress | string | percentage string |
| data.orders[].order_id | string | Customer system order id |
| data.orders[].order_id_link | string | order id link to query order tracking |
| data.orders[].tracking_no | string | SPX tracking number |
| data.orders[].tracking_no_link | string | tracking no link to query order tracking |
| data.orders[].r_first_sort_code | string | Delivery sort code (optional — request in advance) |
| data.orders[].r_third_sort_code | string | Delivery zone ID (optional — request in advance) |
| data.orders[].return_first_sort_code | string | Return sort code (optional — request in advance) |
| data.orders[].estimated_shipping_fee | number | — |
| data.orders[].basic_shipping_fee | number | — |
| data.orders[].cod_service_fee | number | — |
| data.orders[].insurance_service_fee | number | — |
| data.orders[].vat_fee | number | — |
| data.orders[].voucher_shipping_fee | number | — |
| data.fail_list[].order_id | string | Customer system order id |
| data.fail_list[].message | string | fail message |
| data.fail_list[].debug_msg | string | Specific reasons for failure |
| data.fail_list[].ret_code | integer | error code |

**Be careful:** If all orders failed to be created, the task status is still `Done`.

Example response (abridged to 2 orders; source example had 10):
```json
{
    "ret_code": 0,
    "message": "success. ",
    "data": {
        "batch_no": 207982800897461,
        "task_status": 4,
        "description": "Done",
        "total_count": 10,
        "success_count": 10,
        "fail_count": 0,
        "progress": "100.00%",
        "orders": [
            {
                "order_id": "1",
                "order_id_link": "https://test.spx.co.th/track?1",
                "tracking_no": "SPXTH02460308936A",
                "tracking_no_link": "https://test.spx.co.th/track?SPXTH02460308936A",
                "r_first_sort_code": "open-market-sort-1",
                "r_third_sort_code": "",
                "return_first_sort_code": "open-market-sort-2",
                "estimated_shipping_fee": 25,
                "basic_shipping_fee": 25,
                "cod_service_fee": 0,
                "insurance_service_fee": 0,
                "vat_fee": 0,
                "voucher_shipping_fee": 0
            },
            {
                "order_id": "2",
                "order_id_link": "https://test.spx.co.th/track?2",
                "tracking_no": "SPXTH02247120648A",
                "tracking_no_link": "https://test.spx.co.th/track?SPXTH02247120648A",
                "r_first_sort_code": "open-market-sort-1",
                "r_third_sort_code": "",
                "return_first_sort_code": "open-market-sort-2",
                "estimated_shipping_fee": 25,
                "basic_shipping_fee": 25,
                "cod_service_fee": 0,
                "insurance_service_fee": 0,
                "vat_fee": 0,
                "voucher_shipping_fee": 0
            }
        ],
        "fail_list": []
    }
}
```
(Note: the source example also shows optional `try_on_service_fee`, `mutual_check_service_fee`, `partial_delivery_service_fee` fields on some order entries — these appear to be extra/undocumented fee breakdown fields not listed in the response parameter table.)

---

### 1.6 Get AWB V2 (waybill / shipping label)

*(URL: https://spx.ph/integration/en/api/1fa4ab78c1cf46478a0366a0b6b841fa)*

Create Time: 14/09/2022 · Last Updated: 16/10/2024

Used for batch get waybill label. **Note: you can only print orders that have not been cancelled. The returned link is valid within half an hour, and can be opened up to 10 times.**

**HTTP URL:** `{{host}}/open/api/v2/order/batch_get_shipping_label`
**HTTP Method:** POST

**Request body:**

| Parameters | Type | Mandatory | Description |
|---|---|---|---|
| user_id | integer | Y | — |
| user_secret | string | Y | — |
| batch_no | integer | Y | Batch number of these orders, obtained from 1.4 Create order V2 |

Example request:
```json
{
    "user_id": 219216466249141,
    "user_secret": "704c0865-eee7-47bd-9a76-a3bb73d60e8b",
    "batch_no": 207982800897461
}
```

**Response:**

| Parameters | Type | Description |
|---|---|---|
| data.batch_no | integer | — |
| data.task_status | integer | 1: New, 2: Waiting, 3: Running, 4: Done, 5: Failed |
| data.description | string | — |
| data.total_order_count | integer | — |
| data.total_print_count | integer | — |
| data.success_print_count | integer | — |
| data.progress | string | percentage |
| data.awb_link | string | Waybill label's link, shown when task status is done |
| data.orders[].order_id | string | Order id in partner system |
| data.orders[].tracking_no | string | Tracking no in SPX system |

(orders are ordered by create time descending)

Example response:
```json
{
    "ret_code": 0,
    "message": "success. ",
    "data": {
        "batch_no": 207982800897461,
        "task_status": 4,
        "description": "Done",
        "total_order_count": 10,
        "total_print_count": 10,
        "success_print_count": 10,
        "progress": "100.00%",
        "awb_link": "https://test-stable.spx.co.th/downloads/resource/shipping_label/c9c0e35487ea08749c543455a0790b0d.pdf",
        "orders": [
            { "order_id": "10", "tracking_no": "SPEPH02767473618A" },
            { "order_id": "9", "tracking_no": "SPEPH02671858152A" }
        ]
    }
}
```

---

### 1.7 Update Order

*(URL: https://spx.ph/integration/en/api/faba81e9c71543bea5ad3b4425367432)*

Create Time: 25/09/2023 · Last Updated: 22/07/2025

Used for batch updating order information. **The maximum number of edits per order is 3. The AWB must be reprinted after order update. Note: at most 100 per batch. All info can only be updated in "create" status** (i.e. before pickup/dropoff — see `guide.md` → "Update Order Flowchart" for the exact allowed window and non-editable fields: payment_role, insurance_collection, express_insured_value, collect_type).

**HTTP URL:** `{{host}}/open/api/v1/order/batch_update_order`
**HTTP Method:** POST

**Request body:** same shape as 1.4 Create Order V2's `orders[]` object plus a `tracking_no` to identify the order being updated, with per-field mutability notes:

| Parameters | Type | Mandatory | Description |
|---|---|---|---|
| user_id, user_secret | integer/string | Y | — |
| orders[].order_id | string | N | Customer system order id, max length 32 |
| orders[].base_info.service_type | integer | Y | Modification NOT supported — pass the original value |
| orders[].sender_info.sender_state / sender_city | string | Y | not individually annotated as editable/not |
| orders[].sender_info.sender_district / sender_street | string | Y, can be updated before Pickup_done/Dropoff_received | — |
| orders[].sender_info.sender_post_code | string | N, can be updated before Pickup_done/Dropoff_received | max length 17 |
| orders[].sender_info.sender_longitude / sender_latitude | string | N, can be updated before Pickup/Dropoff | max length 16 |
| orders[].sender_info.sender_name / sender_phone | string | Y, can be updated before Pickup_done/Dropoff_received | phone: 11 digits starting with 0, or 12 digits starting with 63 |
| orders[].sender_info.sender_detail_address | string | N, can be updated before Pickup/Dropoff | max length 256 |
| orders[].fulfillment_info.payment_role | integer | Y | 1: sender pay, 2: receiver pay |
| orders[].fulfillment_info.cod_collection | integer | Y | 0: no, 1: yes |
| orders[].fulfillment_info.cod_amount | number | N | mandatory when cod_collection=1. PH Max 50,000 Peso |
| orders[].fulfillment_info.collect_type | integer | Y | Modification NOT supported — pass the original value. 1: pickup, 2: drop off |
| orders[].fulfillment_info.pickup_time | integer | Y, can be updated before Pickup/Dropoff | mandatory only when collect_type is pickup |
| orders[].fulfillment_info.pickup_time_range_id | integer | Y, can be updated before Pickup/Dropoff | mandatory only when collect_type is pickup |
| orders[].deliver_info.* (deliver_state, deliver_city, deliver_district, deliver_street) | string | Y, can be updated before Pickup_done/Dropoff_received | max length 64 |
| orders[].deliver_info.deliver_post_code | string | N, can be updated before Pickup_done/Dropoff_received | max length 17 |
| orders[].deliver_info.deliver_longitude / deliver_latitude | string | N, can be updated before Pickup/Dropoff | max length 16 |
| orders[].deliver_info.deliver_name / deliver_phone | string | N/Y, can be updated before Pickup/Dropoff | phone same PH format rule |
| orders[].deliver_info.deliver_detail_address / deliver_instruction | string | N, can be updated before Pickup/Dropoff | max length 256 |
| orders[].parcel_info.parcel_weight / parcel_item_name | — | Y, can be updated before Pickup_done/Dropoff_received | — |
| orders[].parcel_info.parcel_length / parcel_width / parcel_height / parcel_item_quantity | — | Y, can be updated before Pickup/Dropoff | — |
| orders[].parcel_info.express_insured_value | number | N | — |
| orders[].parcel_info.parcel_item_type | string | Y | enum same as create order |

Example request:
```json
{
    "user_id": 46345543648695,
    "user_secret": "4196c158-b7cd-41c3-a87a-058aef09ea20",
    "orders": [
        {
            "tracking_no": "SPEPH041655957558",
            "order_id": "GW123456789",
            "base_info": { "service_type": 1 },
            "sender_info": {
                "sender_state": "Metro Manila",
                "sender_city": "Metro Manila",
                "sender_district": "Binondo",
                "sender_street": "Barangay 294",
                "sender_post_code": "115",
                "sender_name": "sender test",
                "sender_phone": "639876543333",
                "sender_detail_address": "1"
            },
            "fulfillment_info": { "payment_role": 1, "cod_collection": 0, "collect_type": 2 },
            "deliver_info": {
                "deliver_state": "Metro Manila",
                "deliver_city": "Metro Manila",
                "deliver_district": "Intramuros",
                "deliver_street":"Barangay 658",
                "deliver_post_code": "123",
                "deliver_name": "Rain Buyer test",
                "deliver_phone": "639876543218",
                "deliver_detail_address": "我是一个测试",
                "deliver_instruction": "API Test"
            },
            "parcel_info": {
                "parcel_weight": 0.5,
                "parcel_length": 0,
                "parcel_width": 0,
                "parcel_height": 0,
                "parcel_item_name": "clothes",
                "parcel_item_quantity": 1,
                "express_insured_value": 100,
                "parcel_item_type": "Clothing"
            }
        }
    ]
}
```
(Note: harvested example is again missing a trailing comma after `express_insured_value` — fix before use.)

**Response:**

| Parameters | Type | Description |
|---|---|---|
| data.orders[].order_id | string | — |
| data.orders[].tracking_no | string | SPX tracking number |
| data.orders[].tracking_link | string | SPX tracking website link |
| data.orders[].r_first_sort_code / r_third_sort_code / return_first_sort_code | string | (optional, request in advance) |
| data.orders[].edt_max / edt_min | integer | Estimated Delivery Time range days |
| data.fail_list[].order_id | string | — |
| data.fail_list[].message | string | fail message |
| data.fail_list[].debug_msg | string | reason for failure |
| data.fail_list[].ret_code | integer | error code |

Example response:
```json
{
    "ret_code": 0,
    "message": "success. ",
    "data": {
        "orders": [
            {
                "order_id": "",
                "tracking_no": "SPEPH053855676287",
                "tracking_link": "https://uat.spx.ph/track?SPEPH053855676287",
                "r_first_sort_code": "B-003-MKT-bsc-GMA-6001",
                "r_third_sort_code": "",
                "return_first_sort_code": "B-003-MKT-bsc-GMA-6001",
                "edt_max": 2,
                "edt_min": 0
            }
        ],
        "fail_list": []
    }
}
```

---

### 1.8 Get voucher list

*(URL: https://spx.ph/integration/en/api/a522bcf1de30419e899085f984f57ec3)*

Create Time: 17/01/2025 · Last Updated: 20/01/2025

Lists vouchers that can be used at the account level.

**HTTP URL:** `{{host}}/open/api/v1/order/list_voucher`
**HTTP Method:** POST

**Request body:**

| Parameters | Type | Mandatory | Description |
|---|---|---|---|
| user_id | integer | Y | the user id to check |
| user_secret | string | Y | the user secret to check |

**Response:**

| Parameters | Type | Description |
|---|---|---|
| data.vouchers[].voucher_code | string | Voucher code |
| data.vouchers[].voucher_name | string | Voucher name |
| data.vouchers[].discount_by | int | 1: percentage, 2: fixed amount |
| data.vouchers[].voucher_amount | string | e.g. "10%" or "10000" |
| data.vouchers[].voucher_cap | string | For percentage voucher, the maximum voucher amount |
| data.vouchers[].min_spend | string | Min spend to apply the voucher |
| data.vouchers[].valid_start_time | int | unit: second |
| data.vouchers[].valid_end_time | int | unit: second |

Example response:
```json
{
    "ret_code": 0,
    "message": "success. ",
    "data": {
        "vouchers": [
            {
                "voucher_code": "test11111",
                "voucher_name": "test11111",
                "discount_by": 1,
                "voucher_amount": "10%",
                "voucher_cap": "100",
                "min_spend": "10000",
                "valid_start_time": 1723707688,
                "valid_end_time": 1725037200
            },
            {
                "voucher_code": "test12",
                "voucher_name": "test12",
                "discount_by": 2,
                "voucher_amount": "10",
                "voucher_cap": "0",
                "min_spend": "1000",
                "valid_start_time": 1723185866,
                "valid_end_time": 1723741200
            }
        ]
    }
}
```

---

## 2. Shipping Fee API

### 2.1 Check Shipping Fee

*(URL: https://spx.ph/integration/en/api/20c0dfeab4864bcb91f6672d60b3bfcc)*

Create Time: 08/06/2022 · Last Updated: 22/07/2025

Used for batch get estimated shipping fee **before** order creation. Note: at most 100 per batch.

**HTTP URL:** `{{host}}/open/api/v1/order/batch_check_order`
**HTTP Method:** POST

**Request body:** same `orders[]` shape as Create Order (base_info, sender_info, fulfillment_info, deliver_info, parcel_info) but with different mandatory flags — notably `cod_collection`, `collect_type` are optional here (N) vs required at create time, and `sender_street`/`deliver_street` have no explicit Y/N shown. `fulfillment_info.voucher_code` (N) can be supplied and is obtained from 1.8 Get voucher list. `parcel_info.express_insured_value` becomes mandatory when insurance_collection=1.

Example request:
```json
{
   "user_id": 180000240928183,
   "user_secret": "99325121-968a-45a0-a2a8-a38a6024b8bf",
   "orders": [
       {
           "base_info": { "service_type": 1 },
           "sender_info": {
               "sender_city": "Metro Manila",
               "sender_state": "Metro Manila",
               "sender_district": "Binondo",
               "sender_street": "Barangay 294",
               "sender_detail_address": "1",
               "sender_post_code": "115"
           },
           "fulfillment_info": {
               "cod_collection": 1,
               "cod_amount": 100,
               "collect_type": 2,
               "voucher_code": "NSS VOUCHER"
           },
           "deliver_info": {
               "deliver_state": "Metro Manila",
               "deliver_city": "Metro Manila",
               "deliver_district": "Intramuros",
               "deliver_street":"Barangay 658",
               "deliver_post_code": "123",
               "deliver_detail_address": "sdfsdf"
           },
           "parcel_info": {
               "parcel_weight": 0.5,
               "parcel_item_name": "Blue T-shirt",
               "parcel_item_quantity": 1,
               "parcel_length": 10,
               "parcel_width": 20,
               "parcel_height": 100,
               "express_insured_value": 0,
               "parcel_item_type": "Clothing"
           }
       }
   ]
}
```

**Response:**

| Parameters | Type | Description |
|---|---|---|
| data.orders[].order_id | string | — |
| data.orders[].estimated_shipping_fee | number | — |
| data.orders[].basic_shipping_fee | number | — |
| data.orders[].cod_service_fee | number | — |
| data.orders[].insurance_service_fee | number | — |
| data.orders[].vat_fee | number | — |
| data.orders[].estimated_distance | number | — |
| data.orders[].voucher_shipping_fee | number | — |
| data.orders[].edt_max / edt_min | integer | Estimated Delivery Time range days |
| data.fail_list[].ret_code | integer | error code |
| data.fail_list[].message | string | error message |
| data.fail_list[].debug_msg | string | message for debug |
| data.fail_list[].order_id | string | id of failed order |

Example response:
```json
{
    "ret_code": 0,
    "message": "success. ",
    "data": {
        "orders": [
            {
                "order_id": "GW123456789",
                "estimated_shipping_fee": 23.2,
                "basic_shipping_fee": 29,
                "cod_service_fee": 0,
                "insurance_service_fee": 0,
                "vat_fee": 0,
                "estimated_distance": 0,
                "voucher_shipping_fee": 0,
                "edt_max": 2,
                "edt_min": 0
            }
        ],
        "fail_list": [
            {
                "ret_code": 13050,
                "message": "Check Order Failed.  ",
                "debug_msg": "order xxx check failed",
                "order_id": "GW12345690"
            }
        ]
    }
}
```

---

### 2.2 Get Order Fee

*(URL: https://spx.ph/integration/en/api/30ec728d8cb34bf993c8f98bc5bab28b)*

Create Time: 08/06/2022 · Last Updated: 20/01/2025

Used for batch get **actual** shipping fee **after** order creation. Note: at most 100 per batch.

**HTTP URL:** `{{host}}/open/api/v1/order/batch_get_asf`
**HTTP Method:** POST

**Request body:**

| Parameters | Type | Mandatory | Description |
|---|---|---|---|
| user_id | long | Y | — |
| user_secret | string(128) | Y | — |
| tracking_no_list | list | Y | tracking numbers obtained from 1.2 Create order |

**Response:**

| Parameters | Type | Description |
|---|---|---|
| data.orders[].tracking_no | string | — |
| data.orders[].order_id | string | — |
| data.orders[].parcel_info.actual_weight | number | — |
| data.orders[].parcel_info.actual_length / actual_width / actual_height | number | unit: cm |
| data.orders[].parcel_info.chargeable_weight | number | unit: kg |
| data.orders[].parcel_info.insurance_collection | integer | 0: no, 1: yes |
| data.orders[].parcel_info.express_insured_value | number | — |
| data.orders[].parcel_info.cod_collection | integer | 0: no, 1: yes |
| data.orders[].parcel_info.cod_amount | number | — |
| data.orders[].fee_info.payment_status | integer | — |
| data.orders[].fee_info.payment_role | integer | 1: sender pay, 2: receiver pay |
| data.orders[].fee_info.actual_shipping_fee | number | — |
| data.orders[].fee_info.return_shipping_fee | number | — |
| data.orders[].fee_info.basic_shipping_fee | number | — |
| data.orders[].fee_info.insurance_service_fee | number | — |
| data.orders[].fee_info.cod_service_fee | number | — |
| data.orders[].fee_info.voucher_shipping_fee | number | — |
| data.orders[].deliver_info.actual_distance | number | — |
| data.fail_list[].ret_code | integer | — |
| data.fail_list[].message | string | error message |
| data.fail_list[].tracking_no | string | failed tracking number |

Example response:
```json
{
    "ret_code": 0,
    "message": "success.",
    "data": {
        "orders": [
            {
                "tracking_no": "SPXTH022083013592",
                "parcel_info": {
                    "actual_weight": 2,
                    "actual_length": 10,
                    "actual_width": 50,
                    "actual_height": 20,
                    "chargeable_weight": 0,
                    "insurance_collection": 0,
                    "express_insured_value": 0,
                    "cod_collection": 0,
                    "cod_amount": 0
                },
                "fee_info": {
                    "payment_status": 1,
                    "payment_role": 1,
                    "actual_shipping_fee": 0,
                    "return_shipping_fee": 0,
                    "basic_shipping_fee": 0,
                    "insurance_service_fee": 0,
                    "cod_service_fee": 0,
                    "voucher_shipping_fee": 0
                },
                "deliver_info": { "actual_distance": 0 }
            }
        ],
        "fail_list": [
            { "ret_code": 13151, "message": "Get Actual Shipping Fee Failed. ", "tracking_no": "SPXTH022083013593" }
        ]
    }
}
```

---

## 3. Account API

### 3.1 Create Account

*(URL: https://spx.ph/integration/en/api/bd465a011f1343f0ae9f93d5bc8e768f)*

Create Time: 08/06/2022 · Last Updated: 26/12/2024

Creates a new account by email or phone, to obtain `user_id`/`user_secret` used by all order-related APIs.

**HTTP URL:** `{{host}}/open/api/v1/account/create`
**HTTP Method:** POST

**Request body:**

| Parameters | Type | Mandatory | Description |
|---|---|---|---|
| phone | string | Y | PH: 11 digits starting with 0, or 12 digits starting with 63 |
| email | string | N | max length 64 |

Example request:
```json
{ "phone": "xxxxx", "email": "xxxxx" }
```

**Response:**

| Parameters | Type | Description |
|---|---|---|
| data.user_id | integer | user id |
| data.user_secret | string | secret corresponding to user_id |
| data.password | string | password related to account |

Example response:
```json
{
    "ret_code": 0,
    "message": "success.",
    "data": {
        "user_id": 110592440436149,
        "user_secret": "d32e4ddc-c979-44a2-bf21-789a80c551d5",
        "password": "dkjfdkjkdj"
    }
}
```

---

### 3.2 Check Account Credentials

*(URL: https://spx.ph/integration/en/api/5e095a35dce040ecb6b7a2d7ec1a5e42)*

Create Time: 08/06/2022 · Last Updated: 16/10/2024

Checks whether provided account credentials are valid.

**HTTP URL:** `{{host}}/open/api/v1/account/verify`
**HTTP Method:** POST

**Request body:**

| Parameters | Type | Mandatory | Description |
|---|---|---|---|
| user_id | integer | Y | the user id to check |
| user_secret | string | Y | the user secret to check |

**Response:**

| Parameters | Type | Description |
|---|---|---|
| data.match_result | boolean | matched: true / unmatched: false |

Example response:
```json
{ "ret_code": 0, "message": "success", "data": { "match_result": true } }
```

---

## 4. Web Hook

### 4.1 Tracking Web Hook

*(URL: https://spx.ph/integration/en/api/69dad3f9a5e94c6b8ff5c5ecea1f2492)*

Create Time: 08/06/2022 · Last Updated: 23/07/2025

Describes current order status. When connecting with the SPX Open Service platform, the access party registers a webhook URL. **When the order status changes, SPX sends an HTTP POST request to the registered URL, with JSON body containing the order status.**

**Web Hook URL configuration:** The access party provides an external public network URL as the webhook URL. When allocating `app-id`/`app-secret`, SPX binds this URL to the app and enables the webhook push.

**Payload parameters:**

| Parameter | Type | Description |
|---|---|---|
| status | string | current order status |
| status_code | string | order status code |
| message | string | description for current order status |
| tracking_no | string | express tracking number |
| tracking_link | string | tracking link to query order tracking |
| order_id | string | customer order id |
| order_id_link | string | order id link to query order tracking |
| id | string | the only ID for current request |
| timestamp | int | the timestamp when this status happened |
| actual_shipping_fee | float | current actual shipping fee |
| latest_chargeable_weight | float | latest calculated weight used for shipping fee |
| cod_amount | float | present if COD bill |
| actual_return_shipping_fee | float | latest shipping fee of return bill |
| latest_actual_weight | float | latest actual weight of parcel |
| latest_chargeable_parcel_length / width / height | float | latest parcel dimensions used to calculate shipping fee |
| proof_of_delivery_list | []string | ePOD list |
| driver_name | string | driver's first name |
| driver_phone_number | string | driver's phone number |
| edd_max / edd_min | integer | Estimated Delivery Date range |

**Note:** `actual_shipping_fee` is returned for all order statuses except `1001`, `6003`, and `7001` — refer to the latest one received. `actual_return_shipping_fee` is only returned on status code `6003`.

Example payloads:

Pending Pickup:
```json
{
    "status": "Pending Pickup",
    "status_code": "1001",
    "message": "xxx",
    "tracking_no": "SPXTH026817968592",
    "tracking_link": "https://test.spx.co.th/track?SPXTH026817968592",
    "order_id": "GW123456789",
    "order_id_link": "https://test.spx.co.th/track?GW123456789",
    "timestamp": 1639385434,
    "id": "d4dcfea1-5286-4921-8fd3-7ded133f8579"
}
```

In Transit:
```json
{
    "status": "In Transit",
    "status_code": "2001",
    "message": "xxx",
    "tracking_no": "SPXTH026817968592",
    "tracking_link": "https://test.spx.co.th/track?SPXTH026817968592",
    "order_id": "GW123456789",
    "order_id_link": "https://test.spx.co.th/track?GW123456789",
    "timestamp": 1639385434,
    "actual_shipping_fee": 17,
    "latest_chargeable_weight": 4.34,
    "cod_amount": 203,
    "id": "d4dcfea1-5286-4921-8fd3-7ded133f8579"
}
```

Pickup Fail:
```json
{
    "status": "Pickup Failed",
    "status_code": "5001",
    "message": "Pickup attempt was unsuccessful",
    "tracking_no": "SPXTH026817968592",
    "tracking_link": "https://test.spx.co.th/track?SPXTH026817968592",
    "order_id": "GW123456789",
    "order_id_link": "https://test.spx.co.th/track?GW123456789",
    "timestamp": 1639385434,
    "id": "d4dcfea1-5286-4921-8fd3-7ded133f8579"
}
```

**Web Hook authentication:** SPX digitally signs the parameters in the body with the key of the access party and provides it in the `check-sign` attribute of the request header. The signature generation process is the same as other API requests (see `guide.md` → API authentication guide → "Generates the signature content check-sign"). After receiving the request, the access party generates a signature and compares it with `check-sign` in the header for authentication. If matched, the request is confirmed to be from SPX.

**Web Hook response:** Return a `200` HTTP status code to indicate receipt of the webhook. **SPX sets a timeout period of 3 seconds** — return 200 promptly, before processing the request body. **Remark:** if there are repeatedly rejected or timeout webhook requests, SPX will stop sending webhook requests, which may result in loss of some order status updates.

---

### 4.2 Evidence Proofs Webhook

*(URL: https://spx.ph/integration/en/api/30b2e03359dd4175a45b88db280d427d)*

Create Time: 21/05/2025 · Last Updated: 24/02/2026

The EP (Evidence Proofs) webhook pushes delivery evidence proofs to third-party platforms — four types of courier operation records such as delivery signatures or pickup confirmations. When new evidence is generated or updated, SPX sends an HTTP POST with JSON body to the registered webhook URL.

**Web Hook URL Configuration:** same mechanism as 4.1 — bound to the app during `app-id`/`app-secret` configuration.

**Payload parameters:**

| Parameter | Type | Description |
|---|---|---|
| tracking_no | string | Unique parcel tracking number |
| customer_order_id | string | Customer order id |
| id | string | (no description captured) |
| epod_list | []string | List of URLs for Proof of Delivery (EPOD) |
| epop_list | []string | List of URLs for Proof of Pickup (EPOP) |
| epor_list | []string | List of URLs for Proof of Return (EPOR) |
| epooh_list | []string | List of URLs for Proof of Other Handling (EPOOH) |
| tracking_code_name | string | Tracking status code name |
| status | string | Current shipment status |
| status_code_name | string | (description missing/truncated in source — see note below) |

> Harvest note: the source page's field table ends abruptly at `status_code_name` with no description text before the "Web Hook Authentication" heading begins — this looks like an incomplete row in SPX's own documentation, not a harvesting artifact (verified via direct `innerText` extraction). No JSON example payload was provided on this page (unlike 4.1).

**Web Hook Authentication:** SPX signs the request body using the third-party platform's `app-secret`; the signature is provided in the `check-sign` header. Generation method is consistent with other SPX APIs (see API authentication guide). The third-party platform must validate the signature on receipt.

**Web Hook Response:** Same as 4.1 — return `200` within SPX's 3-second timeout, or SPX will stop sending webhook requests after repeated rejections/timeouts.

---

## 5. Address API

### 5.1 Get Address file download link

*(URL: https://spx.ph/integration/en/api/3c11b14db42f442aa2af0c5c9c9dd5fb)*

Create Time: 11/08/2022 · Last Updated: 31/08/2022

Obtain a link to the latest address files for download — an Excel file containing the latest standard address library, usable for populating address fields in Create Order.

**HTTP URL:** `{{host}}/open/api/address/get_address_download_url`
**HTTP Method:** POST

**Request body:** none required beyond the standard headers (`{}`).

**Response:**

| Parameters | Type | Description |
|---|---|---|
| data.address_download_url | string | The url to download the address file |

Example response:
```json
{
   "ret_code": 0,
   "message": "success. ",
   "data": { "address_download_url": "https://test.spx.co.th/downloads/resource/location_list/e1e33bdfcb12d7ef92deeb7f61efa4f6.xlsx" }
}
```
