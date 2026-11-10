# 1.5 Get Order Create Result V2

- Source: https://spx.ph/integration/en/api/bc0e6b62beae429cacd80b70952f979b
- Create Time: 14/09/2022
- Last Updated: 20/01/2025
- Harvested: 2026-11-10

---

This API is used for get order create result when using the Create Order V2 api to create orders. once order create status is done, it will return tracking number.

Note: Only support one batch no.

| Basic Info |  |
| --- | --- |
| HTTP URL | {{host}}/open/api/v2/order/get_order_create_result |
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

| Parameters |  |  | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- | --- | --- |
| user_id |  |  | integer | Y | Unique user identification. <br> Generated via 3.1 Create Account API or provided by open platform. |
| user_secret |  |  | string | Y | The user secret corresponding to the user id. <br> Generated via 3.1 Create Account API or provided by open platform. |
| batch_no |  |  | integer | Y | Batch no of these orders |

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
| ret_code |  |  | int | Response code |
| message |  |  | string | Response message |
| data |  |  | object |  |
|  | batch_no |  | integer | Batch number of these orders |
|  | task_status |  | integer | Task status enumeration (1: New, 2: Waiting, 3: Running, 4: Done, 5: Failed) |
|  | description |  | string | Task status (English description) |
|  | total_count |  | integer | Total count of this batch orders |
|  | success_count |  | integer | Success count of this batch orders |
|  | fail_count |  | integer | Fail count of this batch orders |
|  | progress |  | string | Order creation progress, percentage |
|  | orders |  | list | Succeed orders list, when task status is done will show |
|  |  | order_id | string | Customer system order id |
|  |  | order_id_link | string | order id link to query order tracking |
|  |  | tracking_no | string | SPX tracking number |
|  |  | tracking_no_link | string | tracking no link to query order tracking |
|  |  | r_first_sort_code | string | Delivery sort code  (Optional return. If you need this data, please request it from us in advance.) |
|  |  | r_third_sort_code | string | Delivery zone ID (Optional return. If you need this data, please request it from us in advance.) |
|  |  | return_first_sort_code | string | Return sort code (Optional return. If you need this data, please request it from us in advance.) |
|  |  | estimated_shipping_fee | number | Estimated Shipping Fee |
|  |  | basic_shipping_fee | number | Basic Shipping Fee |
|  |  | cod_service_fee | number | COD Service Fee |
|  |  | cod_service_fee | number | cod service fee |
|  |  | insurance_service_fee | number | insurance service fee |
|  |  | vat_fee | number | Vat Fee |
|  |  | voucher_shipping_fee | number | voucher shipping fee |
|  | fail_list |  | list | Failed orders list, when task status is done will show |
|  |  | order_id | string | Customer system order id |
|  |  | message | string | fail message |
|  |  | debug_msg | string | Specific reasons for failure |
|  |  | ret_code | integer | error code |

Be careful: If all orders failed to be created, it is still done for the task status.

Examples of Response Parameters:

