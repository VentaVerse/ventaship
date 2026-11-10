# Suggestions of common errors

- Source: https://spx.ph/integration/en/guide/5031855915f947c88c5192745572051d
- Create Time: 14/09/2022
- Last Updated: 21/10/2024
- Harvested: 2026-11-10

---

We have counted the types of errors that fail when creating orders using the open api and have provided a list of the 10 most frequent error codes and the corresponding workarounds here.

Some of the error codes that are not listed can be found in the [Error codes mapping](https://spx.vn/en/integration/en/guide/beef04af97d24f289a14c45147ddfd98) for a description of the error codes and suggestions for troubleshooting.

1. You can get the error code in response body "fail_list"-->"ret_code" when fail in creating orders.

2. First find the corresponding error code and try to follow the solution suggestions and retry. If the error still occurs, please feel free to contact our platform staff and we will help you resolve it as soon as possible.

| Error code | Suggestions |
| --- | --- |
| 10080 | Check “1.1 Get the available pick up time” to obtain available pick up date and time then use for the fields ”pickup_time” and “pickup_time_range_id”. Please use the latest returned results in case the time available for selection has elapsed. |
| 10077 | Please use the longitude and latitude related to the address which is provided in the Address file. (You can use 5.1 Get Address file download link to obtine the address file ). Or you don’t have to fill in the longitude and latitude, just input the address only. |
| 10078 | Please use the longitude and latitude related to the address which is provided in the Address file. (You can use 5.1 Get Address file download link to obtine the address file ). Or you don’t have to fill in the longitude and latitude, just input the address only. |
| 10021 | Please use the standard address provided in the Address file. (You can use 5.1 Get Address file download link to obtine the address file ). |
| 10022 | Please check the address info in order creation request. The sender_address and deliver_address may be too close. |
| 10018 | Please use the standard address provided in the Address file. (You can use 5.1 Get Address file download link to obtine the address file ). If the error still occurs, please contact us. |
| 10019 | Please use the standard address provided in the Address file. (You can use 5.1 Get Address file download link to obtine the address file ). If the error still occurs, please contact us. |
| 10001 | Check the field "parcel_weight": x, |
| 10003 | Check the field "cod_amount": x, |
| 10084/10085/10086 | Check the field "parcel_height","parcel_width","parcel_length": |
| 10083 | Check the field "parcel_weight": x, |

3. Related links and infomations:

- How to obtain the standard address: [get available address](https://spx.vn/en/integration/en/guide/4002b7f9bb214dcaa769d1f01ebd2c09).
- Get the available pickup time slot: [get pickup timeslot](https://spx.vn/en/integration/en/api/fac2bc87fefa41e197e00fc4384e144a)
- Parcel limit:

| Parcel_info limit | PH | TH | VN |
| --- | --- | --- | --- |
| parcel_weight | < 50 kg | 0.001kg < weight < 20kg | 0kg < weight<  15kg |
| parcel_length | Max: 150 cm | Max:100cm | Max: 60cm |
| parcel_width | Max: 150 cm | Max:100cm | Max: 60cm |
| parcel_height | Max: 150 cm | Max:100cm | Max: 60cm |
| cod_max_limit | Max: 50,000 Peso | Max: 36000 | Max: 20000000 |
