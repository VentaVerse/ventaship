# 1.2 Track Order

- Source: https://spx.ph/integration/en/api/4bd8b0f429154d8d8877b14fdd198b02
- Create Time: 08/06/2022
- Last Updated: 09/05/2026
- Harvested: 2026-11-10

---

This API is used for query details of orders (except information related to fees).

NOTE:

Order information returned by this API may require a short time to become fully available after order creation.

If this API is called immediately after Create Order succeeds, especially within 1 second, some newly created orders may temporarily return incomplete information, such as empty sort_code_info, or appear in fail_list with ret_code=13251, such as "order id cannot be found" or "tracking no cannot be found".

Please wait for a short period and retry, for example retry after 1-3 seconds with backoff. Do not use Track Order immediately after Create Order as a strong consistency validation.

| Basic Info |  |
| --- | --- |
| HTTP URL | {{host}}/open/api/v1/order/batch_search_order |
| HTTP Method | POST |
| Basic request parameter | Content-Type: application/ JSON, UTF-8 encoding |

## Request :

### Request header:

| Parameters | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- |
| app-id | integer | Y | Issued by SPX Open platform, each APP is unique. |
| check-sign | string | Y | Used to verify the source security of requests. For details of how to generate, see API authentication description. |
| timestamp | integer | Y | The requested Unix timestamp,  <br> Unit: second |
| random-num | integer | Y | random number <br> Data constraints: <br> signed 64-bit integers |

### Request body:

### 

| Parameters | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- |
| user_id | integer | Y | Unique user identification.Generated via Create Account API or provided by open platform. |
| user_secret | string | Y | The user secret corresponding to the user id.Generated via the Create Account API or provided by open platform. |
| tracking_no_list | list | Y | the list of tracking number ( string) obtained from 1.2 Create order. |

Examples of Request Parameters:

```
{
    "user_id":46345543648695,
    "user_secret":"4196c158-b7cd-41c3-a87a-058aef09ea20",
    "tracking_no_list":["SPEPH041655957558", "SPEPH047082279439"]
}
```

## Response :

| Parameters |  |  |  | Type | Description |
| --- | --- | --- | --- | --- | --- |
| ret_code |  |  |  | int | Response code |
| message |  |  |  | string | Response message |
| data |  |  |  | list | Order detail information |
|  | orders |  |  |  |  |
|  |  | tracking_no |  | string | tracking number |
|  |  | status |  | string | Current parcel status |
|  |  | status_code |  | string | Current parcel status code |
|  |  | base_info |  | object |  |
|  |  |  | service_type | integer | service type1: standard service |
|  |  | sender_info |  |  |  |
|  |  |  | sender_state | string | State name |
|  |  |  | sender_city | string | City name |
|  |  |  | sender_district | string | sender district |
|  |  |  | sender_street | string | sender street |
|  |  |  | sender_post_code | string | sender post code |
|  |  |  | sender_longitude | string | sender longitude |
|  |  |  | sender_latitude | string | sender latitude |
|  |  |  | sender_name | string | sender name |
|  |  |  | sender_phone | string | sender phone |
|  |  |  | sender_detail_address | string | sender detail address |
|  |  | fulfillment_info |  |  |  |
|  |  |  | payment_role | integer | payment role:1: sender pay2: receiver pay |
|  |  |  | cod_collection | integer | To use COD or not Optional value：0: no1: yes |
|  |  |  | cod_amount | integer | COD amount, when cod_collection=1, this param is mandatory <br> PH COD Max50,000 Peso |
|  |  |  | collect_type | integer | pickup mode:1:pickup；2:drop off； |
|  |  |  | pickup_time | interger | Scheduled pickup time |
|  |  |  | pickup_time_range_id | interger | Sequence number. |
|  |  |  | deliver_type | interger | deliver type |
|  |  |  | voucher_code | string | voucher code applied for this order |
|  |  | deliver_info |  |  |  |
|  |  |  | deliver_state | string | State name |
|  |  |  | deliver_city | string | City name |
|  |  |  | deliver_district | string | deliver district |
|  |  |  | deliver_street | string | deliver street |
|  |  |  | deliver_post_code | string | dliver post code |
|  |  |  | deliver_longitude | string | deliver longitude |
|  |  |  | deliver_latitude | string | deliver latitude |
|  |  |  | deliver_name | string | deliver name |
|  |  |  | deliver_phone | string | deliver phone |
|  |  |  | deliver_detail_address | string | deliver detail address |
|  |  |  | deliver_instruction | string | remark |
|  |  | parcel_info |  |  |  |
|  |  |  | parcel_weight | number | Unit: kg |
|  |  |  | parcel_length | number | Unit: cm |
|  |  |  | parcel_width | number | Unit: cm |
|  |  |  | parcel_height | number | Unit: cm |
|  |  |  | parcel_item_name | string | item name |
|  |  |  | parcel_item_quantity | integer | Item Quantity |
|  |  |  | express_insured_value | number | express insured value |
|  |  |  | parcel_item_type | string | Parcel Item Type including: <br> - Food and beverage <br> - Clothing <br> - Electronics <br> - Medical <br> - Groceries <br> - General merchandise <br> - Fragile |
|  |  | routes |  | list |  |
|  |  |  | status | string | route status |
|  |  |  | status_code | string | route status code |
|  |  |  | message | string | route message |
|  |  |  | timestamp | integer | route timestamp |
|  |  | edd_info |  |  |  |
|  |  |  | edd_min | integer | Estimated Delivery Time (EDT) represents a time range displayed before parcel pickup (during order creation) <br> Minimum Days |
|  |  |  | edd_max | integer | Estimated Delivery Time (EDT) represents a time range displayed before parcel pickup (during order creation) <br> Maximum days |
|  | fail_list |  |  |  | Failed Track orders list |
|  |  | ret_code |  | integer | error code |
|  |  | message |  | string | error message |
|  |  | tracking_no |  | string | Failed tracking number |

Examples of Response Parameters:

```
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
                "base_info": {
                    "service_type": 1,
                    "product_id": null
                },
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
                "edd_info": {
                    "edd_min": 0,
                    "edd_max": 0
                }
            }
        ],
        "fail_list": []
    }
}
```
