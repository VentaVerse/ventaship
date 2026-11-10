# Error codes mapping

- Source: https://spx.ph/integration/en/guide/494f71b35fd5460a8c701c1de7da52c1
- Create Time: 08/06/2022
- Last Updated: 08/01/2025
- Harvested: 2026-11-10

---

## Success

| code | type | remark |
| --- | --- | --- |
| 0 | SuccessCode | Success |

## Internal system error [99000, 99999]

| error code | error type | Troubleshooting suggestions |
| --- | --- | --- |
| 99001 | ServerErrorCode | Internal error, please try again later. If it still occurs you can consult customer service. |
| 99002 | DBReadErrorCode | Platform internal DB error, please try again later. If it still occurs you can consult customer service. |
| 99003 | NotFoundRecordCode | Platform internal error, record have not been found, please try again later. If it still occurs you can consult customer service. |
| 99004 | ServiceBusyCode | Platform internal service is busy,  please try again later. If it still occurs you can consult customer service. |

## Requesting validity verification error [1000, 1999]

| error code | error type | Troubleshooting suggestions |
| --- | --- | --- |
| 1001 | AppStatusErrorCode | Check the correctness of app-id and app-secret. If it still occurs you can consult customer service. |
| 1002 | RepeatRequestCode | The same request has already been sent, change the content of the request or send it again some time later. |
| 1003 | TimestampNotFoundCode | Check the timestamp in the header, the format is unix, and the unit needs to be seconds. |
| 1004 | RandomNumNotFoundCode | Check the random-num in the header, the data format needs to be a 64-bit integer. |
| 1005 | InvalidAppIdCode | Check that the app-id in the header is the same as the one provided by the platform |
| 1006 | CheckSignNotFoundCode | Check if the check-sign parameter is added to the header. |
| 1007 | CheckSignVerificationFailedCode | Check that the check-sign in the header generates a legal value according to the steps in the guidelines. <br> Please refer to: API authentication description <br> ->Generates the signature content check-sign |
| 1008 | RequestBodyNotBeEmpty | The body of the request must not be empty. Please fill in the required parameters according to the request instructions in the API documentation. |
| 1009 | RequestExpiredCode | The time between the current time and the time represented by the timestamp in the request header is too long. It is recommended to update the timestamp in the header as well as the check-sign. |

## Parameter validity check [11000, 11999]

| error code | error type | Troubleshooting suggestions |
| --- | --- | --- |
| 11001 | ParamErrorExternalCode | The requested parameters do not match the requirements. Please fill in the required parameters according to the request instructions in the API documentation. <br> example: <br> message=parcel weight can not be null <br> Solution: <br> fill in the value of parcel_weight in request |

## User error [12000, 12999]

| error code | error type | Troubleshooting suggestions |
| --- | --- | --- |
| 12001 | CreateAccountFailedCode | Check the correctness and legitimacy of the phone and email by referring to the returned message. |
| 12002 | EmailExistErrorCode | Check the correctness and legitimacy of the email |
| 12003 | PhoneExistErrorCode | Check the correctness and legitimacy of the phone |
| 12051 | VerifyAccountFailedCode | Please enter the legal user id and right password. |
| 12052 | AccountStatusAbnormalCode | Account status abnormal. Please contact admin. |

## order error [13000, 13999]

| error code | error type | remark |
| --- | --- | --- |
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

Outer error code[10001, 10999]

| error code | error msg |
| --- | --- |
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
| 10033 | Sorry,the address you selected is not within the limits of the region |
| 10034 | Sorry, the pickup address is missing |
| 10035 | Sorry, the delivery address is missing |
| 10036 | Sorry, the delivery branch information is missing |
| 10037 | Sorry, the pickup information is invalid |
| 10038 | The pickup Date you selected is missing |
| 10039 | The pickup timeslot you selected is missing |
| 10040 | The pickup timeslot you selected is invalid |
| 10041 | The Longitude and latitude you selected is invalid |
| 10042 | Sorry,the pament you selected is not supported by our service at the moment |
| 10043 | Sorry,the COD amount you input is not supported by our service at the moment |
| 10044 | Sorry, an unexpected error of  bussiness type has occured |
| 10045 | Sorry, an unexpected error of  service type has occured |
| 10049 | Failed to fetch AWB, please try again later |
| 10050 | Sorry,the item category is not supported by our service at the moment |
| 10051 | You must provide the receiver contact information |
| 10052 | Sorry, an unexpected error of  WMS has occured |
| 10054 | Sorry,the pickup branch is not supported by our service at the moment |
| 10055 | Sorry,the delivery branch is not supported by our service at the moment |
| 10056 | Sorry,the pickup timeslot you seleted is invalid at the moment |
| 10057 | Sorry,the actual service provider is invalid |
| 10058 | You cannot cancel this order |
| 10059 | Sorry,the delivery service you selected is not supported by our service at the moment |
| 10060 | Sorry, ESF calculation failed because of the TWS id is not exist |
| 10061 | Sorry, ESF calculation failed because of the shipping fee rate table failed to match |
| 10062 | Sorry, ESF calculation failed because of the address you selected does not macth avaliable route code |
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
