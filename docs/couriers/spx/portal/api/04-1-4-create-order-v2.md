# 1.4 Create Order V2

- Source: https://spx.ph/integration/en/api/8c5233900b5b40b6af0348d13de172b1
- Create Time: 14/09/2022
- Last Updated: 20/05/2026
- Harvested: 2026-11-10

---

This API is used for batch creating order. once successful create order, it will return batch no of these orders.

Note: At most 100 per batch.

| Basic Info |  |
| --- | --- |
| HTTP URL | {{host}}/open/api/v2/order/batch_create_order |
| HTTP Method | POST |
| Basic request parameter | Content-Type: application/ JSON, UTF-8 encoding |

## Request :

### Request header:

| Parameters | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- |
| app-id | integer | Y | Issued by SPX Open platform, each APP is unique. |
| check-sign | string | Y | Used to verify the source security of requests. For details of how to generate, see API authentication description. |
| timestamp | integer | Y | The requested Unix timestamp, Unit: second |
| random-num | integer | Y | random numberData constraints:signed 64-bit integers |

### Request body:

### 

| Parameters |  |  |  | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- | --- | --- | --- |
| user_id |  |  |  | integer | Y | Unique user identification.Generated via 3.1 Create Account API or provided by open platform. |
| user_secret |  |  |  | string | Y | The user secret corresponding to the user id.Generated via 3.1 Create Account API or provided by open platform. |
| orders |  |  |  | list | Y | Order details. Data constraints: Maximum order quantity is 100 |
|  | order_id |  |  | string | N | Customer system order id. Data constraints: max length:32 |
|  | base_info |  |  |  |  |  |
|  |  | service_type |  | integer | Y | Service type Optional value： 1: standard service |
|  | sender_info |  |  |  |  |  |
|  |  | sender_state |  | string | Y | State name Data constraints: max length: 64 |
|  |  | sender_city |  | string | Y | City name Data constraints: max length: 64 |
|  |  | sender_district |  | string | Y | sender district For PH: Mandatory Data constraints: max length:64 |
|  |  | sender_street |  | string | Y | sender street For PH: Mandatory Data constraints: max length:64 |
|  |  | sender_post_code |  | string | N | sender post code For PH: Mandatory Data constraints: max length: 16 |
|  |  | sender_longitude |  | string | N | sender longitude Data constraints: max length:16 |
|  |  | sender_latitude |  | string | N | sender latitude Data constraints: max length:16 |
|  |  | sender_name |  | string | Y | sender name Data constraints: max length:64 |
|  |  | sender_phone |  | string | Y | PH:The Phone number for sender/receiver should have 11 digits starting with 0 or 12 digits starting with country code (63) |
|  |  | sender_detail_address |  | string | Y | sender detail address Data constraints: max length:256 |
|  | fulfillment_info |  |  | object |  |  |
|  |  | payment_role |  | integer | Y | payment role Optional value： 1: sender pay 2: receiver pay |
|  |  | cod_collection |  | integer | Y | To use COD or not Optional value：0: no1: yes |
|  |  | cod_amount |  | integer | N | COD amount, when cod_collection=1, this param is mandatory PH COD Max50,000 Peso |
|  |  | collect_type |  | integer | Y | collect type Optional value： 1: pickup 2: drop off |
|  |  | pickup_time |  | integer | N | Expected pickup time(timestamp), Unix timestamp format ** Note: Available pick up time can be obtained via “1.1 Get the available pick up time” **Mandatory only when “collection type” is “pick up”, otherwise this field is optional. |
|  |  | pickup_time_range_id |  | integer | N | Pick up time slot id. ** Note: Available pick up time can be obtained via “1.1 Get the available pick up time” **Mandatory only when “collection type” is “pick up”, otherwise this field is optional. |
|  |  | pickup_time_range |  | integer | N | Pick up time range. Example: "09:00 - 13:00" ** Note: Available pick up time can be obtained via “1.1 Get the available pick up time” **Mandatory only when “collection type” is “pick up”, otherwise this field is optional. |
|  |  | voucher_code |  | string | N | voucher code you want to apply for this order |
|  | deliver_info |  |  |  |  |  |
|  |  | deliver_state |  | string | Y | the deliver state name Data constraints: max length: 64 |
|  |  | deliver_city |  | string | Y | the deliver city name Data constraints:max length: 64 |
|  |  | deliver_district |  | string | Y | the deliver district name For PH: Mandatory Data constraints: max length:64 |
|  |  | deliver_street |  | string | Y | the deliver street name For PH: Mandatory Data constraints: max length:64 |
|  |  | deliver_post_code |  | string | N | the deliver post code For PH: Mandatory Data constraints: max length:16 |
|  |  | deliver_longitude |  | string | N | deliver longitude Data constraints: max length:16 |
|  |  | deliver_latitude |  | string | N | deliver latitude Data constraints: max length:16 |
|  |  | deliver_name |  | string | Y | the receiver’s name Data constraints: max length:64 |
|  |  | deliver_phone |  | string | Y | PH:The Phone number for sender/receiver should have 11 digits starting with 0 or 12 digits starting with country code (63) |
|  |  | deliver_detail_address |  | string | Y | the receiver’s detail address Data constraints: max length: 256 |
|  |  | deliver_instruction |  | string | N | receiver’s remark Data constraints: max length: 256 |
|  | parcel_info |  |  |  |  |  |
|  |  | parcel_weight |  | number | Y | parcel weight Unit: kg |
|  |  | parcel_length |  | number | Y | parcel length Unit: cm |
|  |  | parcel_width |  | number | Y | parcel width Unit: cm |
|  |  | parcel_height |  | number | Y | parcel height Unit: cm |
|  |  | parcel_item_name |  | string | Y | parcel item name Data constraints: max length: 64 |
|  |  | parcel_item_quantity |  | integer | N | Item quantity |
|  |  | express_insured_value |  | number | Y | This field is the input for Parcel value |
|  |  | parcel_item_type |  | string | Y | Parcel Item Type including: - Food and beverage - Clothing - Electronics - Medical - Groceries - General merchandise - Fragile |
|  |  | item_list |  |  |  |  |
|  |  |  | item_id | integer | N | if you already have an item, you can pass the item_id, otherwise we will auto generate an itme id in SPX system |
|  |  |  | item_name | string | Y | item name |
|  |  |  | item_weight | string | Y | item weight (unit: g) |
|  |  |  | item_price | string | Y | item price |
|  |  |  | item_picture | string | N | item picture |
|  |  |  | item_quantity | integer | Y | item quantity |
|  |  |  | item_category | string | Y | item category including: - Food and beverage - Clothing - Electronics - Medical - Groceries - General merchandise - Fragile |

Examples of Request Parameters: for 10 orders

```
{
    "user_id":46345543648695,
    "user_secret":"4196c158-b7cd-41c3-a87a-058aef09ea20",
    "orders": [
       {
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
               "cod_collection": 1, 
               "cod_amount": 100, 
               "collect_type": 2,
               "pickup_time":1753430400,
               "pickup_time_range_id": 1,
               "pickup_time_range": "09:00 - 13:00"
               "voucher_code":"Newseller1"

           },
            "deliver_info": {
                   "deliver_longitude":"",
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
               "express_insured_value": "150"
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
|  | batch_no |  | integer | Batch number of these orders |

Examples of Response Parameters:

```
{
    "ret_code": 0,
    "message": "success. ",
    "data": {
        "batch_no": 207982800897461
    }
}
```
