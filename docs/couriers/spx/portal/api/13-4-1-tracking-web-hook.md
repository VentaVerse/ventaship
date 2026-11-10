# 4.1 Tracking Web Hook

- Source: https://spx.ph/integration/en/api/69dad3f9a5e94c6b8ff5c5ecea1f2492
- Create Time: 08/06/2022
- Last Updated: 23/07/2025
- Harvested: 2026-11-10

---

description for current order status when connecting with the SPX Open Service platform, the access party can register a Webhook URL. When the order status changes, the SPX Open Service platform will send an HTTP Post request to the registered URL, and the parameters of the request are JSON data containing the order status.

## Web Hook Url configuration

The access party needs to provide an external public network URL as the Web hook URL. When allocating app-id and app-secret, we will bind this URL to the APP and enable the Web hook push.

## Web Hook parameter description

| Parameter | Type | Description |
| --- | --- | --- |
| status | string | current order status |
| status_code | string | order status code |
| message | string | description for current order status |
| tracking_no | string | express tracking number |
| tracking_link | string | tracking link to query order tracking |
| order_id | string | customer order id |
| order_id_link | string | order id link to query order tracking |
| id | string | the only ID for current request |
| timestamp | int | the timestamp when this status happened |
| actual_shipping_fee | float | the current actual shipping fee |
| latest_chargeable_weight | float | latest calculates the weight of shipping fee |
| cod_amount | float | if COD bill, cod_amount field will have a value |
| actual_return_shipping_fee | float | latest shipping fee of return bill |
| latest_actual_weight | float | latest actual weight of parcel |
| latest_chargeable_parcel_length | float | latest parcel length used to calculate shipping fee |
| latest_chargeable_parcel_width | float | latest parcel width used to calculate shipping fee |
| latest_chargeable_parcel_height | float | latest parcel height used to calculate shipping fee |
| proof_of_delivery_list | []string | epod list |
| driver_name | string | the name of the driver's first name |
| driver_phone_number | string | the phone number of the driver |
| edd_max | integer | Max of Estimated Delivery Date |
| edd_min | integer | Min of Estimated Delivery Date |

Note: actual_shipping_fee will be returned except order status 1001, 6003 and 7001, you can refer to the latest one you received. For actual_return_shipping_fee will only return value on status code 6003.

## Examples

Here are some examples of JSON data for order status.

### Pending Pickup

```
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

### 

### In Transit

```
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

### 

### Pickup Fail

```
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

## Web Hook authentication

SPX will digitally sign the parameters in the body with the key of the access party and provide in the check-sign attribute of the request header. The signature generation process will be the same as other API requests (refer to the Guide >API authentication > Generates the signature content check-sign in Introduction) . After receiving the request, the access party can generate signature and compare it with the check-sign in the header for authentication. If matched, it is determined that the request was sent by SPX.

## Web Hook response

You will need to return 200 HTTP status code to indicate that you have received a Web hook. SPX will set a timeout period of 3 seconds. It is recommended to return 200 responses after receiving a Web hook request before processing the request.

Remark：If there are repeatedly rejected or timeout Web hook requests, the sending of Web hook requests will stop, which may result in the loss of some order status.
