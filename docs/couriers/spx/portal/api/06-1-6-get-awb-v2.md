# 1.6 Get AWB V2

- Source: https://spx.ph/integration/en/api/1fa4ab78c1cf46478a0366a0b6b841fa
- Create Time: 14/09/2022
- Last Updated: 16/10/2024
- Harvested: 2026-11-10

---

This API is used for Batch get waybill label.

| Basic Info |  |
| --- | --- |
| HTTP URL | {{host}}/open/api/v2/order/batch_get_shipping_label |
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

| Parameters | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- |
| user_id | integer | Y | Unique user identification. <br> Generated via Create Account API or provided by open platform. |
| user_secret | string | Y | The user secret corresponding to the user id.  <br> Generated via the Create Account API or provided by open platform. |
| batch_no | integer | Y | Batch number of these orders,  obtained from 1.6 Create order V2 |

Be careful! You can only print orders that have not been cancelled. And the link is valid within half an hour, and can be opened up to 10 times.

Examples of Request Parameters:

```
{
    "user_id": 219216466249141,
    "user_secret": "704c0865-eee7-47bd-9a76-a3bb73d60e8b",
    "batch_no": 207982800897461
}
```

## Response :

| Parameters |  |  | Type | Description |
| --- | --- | --- | --- | --- |
| ret_code |  |  | integer | Response code |
| message |  |  | string | Response message |
| data |  |  | object |  |
|  | batch_no |  | integer | Batch number of these orders |
|  | task_status |  | integer | Task status enumeration (1: New, 2: Waiting, 3: Running, 4: Done, 5: Failed) |
|  | description |  | string | Task status (English description) |
|  | total_order_count |  | integer | Total count of this batch orders |
|  | total_print_count |  | integer | Total print count of this batch orders |
|  | success_print_count |  | integer | Success print count of this batch orders |
|  | progress |  | string | Print awb progress, percentage |
|  | awb_link |  | string | Waybill label’s link, when task status is done will show. |
|  | orders |  | list | Orders in awb link, be careful, order by create time desc |
|  |  | order_id | string | Order id in partner system |
|  |  | tracking_no | string | Tracking no in SPX system |

Examples of Response Parameters:

```
{
	"ret_code": 0,
	"message": "success. ",
	"data": {
		"batch_no": 207982800897461,
		"task_status": 4,
		"description": "Done",
		"total_order_count": 10,
		"total_print_count": 10,
		"success_print_count": 10,
		"progress": "100.00%",
		"awb_link": "https://test-stable.spx.co.th/downloads/resource/shipping_label/c9c0e35487ea08749c543455a0790b0d.pdf",
		"orders": [{
			"order_id": "10",
			"tracking_no": "SPEPH02767473618A"
		}, {
			"order_id": "9",
			"tracking_no": "SPEPH02671858152A"
		}, {
			"order_id": "8",
			"tracking_no": "SPEPH02020155349A"
		}, {
			"order_id": "7",
			"tracking_no": "SPEPH02928676067A"
		}, {
			"order_id": "6",
			"tracking_no": "SPEPH02227111908A"
		}, {
			"order_id": "5",
			"tracking_no": "SPEPH02758891114A"
		}, {
			"order_id": "4",
			"tracking_no": "SPEPH02578270408A"
		}, {
			"order_id": "3",
			"tracking_no": "SPEPH02174281449A"
		}, {
			"order_id": "2",
			"tracking_no": "SPEPH02247120648A"
		}, {
			"order_id": "1",
			"tracking_no": "SPXTH02460308936A"
		}]
	}
}
```
