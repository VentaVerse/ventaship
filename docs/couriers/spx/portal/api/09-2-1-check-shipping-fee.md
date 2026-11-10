# 2.1 Check Shipping Fee

- Source: https://spx.ph/integration/en/api/20c0dfeab4864bcb91f6672d60b3bfcc
- Create Time: 08/06/2022
- Last Updated: 22/07/2025
- Harvested: 2026-11-10

---

{

"user_id": 180000240928183,

"user_secret": "99325121-968a-45a0-a2a8-a38a6024b8bf",

"orders": [

{

"base_info": {

"service_type": 1

},

"sender_info": {

"sender_city": "Metro Manila",

"sender_state": "Metro Manila",

"sender_district": "Binondo",

"sender_street": "Barangay 294",

"sender_detail_address": "1",

"sender_post_code": "115"

},

"fulfillment_info": {

"cod_collection": 0,

"collect_type": 2

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

"parcel_item_name": "",

"parcel_item_quantity": 1,

"parcel_length": 0,

"parcel_width": 0,

"parcel_height": 0,

"express_insured_value": 0

}

}This API is used for batch get estimated shipping fee before order creation.

Note: At most 100 per batch.

| Basic Info |  |
| --- | --- |
| HTTP URL | {{host}}/open/api/v1/order/batch_check_order |
| HTTP Method | POST |
| Basic request parameter | Content-Type: application/ JSON, UTF-8 encoding |

## Request :

### Request header:

| Parameters | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- |
| app-id | integer | Y | Issued by SPX Open platform, each APP is unique. |
| check-sign | string | Y | Used to verify the source security of requests. For details of how to generate, see API authentication description. |
| timestamp | integer | Y | The requested Unix timestamp,  <br> Unit: second |
| random-num | integer | Y | random number <br> Data constraints:signed 64-bit integers |

### Request body:

| Parameters |  |  | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- | --- | --- |
| user_id |  |  | integer | Y | Unique user identification.Generated via Create Account API or provided by open platform. |
| user_secret |  |  | string | Y | The user secret corresponding to the user id.Generated via the Create Account API or provided by open platform. |
| orders |  |  | list | Y | Order detail information |
|  | order_id |  | string | N | the id related to an order. |
|  | base_info |  |  |  |  |
|  |  | service_type | integer | Y | service type  <br> Optional value： <br> 1: standard service |
|  | sender_info |  |  |  |  |
|  |  | sender_state | string | Y | State name <br> Data constraints: <br> max length:64 |
|  |  | sender_city | string | Y | City name <br> Data constraints: <br> max length:64 |
|  |  | sender_district | string | Y | sender district <br> Data constraints: <br> max length:64 |
|  |  | sender_street | string | Y |  |
|  |  | sender_post_code | string | N | sender post code <br> Data constraints: <br> max length: 17 |
|  |  | sender_longitude | string | N | sender longitude <br> Data constraints: <br> max length:16 |
|  |  | sender_latitude | string | N | sender latitude <br> Data constraints: <br> max length:16 |
|  |  | sender_detail_address | string | N | sender detail address <br> Data constraints: <br> max length:256 |
|  | fulfillment_info |  |  |  |  |
|  |  | cod_collection | integer | N | To use COD or not  <br> Optional value： <br> 0: no <br> 1: yes |
|  |  | cod_amount | number | N | COD amount, when cod_collection=1, this param is mandatory <br> PH Max: 50,000 Peso |
|  |  | collect_type | integer | N | collect type <br> Optional value： <br> 1: pickup <br> 2: drop off |
|  |  | voucher_code | string | N | voucher code you want to apply for this order <br> Voucher code is obtained from API 1.8 Get voucher list |
|  | deliver_info |  |  |  |  |
|  |  | deliver_state | string | Y | the deliver state name <br> Data constraints: <br> max length: 64 |
|  |  | deliver_city | string | Y | the deliver city name <br> Data constraints: <br> max length: 64 |
|  |  | deliver_district | string | Y | the deliver city name <br> Data constraints: <br> max length:64 |
|  |  | deliver_street | string | Y |  |
|  |  | deliver_post_code | string | N | the deliver post code <br> Data constraints: <br> max length:17 |
|  |  | deliver_longitude | string | N | deliver longitude <br> Data constraints: <br> max length:16 |
|  |  | deliver_latitude | string | N | deliver latitude <br> Data constraints: <br> max length:16 |
|  |  | deliver_detail_address | string | Y | the receiver’s detail address <br> Data constraints: <br> max length: 256 |
|  | parcel_info |  |  |  |  |
|  |  | parcel_weight | number | Y | parcel weight <br> Unit: kg |
|  |  | parcel_length | number | Y | parcel length <br> Unit: cm |
|  |  | parcel_width | number | Y | parcel width <br> Unit: cm |
|  |  | parcel_height | number | Y | parcel height <br> Unit: cm |
|  |  | parcel_item_name | string | N | parcel item name <br> Data constraints: <br> max length: 64 |
|  |  | parcel_item_quantity | integer | N | Item quantity |
|  |  | express_insured_value | number | N | express insured value <br> Note:  <br> when “insurance_collection” value is “1(Yes)”, this field is mandatory. |
|  |  | parcel_item_type | string | Y | Parcel Item Type including: <br> - Food and beverage <br> - Clothing <br> - Electronics <br> - Medical <br> - Groceries <br> - General merchandise <br> - Fragile |

Examples of Request Parameters:

```
{
   "user_id": 180000240928183,
   "user_secret": "99325121-968a-45a0-a2a8-a38a6024b8bf",
   "orders": [
       {
           "base_info": {
               "service_type": 1
           },
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
               "cod_amount":100,
               "collect_type": 2
               "voucher_code":"NSS VOUCHER"
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
               "express_insured_value": 0
               "parcel_item_type": "Clothing"
           }
       }
```

## Response :

| Parameters |  |  | Type | Description |
| --- | --- | --- | --- | --- |
| ret_code |  |  | integer | Response code |
| message |  |  | string | Response message |
| data |  |  | object |  |
|  | orders |  | list |  |
|  |  | order_id | string | the id related to an order. |
|  |  | estimated_shipping_fee | number | Estimated Shipping Fee |
|  |  | basic_shipping_fee | number | Basic Shipping Fee |
|  |  | cod_service_fee | number | COD Service Fee |
|  |  | insurance_service_fee | number | Insurance Service Fee |
|  |  | vat_fee | number | Vat Fee |
|  |  | estimated_distance | number | Estimated Distance |
|  |  | voucher_shipping_fee | number | Voucher Shipping Fee |
|  |  | edt_max | integer | Estimated Delivery Time (EDT) represents a time range displayed before parcel pickup (during order creation) <br> Maximum days |
|  |  | edt_min | integer | Estimated Delivery Time (EDT) represents a time range displayed before parcel pickup (during order creation) <br> Minimum Days |
|  | fail_list |  |  |  |
|  |  | ret_code | integer | error code |
|  |  | message | string | error message |
|  |  | debug_msg | string | message for debug |
|  |  | order_id | string | the id related to failed order. |

Examples of Response Parameters:

```
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
                "estimated_distance": 0
                "voucher_shipping_fee": 0
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
