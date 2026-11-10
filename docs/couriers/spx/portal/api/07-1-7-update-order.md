# 1.7 Update Order

- Source: https://spx.ph/integration/en/api/faba81e9c71543bea5ad3b4425367432
- Create Time: 25/09/2023
- Last Updated: 22/07/2025
- Harvested: 2026-11-10

---

This API is used for batch updating order information. The maximum number of edits per order is 3

The AWB will need to be reprinted after order update

Note:

- At most 100 per batch.
- All info can only be update in "create" status

| Basic Info |  |
| --- | --- |
| HTTP URL | {{host}}/open/api/v1/order/batch_update_order |
| HTTP Method | POST |
| Basic request parameter | Content-Type: application/ JSON, UTF-8 encoding |

## Request :

### Request header:

| Parameters | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- |
| app-id | integer | Y | Issued by SPX Open platform, each APP is unique. |
| check-sign | string | Y | Used to verify the source security of requests. <br> For details of how to generate, see API authentication description. |
| timestamp | integer | Y | The requested Unix timestamp,  <br> Unit: second |
| random-num | integer | Y | random number <br> Data constraints: signed 64-bit integers |

### Request body:

| Parameters |  |  | Type | Mandatory (Y/N) | Description |  |
| --- | --- | --- | --- | --- | --- | --- |
| user_id |  |  | integer | Y | Unique user identification. Generated via 3.1 Create Account API or provided by open platform. |  |
| user_secret |  |  | string | Y | The user secret corresponding to the user id. Generated via 3.1 Create Account API or provided by open platform. |  |
| orders |  |  | list | Y | Order details. Data constraints: Maximum order quantity is 100 |  |
|  | order_id |  | string | N | Customer system order id. Data constraints: max length:32 |  |
|  | base_info |  |  |  | 7 |  |
|  |  | service_type | integer | Y | Service type Optional value： 1: standard service (Modification is not supported, the original value needs to be passed) |  |
|  | sender_info |  |  |  |  |  |
|  |  | sender_state | string | Y | State name Data constraints: max length:64 |  |
|  |  | sender_city | string | Y | City name Data constraints: max length:64 |  |
|  |  | sender_district | string | Y, Can be updated before  <br>  Pickup_done/ Dropoff_received | sender district |  |
|  |  | sender_street | string | Y, Can be updated before  <br>  Pickup_done/ Dropoff_received | sender district |  |
|  |  | sender_post_code | string | N, Can be updated before  <br>  Pickup_done/ Dropoff_received | sender post code, max length:17 |  |
|  |  | sender_longitude | string | N, Can be updated before Pickup/Dropoff | sender longitude Data constraints: max length:16 |  |
|  |  | sender_latitude | string | N, Can be updated before Pickup/Dropoff | sender latitude Data constraints: max length:16 |  |
|  |  | sender_name | string | Y, Can be updated before  <br>  Pickup_done/ Dropoff_received | sender name Data constraints: max length:64 |  |
|  |  | sender_phone | string | Y, Can be updated before  <br>  Pickup_done/ Dropoff_received | sender phone Data constraints <br> The Phone number for sender/receiver should have 11 digits starting with 0 or 12 digits starting with country code (63) |  |
|  |  | sender_detail_address | string | N, Can be updated before Pickup/Dropoff | sender detail address Data constraints: max length:256 |  |
|  | fulfillment_info |  | object |  |  |  |
|  |  | payment_role | integer | Y | payment role Optional value： 1: sender pay 2: receiver pay |  |
|  |  | cod_collection | integer | Y | To use COD or not Optional value： <br> no: 0 <br> yes:1 |  |
|  |  | cod_amount | number | N | COD amount, when cod_collection=1, this param is mandatoryCOD Amount  <br> PH Max: 50,000 Peso |  |
|  |  | collect_type | integer | Y | collect type Optional value： 1: pickup 2: drop off (Modification is not supported, the original value needs to be passed) |  |
|  |  | pickup_time | integer | Y, Can be updated before Pickup/Dropoff | Expected pickup time(timestamp). Mandatory only when “collection type” is “pick up”, otherwise this field is optional. Note: 1. Available pick up time can be obtained via “1.1 Get the available pick up time” 2. It's better to check “1.1 Get the available pick up time” again before creating order, in order to avoid create failed by "over pick up cutoff time" |  |
|  |  | pickup_time_range_id | integer | Y, Can be updated before Pickup/Dropoff | pick up time slot id. Mandatory only when “collection type” is “pick up”, otherwise this field is optional. Note: pick up time range id can be obtained via“1.1 Get the available pick up time” |  |
|  | deliver_info |  |  |  |  |  |
|  |  | deliver_state | string | Y, Can be updated before  <br>  Pickup_done/ Dropoff_received | the deliver state name Data constraints: max length: 64 |  |
|  |  | deliver_city | string | Y, Can be updated before  <br>  Pickup_done/ Dropoff_received | the deliver city name Data constraints: max length: 64 |  |
|  |  | deliver_district | string | Y, Can be updated before  <br>  Pickup_done/ Dropoff_received | the deliver city name |  |
|  |  | deliver_street | string | Y, Can be updated before  <br>  Pickup_done/ Dropoff_received | the deliver city name |  |
|  |  | deliver_post_code | string | N, Can be updated before  <br>  Pickup_done/ Dropoff_received | the deliver post code,  max length:17 |  |
|  |  | deliver_longitude | string | N, Can be updated before Pickup/Dropoff | deliver longitude Data constraints: max length:16 |  |
|  |  | deliver_latitude | string | N, Can be updated before Pickup/Dropoff | deliver latitude Data constraints: max length:16 |  |
|  |  | deliver_name | string | N, Can be updated before Pickup/Dropoff | the receiver’s name Data constraints: max length:64 |  |
|  |  | deliver_phone | string | Y, Can be updated before Pickup/Dropoff | the receiver’s phone number  <br> The Phone number for sender/receiver should have 11 digits starting with 0 or 12 digits starting with country code (63) |  |
|  |  | deliver_detail_address | string | N, Can be updated before Pickup/Dropoff | the receiver’s detail address Data constraints: max length: 256 |  |
|  |  | deliver_instruction | string | N, Can be updated before Pickup/Dropoff | receiver’s remark Data constraints: max length: 256 |  |
|  | parcel_info |  |  |  |  |  |
|  |  | parcel_weight | number | Y, Can be updated before  <br>  Pickup_done/ Dropoff_received | parcel weight Unit: kg Parcel weight limit |  |
|  |  | parcel_length | number | Y, Can be updated before Pickup/Dropoff | parcel length Unit: cm Parcel length limit |  |
|  |  | parcel_width | number | Y, Can be updated before Pickup/Dropoff | parcel width Unit: cm Parcel width limit |  |
|  |  | parcel_height | number | Y, Can be updated before Pickup/Dropoff | parcel height Unit: cm Parcel height limit |  |
|  |  | parcel_item_name | string | Y, Can be updated before  <br>  Pickup_done/ Dropoff_received | parcel item name Data constraints: max length: 64 |  |
|  | parcel_item_quantity | integer | Y, Can be updated before Pickup/Dropoff | Item quantity |  |  |
|  | express_insured_value | number | N | express insured value |  |  |
|  | parcel_item_type | string | Y | Parcel Item Type including: <br> - Food and beverage <br> - Clothing <br> - Electronics <br> - Medical <br> - Groceries <br> - General merchandise <br> - Fragile |  |  |

