# SPX statuses

SPX's official order status codes, from the NSS OpenAPI guide ("Order status code", copied in `guide.md`). Webhook and tracking payloads carry `status_code` and `status`.

| Code | Status | SPX description |
|---|---|---|
| 1001 | Pending Pickup | Order creation is fully processed in our system, and the parcel is waiting to be picked up. |
| 2001 | In Transit | Parcel has been picked up / dropped off and in-transit for delivery. |
| 2006 | Delivering | Parcel is on the way to the receiver. |
| 3001 | On Hold | Parcel has been placed on hold during delivery. |
| 4001 | Delivered | Parcel has been delivered. |
| 5001 | Pickup Failed | Parcel pickup attempt was unsuccessful. |
| 5002 | Damaged | Parcel has been damaged. |
| 5003 | Lost | Parcel has been lost. |
| 6001 | Returning | Parcel is returning to sender due to unsuccessful delivery attempts. |
| 6002 | Return Failed | Parcel return attempt was unsuccessful. |
| 6003 | Returned | Parcel has been returned to the sender. |
| 7001 | Cancelled | Pickup Request Cancelled. |

## Webhooks

- SPX POSTs to a URL registered on the open platform.
- The response must be a 200 within **3 seconds**.
- Repeated failures or timeouts make SPX **stop sending**, so updates can be lost. This is one reason the status poll exists.
