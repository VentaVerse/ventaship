# LBC statuses

LBC's delivery event statuses, from *LBC Track and Trace API V2* (rev 2.3.5, 2026-04-28), Section 5, Table 6.0.

- **Gate** is LBC's own grouping.
- **Status ID 0** is shared by several in-transit events. Those events can only be told apart by their text.
- **Gate colour** is LBC's Table 7.0 legend, read from the PDF's cell colours. It matters: 3 REFUSED TO ACCEPT and 18 PILFERED/DAMAGED sit under *Failed Attempt* but are black (**For Return**), and 6 CONSIGNEE OUT is white (**In Progress for Delivery**).
- The table shows LBC's internal spiel (its own name for the event). The external customer message is in the PDF.

| Gate | Status ID | Internal spiel | Gate colour (Table 7.0 meaning) |
|---|---|---|---|
| Delivered | 1 | DELIVERED | Yellow (Delivered) |
| Delivered | 5 | RELEASED TO REPRESENTATIVE | Yellow (Delivered) |
| Delivered | 15 | PICKED UP AT BRANCH | Yellow (Delivered) |
| Failed Del w/o Attempt | 19 | LATE ARRIVAL | Gray (Will be Rescheduled for Delivery) |
| Failed Del w/o Attempt | 28 | TRANSFERED TO OTHER DESTINATION | Gray (Will be Rescheduled for Delivery) |
| Failed Del w/o Attempt | 22 | MISROUTED | Gray (Will be Rescheduled for Delivery) |
| Failed Del w/o Attempt | 17 | TRANSFERED FOR PICK-UP | Gray (Will be Rescheduled for Delivery) |
| Failed Del w/o Attempt | 13 | NON-DELIVERY | Gray (Will be Rescheduled for Delivery) |
| Failed Del w/o Attempt | 30 | REQUEST TO POSTPONE DELIVERY | Gray (Will be Rescheduled for Delivery) |
| Failed Del w/o Attempt | 53 | ODZ – FOR SCHEDULED DELIVERY | Gray (Will be Rescheduled for Delivery) |
| Failed Del w/o Attempt | 12 | FORTUITOUS EVENT | Gray (Will be Rescheduled for Delivery) |
| Failed Del w/o Attempt | 14 | OUT OF DELIVERY ZONE | Gray (Will be Rescheduled for Delivery) |
| Failed Del w/o Attempt | 11 | HOLIDAY, LIMITED DELIVERY | Gray (Will be Rescheduled for Delivery) |
| Failed Attempt | 29 | NO PAYMENT | Red (Will be Rescheduled for Delivery) |
| Failed Attempt | 23 | INCOMPLETE ADDRESS | Red (Will be Rescheduled for Delivery) |
| Failed Attempt | 3 | REFUSED TO ACCEPT | Black (For Return) |
| Failed Attempt | 6 | CONSIGNEE OUT | White (In Progress for Delivery) |
| Failed Attempt | 54 | PENDING FOR DISPOSITION | Red (Will be Rescheduled for Delivery) |
| Failed Attempt | 59 | ODZ FOR DISPOSITION | Red (Will be Rescheduled for Delivery) |
| Failed Attempt | 27 | RTO | Red (Will be Rescheduled for Delivery) |
| Failed Attempt | 24 | OFFICE CLOSED | Red (Will be Rescheduled for Delivery) |
| Failed Attempt | 48 | OFFICE CLOSED W/ CERTIFICATE | Red (Will be Rescheduled for Delivery) |
| Failed Attempt | 4 | HOUSE CLOSED | Red (Will be Rescheduled for Delivery) |
| Failed Attempt | 10 | INCORRECT ADDRESS | Red (Will be Rescheduled for Delivery) |
| Failed Attempt | 2 | NO VALID ID | Red (Will be Rescheduled for Delivery) |
| Failed Attempt | 18 | PILFERED/DAMAGED | Black (For Return) |
| Failed Attempt | 7 | RELOCATED TO KNOWN ADDRESS | Red (Will be Rescheduled for Delivery) |
| Failed Attempt | 9 | CONSIGNEE NOT KNOWN | Red (Will be Rescheduled for Delivery) |
| Failed Attempt | 8 | MOVED TO UNKNOWN/NEW ADDRESS | Red (Will be Rescheduled for Delivery) |
| Failed Delivery | 25 or 51 | RTS (returned to sender) | Red (Will be Rescheduled for Delivery) |
| Failed Delivery | 57 | RETURN TO HUB (listed in Table 6.0; the 2.1 changelog says ID 57 was removed) | Red (Will be Rescheduled for Delivery) |
| Intransit | 0 | READY FOR PICK-UP BY CONSIGNEE | White (In Progress for Delivery) |
| Intransit | 8001 | ENCODED AT % | White (In Progress for Delivery) |
| Intransit | 8810 | RECEIVED AT (exchange department) | White (In Progress for Delivery) |
| Intransit | 8820 | FORWARDED AT (exchange department) | White (In Progress for Delivery) |
| Intransit | 1004 | RECEIVED AT | White (In Progress for Delivery) |
| Intransit | 0 | RECEIVED AT % (branch) | White (In Progress for Delivery) |
| Intransit | 0 | FORWARDED AT % (branch) | White (In Progress for Delivery) |
| Intransit | 8002 | OUT FOR DEL | White (In Progress for Delivery) |
| Intransit | 0 | PICKED UP BY LBC | White (In Progress for Delivery) |
| COD/COP Settlement | 0 | Deposited to Account of Shipper (COD remitted) | White (In Progress for Delivery) |

## Notes

- Tracking is a SOAP `LBCTrackAndTrace` call per tracking number. There is no status push.
- An LBC 3PL webhook product may exist; it is unconfirmed.
- Ventaship's LBC adapter (`app/Couriers/Lbc/LbcTrackingStatus.php`) condenses this history into 18 statuses of its own before recording.
