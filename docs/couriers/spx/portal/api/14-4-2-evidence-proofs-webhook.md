# 4.2 Evidence Proofs Webhook

- Source: https://spx.ph/integration/en/api/30b2e03359dd4175a45b88db280d427d
- Create Time: 21/05/2025
- Last Updated: 24/02/2026
- Harvested: 2026-11-10

---

The EP Webhook is used by the SPX Open Service platform to push delivery evidence proofs (EP) to third-party platforms. These proofs include four types of courier operation records, such as delivery signatures or pickup confirmations. When new evidence is generated or updated, SPX will send an HTTP POST request with JSON parameters to the registered Webhook URL.

## Web Hook URL Configuration

The third-party platform must provide an external public URL as the Webhook endpoint. This URL will be bound to the platform's application during the configuration of `app-id` and `app-secret`. Ensure the URL is accessible and capable of handling real-time updates.

| Parameter | Type | Description |
| --- | --- | --- |
| tracking_no | string | Unique parcel tracking number. |
| customer_order_id | string | Customer order id |
| id | string |  |
| epod_list | []string | List of URLs for Proof of Delivery (EPOD) |
| epop_list | []string | List of URLs for Proof of Pickup (EPOP) |
| epor_list | []string | List of URLs for Proof of Return (EPOR) |
| epooh_list | []string | List of URLs for Proof of Other Handling (EPOOH) |
| tracking_code_name | string | Tracking status code name |
| status | string | Current shipment status |
| status_code_name | string |  |

## Web Hook Authentication

SPX signs the request body using the third-party platform's `app-secret`, and the signature is provided in the `check-sign` header. The signature generation method is consistent with other SPX APIs (refer to API Guide > Authentication). The third-party platform must validate the signature upon receipt to ensure the request is legitimate.

## Web Hook Response

You will need to return 200 HTTP status code to indicate that you have received a Web hook. SPX will set a timeout period of 3 seconds. It is recommended to return 200 responses after receiving a Web hook request before processing the request.

Remark：If there are repeatedly rejected or timeout Web hook requests, the sending of Web hook requests will stop, which may result in the loss of some order status.
