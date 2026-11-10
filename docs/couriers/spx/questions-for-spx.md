# Questions for SPX (Open API integration)

Prepared 2026-11-10 from a review of our integration against the SPX Open API portal. Ready to send with the webhook URL.

---

Hello SPX team,

We have finished UAT (tracking SPEPH065515515378) and are moving to production. Our webhook URL is:

**https://&lt;our-domain&gt;/webhooks/spx**

Before we go live, could you confirm a few points that the documentation doesn't cover?

1. **Remote area fee.** The Create Order result (1.5) includes a `remote_area_fee` field that isn't in the documentation. Does 2.1 Check Shipping Fee also return it? Is it already included in `estimated_shipping_fee`, or charged on top?

2. **Parcel value when not insured.** The Create Order V2 (1.4) table describes `express_insured_value` as the parcel value. When the merchant has not chosen insurance, should we send the real parcel value or 0? Does sending a value above 0 switch on insurance and charge an insurance fee automatically?

3. **Cancel after a failed pickup.** Can an order still be cancelled (1.3) after status 5001 Pickup Failed, or only while it is 1001 Pending Pickup?

4. **Location list for production.** We built our address matching from the location file of 5 August 2025 (5.1). Is the production location list the same file? How often does it change, and is there a way to be notified?

5. **Waybill (AWB) endpoint.** Please confirm the production endpoint and response format for 1.6 Get AWB V2, and the host the PDF is served from, so we can allow it.

6. **Webhook headers.** Do webhook pushes always include the `app-id`, `timestamp`, `random-num` and `check-sign` headers, signed exactly as API requests are? If our endpoint is unreachable for a while, do you retry, and how many times?

Thank you!
