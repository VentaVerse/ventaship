# How to call the open API

- Source: https://spx.ph/integration/en/guide/b10f5ca427aa44cd98678561a92ef500
- Create Time: 08/06/2022
- Last Updated: 21/10/2024
- Harvested: 2026-11-10

---

## Pre-Development Notes

1. Before using the API, you need to contact the platform staff to obtain the app-id and app-secret.
2. Before calling the API, please ensure that the check-sign is generated and added to the header as required.
3. When using the order-related interface, you need to use the create account interface to generate user_id and user_secret, or ask the platform staff to provide them.
4. Check the error code table for code meaning and suggestions for troubleshooting.
5. Check the Order status table before using 1.2 track order API, so that you can understand the order status in response body.
6. Read Parameter enumeration mapping table[](https://spx.vn/en/integration/en/guide/babd1497ec4b480e95c69429cc1b2e3b)to see the mapping between parameter values and what they actually mean.
7. Read the API documentation[](https://spx.vn/en/integration/en/api/fac2bc87fefa41e197e00fc4384e144a)carefully and provide parameters with the correct data type as required.
8. Understand the relationship between the order of API calls.
9. Use postman for API debugging. Call the correct interface address.

## API call process

As shown in the figure below, before calling the open API, you need to complete the following 2 preparatory tasks:

### 1.Get unique identifier:

After confirming the access with the operation staff of our platform, we will provide each accessing party with a unique app-id and app-secret for subsequent API calls.

### 2.Generate signature:

API calls require check-sign as the signature to be placed in the request header to confirm the origin of the request and to ensure security. Please refer to API authentication description for the acquisition and use of check-sign

## API call method

All interfaces use the POST method, UTF-8 encoding, body in JSON format, request parameter Type Content-Type= Application/JSON, and headers to input app-id, check-sign, timestamp and random-num.

## General request header

When calling the interface, signature checks are required and parameters needed are passed through the request header. All the parameters showed below are needed in the request header of each API request.

| Parameters | Type | Mandatory (Y/N) | Description |
| --- | --- | --- | --- |
| app-id | integer | Y | Issued by SPX Open platform, each APP is unique. |
| check-sign | string | Y | Used to verify the source security of requests.For details of how to generate, see API authentication description. |
| timestamp | integer | Y | The requested Unix timestamp <br> Unit: second |
| random-num | integer | Y | random number <br> Data constraints: <br> signed 64-bit integers |
