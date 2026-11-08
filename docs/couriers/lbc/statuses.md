# LBC statuses

LBC's delivery event statuses, from *LBC Track and Trace API V2* (rev 2.3.5, 2026-04-28), Section 5, Table 6.0.

- **Gate** is LBC's own grouping.
- **Status ID 0** is shared by several in-transit events. Those events can only be told apart by their text.
- The table shows LBC's internal spiel (its own name for the event). The external customer message is in the PDF.

| Gate | Status ID | Internal spiel |
|---|---|---|
| Delivered | 1 | DELIVERED |
| Delivered | 5 | RELEASED TO REPRESENTATIVE |
| Delivered | 15 | PICKED UP AT BRANCH |
| Failed Del w/o Attempt | 19 | LATE ARRIVAL |
| Failed Del w/o Attempt | 28 | TRANSFERED TO OTHER DESTINATION |
| Failed Del w/o Attempt | 22 | MISROUTED |
| Failed Del w/o Attempt | 17 | TRANSFERED FOR PICK-UP |
| Failed Del w/o Attempt | 13 | NON-DELIVERY |
| Failed Del w/o Attempt | 30 | REQUEST TO POSTPONE DELIVERY |
| Failed Del w/o Attempt | 53 | ODZ – FOR SCHEDULED DELIVERY |
| Failed Del w/o Attempt | 12 | FORTUITOUS EVENT |
| Failed Del w/o Attempt | 14 | OUT OF DELIVERY ZONE |
| Failed Del w/o Attempt | 11 | HOLIDAY, LIMITED DELIVERY |
| Failed Attempt | 29 | NO PAYMENT |
| Failed Attempt | 23 | INCOMPLETE ADDRESS |
| Failed Attempt | 3 | REFUSED TO ACCEPT |
| Failed Attempt | 6 | CONSIGNEE OUT |
| Failed Attempt | 54 | PENDING FOR DISPOSITION |
| Failed Attempt | 59 | ODZ FOR DISPOSITION |
| Failed Attempt | 27 | RTO |
| Failed Attempt | 24 | OFFICE CLOSED |
| Failed Attempt | 48 | OFFICE CLOSED W/ CERTIFICATE |
| Failed Attempt | 4 | HOUSE CLOSED |
| Failed Attempt | 10 | INCORRECT ADDRESS |
| Failed Attempt | 2 | NO VALID ID |
| Failed Attempt | 18 | PILFERED/DAMAGED |
| Failed Attempt | 7 | RELOCATED TO KNOWN ADDRESS |
| Failed Attempt | 9 | CONSIGNEE NOT KNOWN |
| Failed Attempt | 8 | MOVED TO UNKNOWN/NEW ADDRESS |
| Failed Delivery | 25 or 51 | RTS (returned to sender) |
| Failed Delivery | 57 | RETURN TO HUB (listed in the table, but the 2.1 changelog says it was removed) |
| Intransit | 0 | READY FOR PICK-UP BY CONSIGNEE |
| Intransit | 8001 | ENCODED AT % |
| Intransit | 8810 | RECEIVED AT (exchange department) |
| Intransit | 8820 | FORWARDED AT (exchange department) |
| Intransit | 1004 | RECEIVED AT |
| Intransit | 0 | RECEIVED AT % (branch) |
| Intransit | 0 | FORWARDED AT % (branch) |
| Intransit | 8002 | OUT FOR DEL |
| Intransit | 0 | PICKED UP BY LBC |
| COD/COP Settlement | 0 | Deposited to Account of Shipper (COD remitted) |

## Notes

- Tracking is a SOAP `LBCTrackAndTrace` call per tracking number. There is no status push.
- `integration-notes.md` mentions an LBC 3PL webhook product that is unconfirmed.
- Ventaship's LBC adapter (`app/Couriers/Lbc/LbcTrackingStatus.php`) condenses this history into 18 statuses of its own before recording.
