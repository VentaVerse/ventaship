# QuadX statuses

QuadX's official status list, from the example response of `GET /orders/statuses` in the docs fetched on 2026-11-08 (see `api.md`, *Get List of Statuses*).

The docs list **58** statuses. The live endpoint is the authority: it may return more (the status map's pick was made from 59 returned live).

**Status map** marks the 31 statuses chosen for the status map, and the group each one sits in. Source: `mappableStatusGroups()` in `app/Couriers/QuadX/QuadXFulfillment.php`.

| # | Status | QuadX label | Status map |
|---|---|---|---|
| 1 | `pending` | Pending |  |
| 2 | `for_pickup` | Ready for pickup | Before pickup |
| 3 | `picked_up` | Picked up | Picked up, moving |
| 4 | `failed_pickup` | Failed pickup | Failed attempt |
| 5 | `failed_delivery` | Failed delivery | Failed attempt |
| 6 | `in_transit` | In transit | Picked up, moving |
| 7 | `claimed` | Claimed |  |
| 8 | `delivered` | Delivered | Delivered |
| 9 | `return_in_transit` | Returned - in transit | Return to sender |
| 10 | `returned` | Returned | Return to sender |
| 11 | `failed_return` | Failed return | Return to sender |
| 12 | `out_for_delivery` | Out for delivery | Picked up, moving |
| 13 | `confirmed` | Confirmed |  |
| 14 | `canceled` | Canceled | Cancelled |
| 15 | `for_consolidation` | For consolidation |  |
| 16 | `consolidated` | Consolidated |  |
| 17 | `for_dropoff` | Ready for dropoff |  |
| 18 | `dropped_off` | Dropped off |  |
| 19 | `for_acceptance` | For acceptance |  |
| 20 | `accepted` | Accepted | Before pickup |
| 21 | `out_for_return` | Out for return | Return to sender |
| 22 | `for_payment` | For payment |  |
| 23 | `paid` | Paid |  |
| 24 | `for_transfer` | For transfer | Picked up, moving |
| 25 | `received_at_pickup_hub` | Received at pickup hub | Picked up, moving |
| 26 | `received_at_delivery_hub` | Received at delivery hub | Picked up, moving |
| 27 | `received_at_delivery_area` | Received at delivery area | Picked up, moving |
| 28 | `at_3pl_facility` | At 3PL facility | Picked up, moving |
| 29 | `at_sorting_center` | At sorting center | Picked up, moving |
| 30 | `in_transit_to_delivery_area` | In transit to delivery area | Picked up, moving |
| 31 | `transferred_to_partner` | Transferred to partner |  |
| 32 | `for_return` | For return | Return to sender |
| 33 | `arrived_at_rts_hub` | Arrived at RTS hub | Return to sender |
| 34 | `arrived_at_rts_area` | Arrived at RTS area | Return to sender |
| 35 | `at_sorting_center_rts` | At sorting center RTS | Return to sender |
| 36 | `for_claims` | For Claims |  |
| 37 | `claims_in_transit` | Claims in transit |  |
| 38 | `arrived_at_claims_area` | Arrived at claims area |  |
| 39 | `disposed` | Disposed |  |
| 40 | `rejected` | Rejected | Failed attempt |
| 41 | `damaged` | Damaged |  |
| 42 | `lost` | Lost |  |
| 43 | `rider_found` | Rider found |  |
| 44 | `picking_up` | Picking up |  |
| 45 | `for_delivery` | For delivery | Picked up, moving |
| 46 | `for_release` | For release |  |
| 47 | `out_for_pickup` | Out for pickup | Before pickup |
| 48 | `failed_pickup_attempt` | Failed pickup attempt | Failed attempt |
| 49 | `runsheet_closure` | Runsheet closure |  |
| 50 | `pickup_rider_found` | Pickup rider found | Before pickup |
| 51 | `delivery_rider_found` | Delivery rider found | Picked up, moving |
| 52 | `for_disposal` | For disposal |  |
| 53 | `pending_disposition` | Pending disposition |  |
| 54 | `rcd_at_sorting_center` | RCD at sorting center |  |
| 55 | `rcd_in_transit` | RCD in transit |  |
| 56 | `received_at_claims_recovery_hub` | Received at Claims/Recovery hub |  |
| 57 | `dws_update` | DWS update |  |
| 58 | `searching_for_rider` | Searching for Rider |  |

**Picked, but not in the docs' example list:** `for_reattempt`. They come from the live endpoint or from statuses seen in production.