```
{
	"ret_code": 0,
	"message": "success. ",
	"data": {
		"batch_no": 207982800897461,
		"task_status": 4,
		"description": "Done",
		"total_count": 10,
		"success_count": 10,
		"fail_count": 0,
		"progress": "100.00%",
		"orders": [{
			"order_id": "1",
                        "order_id_link": "https://test.spx.co.th/track?1",
			"tracking_no": "SPXTH02460308936A",
                        "tracking_no_link": "https://test.spx.co.th/track?SPXTH02460308936A",
			"r_first_sort_code": "open-market-sort-1",
			"r_third_sort_code": "",
			"return_first_sort_code": "open-market-sort-2"，
                        "estimated_shipping_fee": 25,
                        "basic_shipping_fee": 25,
                        "cod_service_fee": 0,
                        "insurance_service_fee": 0,
                        "vat_fee": 0
                        "voucher_shipping_fee": 0,
                       
		}, {
			"order_id": "2",
                        "order_id_link": "https://test.spx.co.th/track?2",
			"tracking_no": "SPXTH02247120648A",
                        "tracking_no_link": "https://test.spx.co.th/track?SPXTH02247120648A",
			"r_first_sort_code": "open-market-sort-1",
			"r_third_sort_code": "",
			"return_first_sort_code": "open-market-sort-2"，
                        "estimated_shipping_fee": 25,
                        "basic_shipping_fee": 25,
                        "cod_service_fee": 0,
                        "insurance_service_fee": 0,
                        "vat_fee": 0
                        "voucher_shipping_fee": 0,                      
		}, {
			"order_id": "3",
                        "order_id_link": "https://test.spx.co.th/track?3",
			"tracking_no": "SPXTH02174281449A",
                        "tracking_no_link": "https://test.spx.co.th/track?SPXTH02174281449A",
			"r_first_sort_code": "open-market-sort-1",
			"r_third_sort_code": "",
			"return_first_sort_code": "open-market-sort-2"，
                        "estimated_shipping_fee": 25,
                        "basic_shipping_fee": 25,
                        "cod_service_fee": 0,
                        "insurance_service_fee": 0,
                        "vat_fee": 0
                        "voucher_shipping_fee": 0,
                  }, {
			"order_id": "4",
                        "order_id_link": "https://test.spx.co.th/track?4",
			"tracking_no": "SPXTH02578270408A",
                        "tracking_no_link": "https://test.spx.co.th/track?SPXTH02578270408A",
			"r_first_sort_code": "open-market-sort-1",
			"r_third_sort_code": "",
			"return_first_sort_code": "open-market-sort-2"，
                       "estimated_shipping_fee": 25,
                        "basic_shipping_fee": 25,
                        "cod_service_fee": 0,
                        "insurance_service_fee": 0,
                        "vat_fee": 0
                        "voucher_shipping_fee": 0,
                        "try_on_service_fee": 0,
                        "mutual_check_service_fee": 0,
                        "partial_delivery_service_fee": 0
		}, {
			"order_id": "5",
                        "order_id_link": "https://test.spx.co.th/track?5",
			"tracking_no": "SPXTH02758891114A",
                        "tracking_no_link": "https://test.spx.co.th/track?SPXTH02758891114A",
			"r_first_sort_code": "open-market-sort-1",
			"r_third_sort_code": "",
			"return_first_sort_code": "open-market-sort-2"，
                        "estimated_shipping_fee": 25,
                        "basic_shipping_fee": 25,
                        "cod_service_fee": 0,
                        "insurance_service_fee": 0,
                        "vat_fee": 0
                        "voucher_shipping_fee": 0,                        
		}, {
			"order_id": "6",
                        "order_id_link": "https://test.spx.co.th/track?6",
			"tracking_no": "SPXTH02227111908A",
                        "tracking_no_link": "https://test.spx.co.th/track?SPXTH02227111908A",
			"r_first_sort_code": "open-market-sort-1",
			"r_third_sort_code": "",
			"return_first_sort_code": "open-market-sort-2"，
                        "estimated_shipping_fee": 25,
                        "basic_shipping_fee": 25,
                        "cod_service_fee": 0,
                        "insurance_service_fee": 0,
                        "vat_fee": 0
                        "voucher_shipping_fee": 0,
                        "try_on_service_fee": 0,
                        "mutual_check_service_fee": 0,
                        "partial_delivery_service_fee": 0
		}, {
			"order_id": "7",
                        "order_id_link": "https://test.spx.co.th/track?7",
			"tracking_no": "SPXTH02928676067A",
                        "tracking_no_link": "https://test.spx.co.th/track?SPXTH02928676067A",
			"r_first_sort_code": "open-market-sort-1",
			"r_third_sort_code": "",
			"return_first_sort_code": "open-market-sort-2"，
                        "estimated_shipping_fee": 25,
                        "basic_shipping_fee": 25,
                        "cod_service_fee": 0,
                        "insurance_service_fee": 0,
                        "vat_fee": 0
                        "voucher_shipping_fee": 0,                       
		}, {
			"order_id": "8",
                        "order_id_link": "https://test.spx.co.th/track?8",
			"tracking_no": "SPXTH02020155349A",
                        "tracking_no_link": "https://test.spx.co.th/track?SPXTH02020155349A",
			"r_first_sort_code": "open-market-sort-1",
			"r_third_sort_code": "",
			"return_first_sort_code": "open-market-sort-2"，
                         "estimated_shipping_fee": 25,
                        "basic_shipping_fee": 25,
                        "cod_service_fee": 0,
                        "insurance_service_fee": 0,
                        "vat_fee": 0
                        "voucher_shipping_fee": 0,                       
		}, {
			"order_id": "9",
                        "order_id_link": "https://test.spx.co.th/track?9",
			"tracking_no": "SPXTH02671858152A",
                        "tracking_no_link": "https://test.spx.co.th/track?SPXTH02671858152A",
			"r_first_sort_code": "open-market-sort-1",
			"r_third_sort_code": "",
			"return_first_sort_code": "open-market-sort-2"，
                        "estimated_shipping_fee": 25,
                        "basic_shipping_fee": 25,
                        "cod_service_fee": 0,
                        "insurance_service_fee": 0,
                        "vat_fee": 0
                        "voucher_shipping_fee": 0,                       
		}, {
			"order_id": "10",
                        "order_id_link": "https://test.spx.co.th/track?10",
			"tracking_no": "SPXTH02767473618A",
                        "tracking_no_link": "https://test.spx.co.th/track?SPXTH02767473618A",
			"r_first_sort_code": "open-market-sort-1",
			"r_third_sort_code": "",
			"return_first_sort_code": "open-market-sort-2"，
                        "estimated_shipping_fee": 25,
                        "basic_shipping_fee": 25,
                        "cod_service_fee": 0,
                        "insurance_service_fee": 0,
                        "vat_fee": 0
                        "voucher_shipping_fee": 0,                       
		}],
		"fail_list": []
	}
}
```
