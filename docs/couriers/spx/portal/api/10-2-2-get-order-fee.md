# 2.2 Get Order Fee

- Source: https://spx.ph/integration/en/api/30ec728d8cb34bf993c8f98bc5bab28b
- Create Time: 08/06/2022
- Last Updated: 20/01/2025
- Harvested: 2026-11-10

---

This API is used for batch get actual shipping fee after order creation.

Note: At most 100 per batch.

| Basic Info |  |
| --- | --- |
| HTTP URL | {{host}}/open/api/v1/order/batch_get_asf |
| HTTP Method | POST |
| Basic request parameter | Content-Type: application/ JSON, UTF-8 encoding |

## Request :

### Request header:

| Parameters | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- |
| app-id | integer | Y | Issued by SPX Open platform, each APP is unique. |
| check-sign | string | Y | Used to verify the source security of requests.For details of how to generate, see API authentication description. |
| timestamp | integer | Y | The requested Unix timestamp,  <br> Unit: second |
| random-num | integer | Y | random number <br> Data constraints: <br> signed 64-bit integers |

### Request body:

| Parameters | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- |
| user_id | long | Y | Unique user identification.Generated via Create Account API or provided by open platform. |
| user_secret | string(128) | Y | The user secret corresponding to the user id.Generated via the Create Account API or provided by open platform. |
| tracking_no_list | list | Y | the list of tracking number ( string) obtained from 1.2 Create order |

Examples of Request Parameters:

```
{
    "user_id": 195216188605877,
    "user_secret": "6e1c50c0-93f2-4b66-affe-0c79f9dbe1a3",
    "tracking_no_list":["SPXTH022083013592"]
}
```

## Response :

| Parameters |  |  |  | Type | Description |
| --- | --- | --- | --- | --- | --- |
| ret_code |  |  |  | int | Response code |
| message |  |  |  | string | Response message |
| data |  |  |  | object |  |
|  | orders |  |  | list |  |
|  |  | tracking_no |  | string |  |
|  |  | order_id |  | string | order id |
|  |  | parcel_info |  |  | ActualParcelInfo |
|  |  |  | actual_weight | number |  |
|  |  |  | actual_length | number | actual parcel lengthUnit: cm |
|  |  |  | actual_width | number | actual parcel widthUnit: cm |
|  |  |  | actual_height | number | actual parcel heightUnit: cm |
|  |  |  | chargeable_weight | number | parcel chargeable weightUnit: kg |
|  |  |  | insurance_collection | integer | insurance collectionvalue：0: no1: yes |
|  |  |  | express_insured_value | number | express insured value |
|  |  |  | cod_collection | integer | To use COD or not value： <br> 0: no <br> 1: yes |
|  |  |  | cod_amount | number | cod amount |
|  |  | fee_info |  | object | Actual Fee Info |
|  |  |  | payment_status | integer |  |
|  |  |  | payment_role | integer | value:1: sender pay2: receiver pay； |
|  |  |  | actual_shipping_fee | number | actual shipping fee |
|  |  |  | return_shipping_fee | number | return shipping fee |
|  |  |  | basic_shipping_fee | number | basic shipping fee |
|  |  |  | insurance_service_fee | number | insurance service fee |
|  |  |  | cod_service_fee | number | cod service fee |
|  |  |  | voucher_shipping_fee | number | voucher shipping fee |
|  |  | deliver_info |  | object |  |
|  |  | actual_distance | number | actual distance |  |
|  | fail_list |  |  | list | get order fee failed list |
|  |  | ret_code |  | integer |  |
|  |  | message |  | string | error message |
| tracking_no |  | string | the list of failed  tracking number |  |  |

Examples of Response Parameters:

```
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
                    "cod_service_fee": 0
                    "voucher_shipping_fee": 0,
                },
                "deliver_info": {
                    "actual_distance": 0
                }
            }
        ],
        "fail_list": [{
                "ret_code": 13151,
                "message": "Get Actual Shipping Fee Failed. ",
                "tracking_no": "SPXTH022083013593"
            }
]
    }
}
```
