# Get available addresses

- Source: https://spx.ph/integration/en/guide/e25f379dc37c4c0781f150949b9eb6c6
- Create Time: 11/08/2022
- Last Updated: 17/10/2024
- Harvested: 2026-11-10

---

In order to improve the efficiency and quality of the service, we will provide an excel file containing all the standard addresses supported by the latest service range of Shopee Xpress.

This data is mainly used in address fields in the [1.4 Create Order V2](https://spx.ph/integration/en/api/8c5233900b5b40b6af0348d13de172b1) API. It is recommend to use the address listed in the file as the input parameter for creating an order, so as to reduce the failure of placing an order caused by the address error or the service range of the selected address is not supported.

Through[5.1 Get Address file download link](https://spx.ph/integration/en/api/3c11b14db42f442aa2af0c5c9c9dd5fb), you can get the download link of the latest address library excel file in real time.

## How to use the address file?

In create order request body, you have to input the address info both in sender_info and deliver_info.

The correspondence between the columns in the excel file and the fields for creating orders is as follows:

[PH Example file](https://uat.spx.ph/downloads/resource/location_list/2c5a96a3f3bafaa347b6222077f7a9e9.xlsx)

| for PH：State / City / PostCode |  |  |  |  |  |
| --- | --- | --- | --- | --- | --- |
| Data in excel | State | City | PostCode | Longitude | Latitude |
| API Corresponding Fields <br> Type: string | sender_state <br> deliver_state | sender_city <br> deliver_city | sender_city <br> deliver_city | sender_longitude <br> deliver_longitude | sender_latitude <br> deliver_latitude |
| Mandatory (Y/N) | Y | Y | Y | N | N |

[TH Example file](https://spx.co.th/downloads/resource/location_list/32895fdb48f38e2fc9712adfb38e6770.xlsx)

| for TH：State / City / PostCode |  |  |  |  |  |
| --- | --- | --- | --- | --- | --- |
| Data in excel | State | City | PostCode | Longitude | Latitude |
|  | จังหวัดขอนแก่น | อำเภออุบลรัตน์ | 40250 | 102.6676 | 16.7547 |
| API Corresponding Fields <br> Type: string | sender_state <br> deliver_state | sender_city <br> deliver_city | sender_post_code <br> deliver_post_code | sender_longitude <br> deliver_longitude | sender_latitude <br> deliver_latitude |
| Mandatory (Y/N) | Y | Y | Y | N | N |

[VN Example file](https://spx.vn/downloads/resource/location_list/f5d75dd5be82bea9276e6cea41ff6345.xlsx)

| for VN：State / District / Ward |  |  |  |  |  |
| --- | --- | --- | --- | --- | --- |
| Data in excel | State | District | Ward | Longitude | Latitude |
|  | Nam Định | Huyện Trực Ninh | Xã Trực Tuấn | 106.2787 | 20.27511 |
| API Corresponding Fields <br> Type: string | sender_state <br> deliver_state | sender_city <br> deliver_city | sender_district <br> deliver_district | sender_longitude <br> deliver_longitude | sender_latitude <br> deliver_latitude |
| Mandatory (Y/N) | Y | Y | Y | N | N |

[ID Example file](https://spx.co.id/downloads/resource/location_list/0d11ce4725bb856a686e181c2e250997.xlsx)

| for ID：Province / City / District / PostCode |  |  |  |  |  |  |
| --- | --- | --- | --- | --- | --- | --- |
| Data in excel | Province | City | District | PostCode | Longitude | Latitude |
|  | JAWA TIMUR | KAB. MAGETAN | PANEKAN | 63352 | 111.305702 | -7.628713 |
| API Corresponding Fields <br> Type: string | sender_state <br> deliver_state | sender_city <br> deliver_city | sender_district <br> deliver_district | sender_post_code <br> deliver_post_code | sender_longitude <br> deliver_longitude | sender_latitude <br> deliver_latitude |
| Mandatory (Y/N) | Y | Y | Y | Y | N | N |