Examples of Request Parameters:

```
{
    "user_id": 46345543648695,
    "user_secret": "4196c158-b7cd-41c3-a87a-058aef09ea20",
    "orders": [
        {
            "tracking_no": "SPEPH041655957558",
            "order_id": "GW123456789",
            "base_info": {
                "service_type": 1
            },
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
                "cod_collection": 0,
                "collect_type": 2
            },
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
                "express_insured_value": 100
                "parcel_item_type": "Clothing"
            }
        }
    ]
}
```

## Response :

| Parameters |  |  | Type | Description |
| --- | --- | --- | --- | --- |
| ret_code |  |  | int | Response code |
| message |  |  | string | Response message |
| data |  |  | object |  |
|  | orders |  | list | Succeed orders list |
|  |  | order_id | string | Customer system order id |
|  |  | tracking_no | string | SPX tracking number |
|  |  | tracking_link | string | SPX tracking number SPX website link |
|  |  | r_first_sort_code | string | Delivery sort code  <br>  (Optional return. If you need this data, please request it from us in advance.) |
|  |  | r_third_sort_code | string | Delivery zone ID  <br> (Optional return. If you need this data, please request it from us in advance.) |
|  |  | return_first_sort_code | string | Return sort code  <br> (Optional return. If you need this data, please request it from us in advance.) |
|  |  | edt_max | integer | Estimated Delivery Time (EDT) represents a time range displayed before parcel pickup (during order creation) <br> Maximum days |
|  |  | edt_min | integer | Estimated Delivery Time (EDT) represents a time range displayed before parcel pickup (during order creation) <br> Minimum Days |
|  | fail_list |  | list | Failed orders list |
|  |  | order_id | string | Customer system order id |
|  |  | message | string | fail message |
|  |  | debug_msg | string | Specific reasons for failure |
|  |  | ret_code | integer | error code |

Examples of Response Parameters:

```
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
