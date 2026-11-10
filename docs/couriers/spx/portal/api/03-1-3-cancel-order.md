# 1.3 Cancel Order

- Source: https://spx.ph/integration/en/api/bb53ea49018e4575ae1e3872a537f7a9
- Create Time: 08/06/2022
- Last Updated: 16/10/2024
- Harvested: 2026-11-10

---

This API is used for batch cancel order which have been successfully created.

| Basic Info |  |
| --- | --- |
| HTTP URL | {{host}}/open/api/v1/order/batch_cancel_order |
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

| Parameters | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- |
| user_id | integer | Y | Unique user identification. <br> Generated via Create Account API or provided by open platform. |
| user_secret | string | Y | The user secret corresponding to the user id. <br> Generated via the Create Account API or provided by open platform. |
| tracking_no_list | list | Y | the tracking number ( string) obtained from 1.2 Create order |

Examples of Request Parameters:

```
{
    "user_id": 195216188605877,
    "user_secret": "6e1c50c0-93f2-4b66-affe-0c79f9dbe1a3",
    "tracking_no_list":["SPEPH041655957558"]
}
```

## Response :

| Parameters |  |  | Type | Description |
| --- | --- | --- | --- | --- |
| ret_code |  |  | int | Response code |
| message |  |  | string | Response message |
| data |  |  | object |  |
|  | tracking_no_list |  | list (string) | the successful canceled tracking number |
|  | fail_list |  | list | Failed cancel orders list |
|  |  | ret_code | int | error code |
|  |  | message | string | error message |
|  |  | tracking_no | string | the failed tracking number list |

Examples of Response Parameters:

```
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
