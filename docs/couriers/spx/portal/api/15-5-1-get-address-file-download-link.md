# 5.1 Get Address file download link

- Source: https://spx.ph/integration/en/api/3c11b14db42f442aa2af0c5c9c9dd5fb
- Create Time: 11/08/2022
- Last Updated: 31/08/2022
- Harvested: 2026-11-10

---

This interface allows you to obtain link to the latest address files for download. By visiting the link in the returned value, you can download the excel file containing the latest standard address library which can be used in creating orders.

#### 

| Basic Info |  |
| --- | --- |
| HTTP URL | {{host}}/open/api/address/get_address_download_url |
| HTTP Method | POST |
| Basic request parameter | Content-Type: application/ JSON, UTF-8 encoding |

## Request :

### Request header:

| Parameters | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- |
| app-id | integer | Y | Issued by SPX Open platform, each APP is unique. |
| check-sign | string | Y | Used to verify the source security of requests. <br> For details of how to generate, see API authentication description. |
| timestamp | integer | Y | The requested Unix timestamp, Unit: second |
| random-num | integer | Y | random number <br> Data constraints:signed 64-bit integers |

### Request body:

this interface do not need other request parameter, only the header is required.

Examples of Request Parameters:

```
{}
```

## Response :

| Parameters |  | Type | Description |
| --- | --- | --- | --- |
| ret_code |  | int | Response code |
| message |  | string | Response message |
| data |  | object |  |
|  | address_download_url | string | The url to download address file. |

Examples of Response Parameters:

```
{
   "ret_code": 0,
   "message": "success. ",
   "data": {
       "address_download_url": "https://test.spx.co.th/downloads/resource/location_list/e1e33bdfcb12d7ef92deeb7f61efa4f6.xlsx"
   }
}
```
