# 1.8 Get voucher list

- Source: https://spx.ph/integration/en/api/a522bcf1de30419e899085f984f57ec3
- Create Time: 17/01/2025
- Last Updated: 20/01/2025
- Harvested: 2026-11-10

---

## This API is used to list vouchers that can be used at the account level.

| Basic Info |  |
| --- | --- |
| HTTP URL | {{host}}/open/api/v1/order/list_voucher |
| HTTP Method | POST |
| Basic request parameter | Content-Type: application/ JSON, UTF-8 encoding |

## Request :

### Request header:

| Parameters | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- |
| app-id | integer | Y | Issued by SPX Open platform, each APP is unique. |
| check-sign | string | Y | Used to verify the source security of requests. For details of how to generate, see API authentication description. |
| timestamp | integer | Y | The requested Unix timestamp,  <br> Unit: second |
| random-num | integer | Y | random number |

### Request body:

| Parameters | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- |
| user_id | integer | Y | the user id to check |
| user_secret | string | Y | the user secret to check |

Examples of Request Parameters:

```
{
    "user_id": xxxxx,
    "user_secret": "xxxxx"
}
```

## Response :

| Parameters |  |  | Type | Description |
| --- | --- | --- | --- | --- |
| ret_code |  |  | int | Response code |
| message |  |  | string | Response message |
| data |  |  | object |  |
|  | vouchers |  | list | Voucher list |
|  |  | voucher_code | string | Voucher code |
|  |  | voucher_name | string | Voucher name |
|  |  | discount_by | int | 1: percentage 2: fixed amount |
|  |  | voucher_amount | string | Value of voucher E.g. 10% or 10000 |
|  |  | voucher_cap | string | For percentage voucher, the maximum voucher amount |
|  |  | min_spend | string | Min spend to apply the voucher |
|  |  | valid_start_time | int | Valid start time, unit: second |
|  |  | valid_end_time | int | Valid end time, unit: second |

Examples of Response Parameters:

```
{
	"ret_code": 0,
	"message": "success. ",
	"data": {
		"vouchers": [{
			"voucher_code": "test11111",
			"voucher_name": "test11111",
			"discount_by": 1,
			"voucher_amount": "10%",
			"voucher_cap": "100",
			"min_spend": "10000",
			"valid_start_time": 1723707688,
			"valid_end_time": 1725037200
		}, {
			"voucher_code": "test12",
			"voucher_name": "test12",
			"discount_by": 2,
			"voucher_amount": "10",
			"voucher_cap": "0",
			"min_spend": "1000",
			"valid_start_time": 1723185866,
			"valid_end_time": 1723741200
		}]
	}
}
```
