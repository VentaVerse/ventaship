# 3.1 Create Account

- Source: https://spx.ph/integration/en/api/bd465a011f1343f0ae9f93d5bc8e768f
- Create Time: 08/06/2022
- Last Updated: 26/12/2024
- Harvested: 2026-11-10

---

This API is used for create a new account by email or phone.

#### 

| Basic Info |  |
| --- | --- |
| HTTP URL | {{host}}/open/api/v1/account/create |
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
| phone | string | Y | Phone number related to account. <br> The Phone number for sender/receiver should have 11 digits starting with 0 or 12 digits starting with country code (63) |
| email | string | N | Email.Data constraints:max length: 64 |

Examples of Request Parameters:

```
{
    "phone": "xxxxx",
    "email": "xxxxx"
}
```

## Response :

| Parameters |  | Type | Description |
| --- | --- | --- | --- |
| ret_code |  | int | Response code |
| message |  | string | Response message |
| data |  | object |  |
|  | user_id | integer | user id |
|  | user_secret | string | The user secret corresponding to the user id. |
|  | password | string | password related to account |

Examples of Response Parameters:

```
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
