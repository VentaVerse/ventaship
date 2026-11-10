# 1.1 Get Pickup Timeslot

- Source: https://spx.ph/integration/en/api/c27c8517d55c4fea96bcb426cfcaf140
- Create Time: 08/06/2022
- Last Updated: 16/10/2024
- Harvested: 2026-11-10

---

This API is used for getting the available pickup time supported by the current service.

| Basic Info |  |
| --- | --- |
| HTTP URL | {{host}}/open/api/v1/order/get_pickup_time |
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

| Parameters | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- |
| user_id | integer | Y | Unique user identification. <br> Generated via 3.1 Create Account API or provided by open platform. |
| user_secret | string | Y | The user secret corresponding to the user id. <br> Generated via 3.1 Create Account API or provided by open platform. |
| service_type | integer | Y | The service type. <br> Optional value： <br> 1: standard service |

Examples of Request Parameters:

```
{
    "user_id": xxxxx,
    "user_secret":  "CSFyQ8_JST",
    "service_type": 1
}
```

## Response :

| Parameters |  |  | Type | Description |
| --- | --- | --- | --- | --- |
| ret_code |  |  | integer | Response codeData Type:unsigned 32-bit integers |
| message |  |  | string | Response message |
| data |  |  | object |  |
|  | date |  | string |  |
|  | pickup_time |  | string | date in timestamp format，can be used as request parameter in Create Order interface-->fulfillment_info-->pickup_timeData Type:unsigned 32-bit integers |
|  | slots |  | object | Available pickup time of the day |
|  |  | pickup_time_range_id | integer | The id corresponding to the pickup time period，can be used as request parameter in 1.2 Create Order request body-->fulfillment_info-->pickup_time_range_idData Type:unsigned 32-bit integers |
|  |  | pickup_time_range | string | The pickup time period string，can be used as request parameter in 1.2 Create Order request body-->fulfillment_info-->pickup_time_rangeData Type:string |

Examples of Response Parameters:

```
{
    "ret_code": 0,
    "message": "success.",
    "data": [
        {
            "date": "2022-02-17",
            "pickup_time": 1645088400,
            "slots": [
                {
                    "pickup_time_range_id": 0,
                    "pickup_time_range": "Now"
                },
                {
                    "pickup_time_range_id": 2,
                    "pickup_time_range": "2:00pm-4:00pm"
                },
                {
                    "pickup_time_range_id": 3,
                    "pickup_time_range": "4:00pm-6:00pm"
                },
                {
                    "pickup_time_range_id": 4,
                    "pickup_time_range": "6:00pm-8:00pm"
                }
            ]
        },
        {
            "date": "2022-02-18",
            "pickup_time": 1645174800,
            "slots": [
                {
                    "pickup_time_range_id": 1,
                    "pickup_time_range": "10:00am-12:00am"
                },
                {
                    "pickup_time_range_id": 2,
                    "pickup_time_range": "2:00pm-4:00pm"
                },
                {
                    "pickup_time_range_id": 3,
                    "pickup_time_range": "4:00pm-6:00pm"
                },
                {
                    "pickup_time_range_id": 4,
                    "pickup_time_range": "6:00pm-8:00pm"
                }
            ]
        }
    ]
}
```
