# SPX (Shopee Xpress) Open API — Guide

Source: https://spx.ph/integration/en/guide/cef9f862490e49b796df946e8fb624bf
Harvested: 2026-08-08 (verbatim `document.querySelector('div.doc').innerText` extraction per section)

---

## Introduction

*(URL: https://spx.ph/integration/en/guide/cef9f862490e49b796df946e8fb624bf)*

Create Time: 08/06/2022 · Last Updated: 25/09/2023

### Overview

This manual is the official documentation for integration with Shopee Xpress (SPX) Open Service Open API. Third party systems (e.g. ERPs) or individual sellers can obtain access to Shopee Xpress's logistics services directly through SPX Open API. This platform provides online-documents which introduce the API interactions and integration process to help our partners access SPX Open API faster and more accurately.

### Integration process

*(Figure/diagram — not text-extractable; not reproduced here.)*

---

## API list

*(URL: https://spx.ph/integration/en/guide/67898740f15549f4a4524a23db339638)*

Create Time: 08/06/2022 · Last Updated: 17/10/2024

Click on "API" above to view the interface details or click here.

| API | Description | Calling relationships with other API |
|---|---|---|

**1. Order** (need the user-id and user-secret generated via 3.1 Create Account)

- **1.1 Get pickup timeslot** — Get the available pickup time supported by the current service. — `-`
- **1.2 Track Order** — Batch query details of orders (except information related to fees). — You need to call 1.4 Create Order V2 first to get the tracking number.
- **1.3 Cancel Order** — Batch cancel order. — You need to call 1.4 Create Order V2 first to get the tracking number.
- **1.4 Create Order V2** — Create order — `-`
- **1.5 Get Order Create Result V2** — Get create order result — You need to call 1.4 Create Order V2 first to get the tracking number.
- **1.6 Get AWB V2** — Batch get waybill label, a maximum of 30 orders can be requested at a time. — You need to call 1.4 Create Order V2 first to get the tracking number.
- **1.7 Update Order** — used for batch updating order information. — `-`

**2. Shipping fee** (need the user-id and user-secret generated via 3.1 Create Account)

- **2.1 Check Shipping Fee** — Batch get estimated shipping fee before order creation. At most 100 per batch. — `-`
- **2.2 Get Order Fee** — Batch get actual shipping fee after order creation — You need to call 1.2 Create Order first to get the tracking number.

**3. Account**

- **3.1 Create Account** — Create a new account by email or phone to generate user-id and user-secret. — The user-id and user-secret will be used for other APIs.
- **3.2 Check Account Credentials** — To check whether account credentials provided are valid — `-`

**4. Web Hook**

- **4.1 Tracking Web Hook** — When the order status changes, the SPX Open Service platform will send an HTTP Post request to the registered URL, and the parameters of the request are JSON data containing the order status. — `-`

**5. Address API**

- **5.1 Get Address file download link** — obtain link to the latest address files for download — `-`

> Note: numbering in the live API list (e.g. "1.7 Update Order" vs. the "Update Order Flowchart" appendix referencing "API 1.9 Update Order") is inconsistent between the Guide's API-list summary and the Appendix — the API reference tab (`api.md`) is authoritative for exact endpoint numbering.

---

## How to call the open API

*(URL: https://spx.ph/integration/en/guide/b10f5ca427aa44cd98678561a92ef500)*

Create Time: 08/06/2022 · Last Updated: 21/10/2024

### Pre-Development Notes

- Before using the API, you need to contact the platform staff to obtain the app-id and app-secret.
- Before calling the API, please ensure that the check-sign is generated and added to the header as required.
- When using the order-related interface, you need to use the create account interface to generate user_id and user_secret, or ask the platform staff to provide them.
- Check the error code table for code meaning and suggestions for troubleshooting.
- Check the Order status table before using 1.2 track order API, so that you can understand the order status in response body.
- Read Parameter enumeration mapping table to see the mapping between parameter values and what they actually mean.
- Read the API documentation carefully and provide parameters with the correct data type as required.
- Understand the relationship between the order of API calls.
- Use postman for API debugging. Call the correct interface address.

### API call process

As shown in the figure below (diagram not text-extractable), before calling the open API, you need to complete the following 2 preparatory tasks:

1. **Get unique identifier**: After confirming the access with the operation staff of our platform, we will provide each accessing party with a unique app-id and app-secret for subsequent API calls.
2. **Generate signature**: API calls require check-sign as the signature to be placed in the request header to confirm the origin of the request and to ensure security. Please refer to API authentication description for the acquisition and use of check-sign.

### API call method

All interfaces use the POST method, UTF-8 encoding, body in JSON format, request parameter Type Content-Type= Application/JSON, and headers to input app-id, check-sign, timestamp and random-num.

### General request header

When calling the interface, signature checks are required and parameters needed are passed through the request header. All the parameters showed below are needed in the request header of each API request.

| Parameters | Type | Mandatory (Y/N) | Description |
|---|---|---|---|
| app-id | integer | Y | Issued by SPX Open platform, each APP is unique. |
| check-sign | string | Y | Used to verify the source security of requests. For details of how to generate, see API authentication description. |
| timestamp | integer | Y | The requested Unix timestamp. Unit: second |
| random-num | integer | Y | random number. Data constraints: signed 64-bit integers |

---

## API authentication guide

*(URL: https://spx.ph/integration/en/guide/bdc52775bd044b5eab12342a99552b1b)*

Create Time: 08/06/2022 · Last Updated: 17/10/2024

### Get app-ID and app-Secret

The open platform assigns a unique set of app-id and app-secret to each accessed app.

Example:
```
app-id = 100000
app-secret = H25HY53GO4BA2GQ
```

### Generates the signature content check-sign

The following details are needed to generate check-sign:

- app-id, app-secret, timestamp (in seconds, type: int64), random-num (type: int64) — set in the request header
- payload in JSON format — set in the request body (need to be converted to a string before input to GenerateCheckSign)

The check-sign signature parameter is implemented via **HMAC-SHA256** algorithm. The process of user-generated check sign needs to be the same as the example below. The unit of timestamp needs to be second, not millisecond.

Example (Golang):

```go
package main

import (
    "bytes"
    "crypto/hmac"
    "crypto/sha256"
    "encoding/hex"
    "encoding/json"
    "fmt"
    "io/ioutil"
    "math/rand"
    "net/http"
    "strconv"
    "time"
)

const _checkSignFormatStr = "%d_%d_%d_%s"

type HttpReqData struct {
    UserId      uint64 `json:"user_id"`
    UserSecret  string `json:"user_secret"`
    ServiceType uint32 `json:"service_type"`
}

func main() {
    var appId = uint64(100000)
    var appSecret = "H25HY53GO4BA2GQ"
    var timestamp = time.Now().Unix()
    rand.Seed(timestamp)
    var randomNum = rand.Int63()
    reqData := &HttpReqData{
        UserId:      239404781503925,
        UserSecret:  "85f0d570-a265-4d9d-857e-b30aa57c4fbe",
        ServiceType: 1,
    }
    payload, err := json.Marshal(reqData)
    if err != nil {
        return
    }
    // Generate check sign
    checkSign, err := GenerateCheckSign(appId, appSecret, timestamp, randomNum, payload)
    if err != nil {
        return
    }
    fmt.Println(checkSign)

    // Do http request
    httpReq, err := http.NewRequest(http.MethodPost, "https://test-stable.spx.vn/open/api/v1/order/get_pickup_time", bytes.NewReader(payload))
    if err != nil {
        return
    }
    httpReq.Header.Set("app-id", strconv.FormatUint(appId, 10))
    httpReq.Header.Set("check-sign", checkSign)
    httpReq.Header.Set("timestamp", strconv.FormatInt(timestamp, 10))
    httpReq.Header.Set("random-num", strconv.FormatInt(randomNum, 10))
    httpResp, err := http.DefaultClient.Do(httpReq)
    if err != nil {
        return
    }
    defer httpResp.Body.Close()
    body, err := ioutil.ReadAll(httpResp.Body)
    if err != nil {
        return
    }
    print(string(body))
}

func GenerateCheckSign(appId uint64, secret string, timestamp, random int64, payload []byte) (string, error) {
    originalValue := fmt.Sprintf(_checkSignFormatStr, appId, timestamp, random, payload)
    h := hmac.New(sha256.New, []byte(secret))
    _, err := h.Write([]byte(originalValue))
    if err != nil {
        return "", err
    }
    checkSign := hex.EncodeToString(h.Sum(nil))
    return checkSign, nil
}
```

The doc also provides equivalent examples in Python3, Node.js, Java, PHP, C# (tabs were present in the UI but only the Golang tab's code was captured in the extracted text — the "Plain text / Shell / JSON" tabs held the test-data blocks below, not additional language code).

### Test your generate check-sign method

We have provided a set of test data and the expected correct results should be returned. Please use the data below to check that your own method of generating check-sign produces the same results.

Test data:

| parameter | data type | value |
|---|---|---|
| app-id | integer | 100000 |
| app-secret | string | H25HY53GO4BA2GQ |
| timestamp | integer | 1677918414 |
| random-num | integer | 926611981 |
| payload | string | see JSON below — keep content exactly the same, including format |

payload value:
```json
{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}
```

Expected result (string):
```
97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d
```

Note: If the results are not the same you can troubleshoot in the following ways:
- Check whether the data type is correct
- Check whether the HMAC-SHA256 algorithm is used.
- Check whether the format of the payload is consistent with that provided — line feeds and different spaces will lead to different generated results.
- If you are using Windows OS for development and testing, please refer to the Windows version note below.

### For Windows development & testing environment

When using Postman for interface testing, the request body includes newlines that are not explicitly displayed in addition to the visible characters entered by the user. The line break is parsed as `\n` on unix systems, and `\r\n` on windows systems. This results in the actual payload byte[] for the same request body having different content on different systems. In this case, the same encryption process must generate different check-sign.

So, the doc also provides the expected results of generating signatures using a windows system test. If your development environment is windows, or you are using a windows OS to test the check-sign algorithm, when your payload has line breaks and you copy the payload provided into Postman, the line breaks will be represented as `\r\n`. In this case, using the test data above, the result generated by the standard algorithm should be:

Expected result (string):
```
97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d
```

(Note: the harvested page text shows the identical hash value quoted for both the Unix and Windows expected-result callouts — this may be a documentation copy-paste artifact on SPX's side, not a harvesting error. If your own signing implementation produces the Unix-case hash but request bodies actually contain `\r\n` line endings, verify against SPX directly.)

If your results are not the same as either of the above expected results, follow SPX's guidelines to check and revise your algorithm again.

Note: In order to eliminate the problems caused by differences between different OS, inform SPX staff if you use Windows for development & testing and are generating signatures by copying data and pasting it into a Unix OS to generate the result (e.g. using an online site to generate a signature where line breaks correspond to `\n`, then pasting the signature result into Postman where the request body line break is `\r\n`) — this mismatch causes `check-sign verification failed` in the response. During the debugging/UAT stage with the platform, SPX will provide a compatible cross-platform solution; subsequently, for protocol uniformity and security, the live environment will only accept the unique check-sign that meets the specification.

---

## API address

*(URL: https://spx.ph/integration/en/guide/8a44b7238cea4c6388dcab202f5ac146)*

Create Time: 08/06/2022 · Last Updated: 17/10/2024

API addresses are differentiated according to region (CID) and live & non-live, with the following rules:

- **Non-live**: `https://test-stable.spx.{{CID}}/`
  (Example: `https://test-stable.spx.ph/`, `https://test-stable.spx.vn/`, `https://test-stable.spx.co.th/`, `https://test-stable.spx.sg/`, `https://test-stable.spx.co.id/`)
- **Live**: `https://spx.{{CID}}/`
  (Example: `https://spx.ph/`, `https://spx.vn/`, `https://spx.co.th/`, `https://spx.sg/`, `https://spx.co.id/`)

**NOTE:** Replace `{{host}}` in the http url with this address.

For Philippines (CID = `ph`):
- UAT/non-live base: `https://test-stable.spx.ph/`
- Live/production base: `https://spx.ph/`

---

## Get available addresses

*(URL: https://spx.ph/integration/en/guide/e25f379dc37c4c0781f150949b9eb6c6)*

Create Time: 11/08/2022 · Last Updated: 17/10/2024

In order to improve the efficiency and quality of the service, SPX provides an excel file containing all the standard addresses supported by the latest service range of Shopee Xpress. This data is mainly used in address fields in the **1.4 Create Order V2** API. It is recommended to use the address listed in the file as the input parameter for creating an order, so as to reduce the failure of placing an order caused by an address error or the service range of the selected address not being supported.

Through **5.1 Get Address file download link**, you can get the download link of the latest address library excel file in real time.

### How to use the address file?

In the create-order request body, you have to input the address info both in `sender_info` and `deliver_info`. The correspondence between the columns in the excel file and the fields for creating orders is as follows:

**PH Example file** — for PH: State / City / PostCode

| Data in excel | State | City | PostCode | Longitude | Latitude |
|---|---|---|---|---|---|
| API Corresponding Fields (string) | sender_state / deliver_state | sender_city / deliver_city | sender_city / deliver_city *(sic — see note)* | sender_longitude / deliver_longitude | sender_latitude / deliver_latitude |
| Mandatory (Y/N) | Y | Y | Y | N | N |

> Note: the harvested table text literally repeats `sender_city` / `deliver_city` for both the "City" and "PostCode" excel columns for PH. This looks like a documentation error/omission on SPX's side (PostCode likely should map to `sender_postcode`/`deliver_postcode` or similar) — verify the actual field name against the **1.4 Create Order V2** request schema in `api.md` / with SPX support before relying on it.

**TH Example file** — for TH: State / City / PostCode

| Data in excel | State | City | PostCode | Longitude | Latitude |
|---|---|---|---|---|---|
| Example row | จังหวัดขอนแก่น | อำเภออุบลรัตน์ | 40250 | 102.6676 | 16.7547 |
| API Corresponding Fields (string) | sender_state / deliver_state | sender_city / deliver_city | sender_post_code / deliver_post_code | sender_longitude / deliver_longitude | sender_latitude / deliver_latitude |
| Mandatory (Y/N) | Y | Y | Y | N | N |

**VN Example file** — for VN: State / District / Ward

| Data in excel | State | District | Ward | Longitude | Latitude |
|---|---|---|---|---|---|
| Example row | Nam Định | Huyện Trực Ninh | Xã Trực Tuấn | 106.2787 | 20.27511 |
| API Corresponding Fields (string) | sender_state / deliver_state | sender_city / deliver_city | sender_district / deliver_district | sender_longitude / deliver_longitude | sender_latitude / deliver_latitude |
| Mandatory (Y/N) | Y | Y | Y | N | N |

**ID Example file** — for ID: Province / City / District / PostCode

| Data in excel | Province | City | District | PostCode | Longitude | Latitude |
|---|---|---|---|---|---|---|
| Example row | JAWA TIMUR | KAB. MAGETAN | PANEKAN | 63352 | 111.305702 | -7.628713 |
| API Corresponding Fields (string) | sender_state / deliver_state | sender_city / deliver_city | sender_district / deliver_district | sender_post_code / deliver_post_code | sender_longitude / deliver_longitude | sender_latitude / deliver_latitude |
| Mandatory (Y/N) | Y | Y | Y | Y | N | N |

---

## Parameter enumeration mapping

*(URL: https://spx.ph/integration/en/guide/246c1bdbb1bd49a28c2e95832d2de637)*

Create Time: 08/06/2022 · Last Updated: 17/10/2024

| Parameters | Enumerated values | Type | Description |
|---|---|---|---|
| service_type | 1: standard service | int | Express service type |
| payment_role | 1: sender pay; 2: receiver pay | int | Payment role |
| cod_collection | 0: No; 1: Yes | int | Need COD or not |
| insurance_collection | 0: No; 1: Yes | int | Need insurance service or not |
| collect_type | 1: pickup; 2: drop off | int | collect type |

---

## Error codes mapping

*(URL: https://spx.ph/integration/en/guide/494f71b35fd5460a8c701c1de7da52c1)*

Create Time: 08/06/2022 · Last Updated: 08/01/2025

### Success

| code | type | remark |
|---|---|---|
| 0 | SuccessCode | Success |

### Internal system error [99000, 99999]

| error code | error type | Troubleshooting suggestions |
|---|---|---|
| 99001 | ServerErrorCode | Internal error, please try again later. If it still occurs you can consult customer service. |
| 99002 | DBReadErrorCode | Platform internal DB error, please try again later. If it still occurs you can consult customer service. |
| 99003 | NotFoundRecordCode | Platform internal error, record have not been found, please try again later. If it still occurs you can consult customer service. |
| 99004 | ServiceBusyCode | Platform internal service is busy, please try again later. If it still occurs you can consult customer service. |

### Requesting validity verification error [1000, 1999]

| error code | error type | Troubleshooting suggestions |
|---|---|---|
| 1001 | AppStatusErrorCode | Check the correctness of app-id and app-secret. If it still occurs you can consult customer service. |
| 1002 | RepeatRequestCode | The same request has already been sent, change the content of the request or send it again some time later. |
| 1003 | TimestampNotFoundCode | Check the timestamp in the header, the format is unix, and the unit needs to be seconds. |
| 1004 | RandomNumNotFoundCode | Check the random-num in the header, the data format needs to be a 64-bit integer. |
| 1005 | InvalidAppIdCode | Check that the app-id in the header is the same as the one provided by the platform. |
| 1006 | CheckSignNotFoundCode | Check if the check-sign parameter is added to the header. |
| 1007 | CheckSignVerificationFailedCode | Check that the check-sign in the header generates a legal value according to the steps in the guidelines. Refer to API authentication description → Generates the signature content check-sign. |
| 1008 | RequestBodyNotBeEmpty | The body of the request must not be empty. Please fill in the required parameters according to the request instructions in the API documentation. |
| 1009 | RequestExpiredCode | The time between the current time and the time represented by the timestamp in the request header is too long. It is recommended to update the timestamp in the header as well as the check-sign. |

### Parameter validity check [11000, 11999]

| error code | error type | Troubleshooting suggestions |
|---|---|---|
| 11001 | ParamErrorExternalCode | The requested parameters do not match the requirements. Please fill in the required parameters according to the request instructions in the API documentation. Example: `message=parcel weight can not be null`. Solution: fill in the value of `parcel_weight` in request. |

### User error [12000, 12999]

| error code | error type | Troubleshooting suggestions |
|---|---|---|
| 12001 | CreateAccountFailedCode | Check the correctness and legitimacy of the phone and email by referring to the returned message. |
| 12002 | EmailExistErrorCode | Check the correctness and legitimacy of the email. |
| 12003 | PhoneExistErrorCode | Check the correctness and legitimacy of the phone. |
| 12051 | VerifyAccountFailedCode | Please enter the legal user id and right password. |
| 12052 | AccountStatusAbnormalCode | Account status abnormal. Please contact admin. |

### order error [13000, 13999]

| error code | error type | remark |
|---|---|---|
| 13001 | CancelOrderFailedCode | Cancel Order Failed. |
| 13002 | OrderUnableCancelCode | Only those orders with pending pickup/dropoff status can be cancelled. |
| 13051 | CheckOrderFailedCode | Check Order Failed. |
| 13101 | CreateOrderFailedCode | Create Order Failed. |
| 13102 | GetOrderCreateResultFailedCode | Get Order Create Result Failed. |
| 13103 | OrderIdDuplicateFailedCode | The order id has been used already, please input the new one and submit the order again. |
| 13151 | GetActualShippingFeeFailedCode | Get Actual Shipping Fee Failed. |
| 13201 | GetShippingLabelFailedCode | Get Shipping Label Failed. |
| 13251 | SearchOrderFailedCode | Search Order Failed. |
| 13301 | GetPickupTimeFailedCode | Get Pickup Time Failed. |
| 13351 | BatchNoNotExistFailedCode | BatchNo Not Exist. |
| 13352 | TaskStatusIncorrectFailedCode | Task Status Incorrect. |

### Outer error code [10001, 10999]

| error code | error msg |
|---|---|
| 10001 | Weight exceeded maximum capacity |
| 10003 | COD Amount exceeded maximum supported value |
| 10004 | COD Amount is less than supported value |
| 10011 | There is no available service for your parcel |
| 10012 | You must provide a valid COD amount |
| 10013 | You must select a pickup timeslot |
| 10014 | Sorry, the pickup service is not supported by our service at the moment |
| 10015 | Sorry, the COD pickup service is not supported by our service at the moment |
| 10016 | Sorry, the delivery service is not supported by our service at the moment |
| 10017 | Sorry, the COD delivery service is not supported by our service at the moment |
| 10018 | Sorry, the pickup address you selected is not supported by our service at the moment |
| 10019 | Sorry, the delivery address you selected is not supported by our service at the moment |
| 10020 | Sorry, an unexpected error has occured for distance calculation |
| 10021 | Sorry, the distance between pickup and deliver exceeds the maximus limit |
| 10022 | Sorry, the distance between pickup and deliver less than the minimum limit |
| 10023 | Sorry, the address you selected is not supported by our route service at the moment |
| 10024 | Sorry, the pickup option you selected is not supported by our service at the moment |
| 10025 | Sorry, the pickup address you selected is not supported by our serviceable area at the moment |
| 10026 | Sorry, the delivery address you selected is not supported by our serviceable area at the moment |
| 10027 | The pickup address postal code you entered is invalid |
| 10028 | The receiver postal code you entered is invalid |
| 10029 | Sorry, the pickup address you selected does not support COD at the moment |
| 10030 | Sorry, the delivery address you selected does not support COD at the moment |
| 10031 | This pickup address postal code does not support COD |
| 10032 | This delivery address postal code does not support COD |
| 10033 | Sorry, the address you selected is not within the limits of the region |
| 10034 | Sorry, the pickup address is missing |
| 10035 | Sorry, the delivery address is missing |
| 10036 | Sorry, the delivery branch information is missing |
| 10037 | Sorry, the pickup information is invalid |
| 10038 | The pickup Date you selected is missing |
| 10039 | The pickup timeslot you selected is missing |
| 10040 | The pickup timeslot you selected is invalid |
| 10041 | The Longitude and latitude you selected is invalid |
| 10042 | Sorry, the payment you selected is not supported by our service at the moment |
| 10043 | Sorry, the COD amount you input is not supported by our service at the moment |
| 10044 | Sorry, an unexpected error of business type has occured |
| 10045 | Sorry, an unexpected error of service type has occured |
| 10049 | Failed to fetch AWB, please try again later |
| 10050 | Sorry, the item category is not supported by our service at the moment |
| 10051 | You must provide the receiver contact information |
| 10052 | Sorry, an unexpected error of WMS has occured |
| 10054 | Sorry, the pickup branch is not supported by our service at the moment |
| 10055 | Sorry, the delivery branch is not supported by our service at the moment |
| 10056 | Sorry, the pickup timeslot you selected is invalid at the moment |
| 10057 | Sorry, the actual service provider is invalid |
| 10058 | You cannot cancel this order |
| 10059 | Sorry, the delivery service you selected is not supported by our service at the moment |
| 10060 | Sorry, ESF calculation failed because of the TWS id is not exist |
| 10061 | Sorry, ESF calculation failed because of the shipping fee rate table failed to match |
| 10062 | Sorry, ESF calculation failed because of the address you selected does not match available route code |
| 10064 | Sorry, ESF calculation failed because of the weight you input is not within allocated range |
| 10065 | Sorry, the pickup and delivery address are not supported by our serviceable area at the moment, you can edit the address first |
| 10066 | Oops, the basic serviceable area does not config, can edit the address first and contact our ops. |
| 10067 | Oops, the ops serviceable area does not config, can edit the address first and contact our ops. |
| 10068 | Oops, the serviceable area does not found, can edit the address first and contact our ops. |
| 10069 | Oops, the serviceable area route does not found, can edit the address first and contact our ops. |
| 10070 | Oops, the pickup address does not found in basic serviceable area, can edit the address first and contact our ops. |
| 10071 | Oops, the delivery address does not found in basic serviceable area, can edit the address first and contact our ops. |
| 10074 | Oops, the pickup address does not found in ops serviceable area, can edit the address first and contact our ops. |
| 10075 | Oops, the delivery address does not found in ops serviceable area, can edit the address first and contact our ops. |
| 10076 | Sorry, the basic shipping fee Not Found. |
| 10077 | Sorry, calculate distance error, pickup latitude invalid. |
| 10078 | Sorry, calculate distance error, deliver latitude invalid. |
| 10079 | Sorry, check line way bill fail, invalid params, invalid deliver_info or deliver_name. |
| 10080 | Sorry, check line way bill fail, now_time over cutoff_time. |
| 10081 | Sorry, Open Logistics Lcos Client GetPickupDaysInfo fail, no pickup time is available with extend pickup date to escrow. |
| 10082 | Parcel info error, weight and volumetric weight less than min value. |
| 10083 | Parcel info error, weight and volumetric weight exceed max value. |
| 10084 | Parcel info error, length exceed max value. |
| 10085 | Parcel info error, width exceed max value. |
| 10086 | Parcel info error, height exceed max value. |

---

## Order status code

*(URL: https://spx.ph/integration/en/guide/e68727c5a34e49978b66f2c029181f2b)*

Create Time: 08/06/2022 · Last Updated: 15/04/2025

| Status Code | Status | Description |
|---|---|---|
| 1001 | Pending Pickup | Order creation is fully processed in our system, and the parcel is waiting to be picked up. |
| 2001 | In Transit | Parcel has been picked up / dropped off and in-transit for delivery. |
| 2006 | Delivering | Parcel is on the way to the receiver |
| 3001 | On Hold | Parcel has been placed on hold during delivery. |
| 4001 | Delivered | Parcel has been delivered. |
| 5001 | Pickup Failed | Parcel pickup attempt was unsuccessful. |
| 5002 | Damaged | Parcel has been damaged. |
| 5003 | Lost | Parcel has been lost. |
| 6001 | Returning | Parcel is returning to sender due to unsuccessful delivery attempts. |
| 6002 | Return Failed | Parcel return attempt was unsuccessful. |
| 6003 | Returned | Parcel has been returned to the sender. |
| 7001 | Cancelled | Pickup Request Cancelled |

---

## Update Order Flowchart

*(URL: https://spx.ph/integration/en/guide/7012e6a61b864a41af18e2ba15bcfb17)*

Create Time: 28/11/2023 · Last Updated: 17/10/2024

Flowchart of API 1.9 Update Order *(the flowchart itself is a rendered diagram/image — not text-extractable via innerText; not reproduced here)*.

### Condition:

1. The following information cannot be edited:
   1.1 Payment role
   1.2 Insurance selection (parameter: `insurance_collection`) and Express Insured Value (parameter: `express_insured_value`)
   1.3 Collect type (order collection type: pickup vs dropoff)
2. The maximum for editing order is 10 (combined with before and after pickup)
   2.1 Edit time: Before "Pickup_assigned" and "DOP_Received"

> Note: this section refers to the endpoint as "API 1.9 Update Order," while the API-list section above numbers it "1.7 Update Order." Treat `api.md`'s endpoint list as authoritative for the exact current numbering.

---

## Error-prone fields

*(URL: https://spx.ph/integration/en/guide/819534da135e4d80a3cf73918ecb5bf6)*

Create Time: 08/06/2022 · Last Updated: 17/10/2024

1. **tracking_no** — When the request parameter has `tracking_no_list` or `tracking_no`, first make sure that `tracking_no` is the value returned by the create order interface, not the `order_id` passed in by yourselves when creating the order. The tracking_no string usually starts with SPX or CID (VN/TH/SG/PH).
2. **tracking_no_list** — The following example puts 3 tracking_no in a string; the system will recognize it as one string, resulting in an order number error and the relevant interface call returning a failure.

   Wrong example:
   ```json
   "tracking_no_list": ["tracking_no_1,tracking_no_2,tracking_no_3"]
   ```
   Correct example:
   ```json
   "tracking_no_list": ["tracking_no_1","tracking_no_2","tracking_no_3"]
   ```
3. **Payment role** — If you input the wrong payment role value, it will cause the failure of order creation and checking shipping fee.
4. **COD amount** — When `cod_collection=1`, this param is mandatory and value must be greater than 0. Otherwise, it will cause the failure of order creation and checking shipping fee. Please reach out to the SPX team if you want to activate the COD service.
5. **Address** — Some areas do not support courier pickup or delivery services, which will result in the failure of your order-related operations.

---

## Suggestions of common errors

*(URL: https://spx.ph/integration/en/guide/5031855915f947c88c5192745572051d)*

Create Time: 14/09/2022 · Last Updated: 21/10/2024

SPX has counted the types of errors that fail when creating orders using the open api and provides a list of the 10 most frequent error codes and corresponding workarounds here. Some error codes not listed can be found in the Error codes mapping section above.

1. You can get the error code in response body `fail_list` → `ret_code` when fail in creating orders.
2. First find the corresponding error code and try to follow the solution suggestions and retry. If the error still occurs, contact SPX platform staff.

| Error code | Suggestions |
|---|---|
| 10080 | Check "1.1 Get the available pick up time" to obtain available pick up date and time then use for the fields `pickup_time` and `pickup_time_range_id`. Please use the latest returned results in case the time available for selection has elapsed. |
| 10077 | Please use the longitude and latitude related to the address which is provided in the Address file (via 5.1 Get Address file download link). Or you don't have to fill in the longitude and latitude, just input the address only. |
| 10078 | Same as above (10077). |
| 10021 | Please use the standard address provided in the Address file (via 5.1 Get Address file download link). |
| 10022 | Please check the address info in order creation request. The sender_address and deliver_address may be too close. |
| 10018 | Please use the standard address provided in the Address file. If the error still occurs, please contact us. |
| 10019 | Same as above (10018). |
| 10001 | Check the field `"parcel_weight": x` |
| 10003 | Check the field `"cod_amount": x` |
| 10084 / 10085 / 10086 | Check the fields `"parcel_height"`, `"parcel_width"`, `"parcel_length"` |
| 10083 | Check the field `"parcel_weight": x` |

### Related links and information

- How to obtain the standard address: get available address.
- Get the available pickup time slot: get pickup timeslot.
- Parcel limit:

| Parcel_info limit | PH | TH | VN |
|---|---|---|---|
| parcel_weight | < 50 kg | 0.001kg < weight < 20kg | 0kg < weight < 15kg |
| parcel_length | Max: 150 cm | Max: 100cm | Max: 60cm |
| parcel_width | Max: 150 cm | Max: 100cm | Max: 60cm |
| parcel_height | Max: 150 cm | Max: 100cm | Max: 60cm |
| cod_max_limit | Max: 50,000 Peso | Max: 36000 | Max: 20000000 |
