# API list

- Source: https://spx.ph/integration/en/guide/67898740f15549f4a4524a23db339638
- Create Time: 08/06/2022
- Last Updated: 17/10/2024
- Harvested: 2026-11-10

---

Click on "API" above to view the interface details or click [here.](https://spx.ph/integration/en/api/c27c8517d55c4fea96bcb426cfcaf140)

![](https://sls.sp-cdn.shopee.com/api/v1/file/c3/69067583/spx-open/A3oxOFKHAMjxUHcQETAEACc)

| API | Description | Calling relationships with other API |
| --- | --- | --- |
| 1. Order (need the user-id and user-secret generated via 3.1 Create Account) |  |  |
| 1.1 Get pickup timeslot | Get the available pickup time supported by the current service. | - |
| 1.2 Track Order | Batch query details of orders (except information related to fees). | You need to call 1.4 Create Order V2 first to get the tracking number. |
| 1.3 Cancel Order | Batch cancel order. | You need to call 1.4 Create Order V2 first to get the tracking number. |
| 1.4 Create Order V2 | Create order | - |
| 1.5 Get Order Create Result V2 | Get create order result | You need to call 1.4 Create Order V2 first to get the tracking number. |
| 1.6 Get AWB V2 | Batch get waybill label, a maximum of 30 orders can be requested at a time. | You need to call 1.4 Create Order V2 first to get the tracking number. |
| 1.7 Update Order | used for batch updating order information. | - |
| 2. Shipping fee (need the user-id and user-secret generated via 3.1 Create Account) |  |  |
| 2.1 Check Shipping Fee | Batch get estimated shipping fee before order creation. At most 100 per batch. | - |
| 2.2 Get Order Fee | Batch get actual shipping fee after order creation | You need to call 1.2 Create Order first to get the tracking number. |
| 3.Account |  |  |
| 3.1 Create Account | Create a new account by email or phone to generate user-id and user-secret. | The user-id and user-secret will be used for other APIs. |
| 3.2 Check Account Credentials | To check whether account credentials provided are valid | - |
| 4.Web Hook |  |  |
| 4.1 Tracking Web Hook | When the order status changes, the SPX Open Service platform will send an HTTP Post request to the registered URL, and the parameters of the request are JSON data containing the order status. | - |
| 5. Address API |  |  |
| 5.1 Get Address file download link | obtain link to the latest address files for download | - |
