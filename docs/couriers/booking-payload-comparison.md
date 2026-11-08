# Add-shipment form vs courier booking payloads

Date: 2026-11-08. Read-only research. No code or database was changed.

## How to read the citations

| Prefix | Path |
|---|---|
| `VS/` | `/home/ubuntu/ventaship/` |
| `VC/` | `/home/ubuntu/venta/extensions/courier-fulfillment/` (VentaCart extension) |
| `qx.md` | `VS/docs/couriers/quadx/api.md` |
| `spx.md` | `VS/docs/couriers/spx/api.md` |
| `spxg.md` | `VS/docs/couriers/spx/guide.md` |
| `spxb.md` | `VS/docs/couriers/spx/integration-brief.md` |
| `lbcP` | `LBC_Pickup_Request_API_V2 2026-02-04.pdf`, pdftotext output at `/tmp/claude-1000/-home-ubuntu-ventaship/c8c8af77-89a5-4cf5-8064-a1cc12c70a2d/scratchpad/lbc-pickup.txt` |
| `lbcA` | `LBC_AirWaybill_API 2026-02-11.pdf`, pdftotext output at `/tmp/claude-1000/-home-ubuntu-ventaship/c8c8af77-89a5-4cf5-8064-a1cc12c70a2d/scratchpad/lbc-awb.txt` |
| `lbcN` | `VS/docs/couriers/lbc/integration-notes.md` |
| `api.md` | `VS/docs/api.md` (Ventaship client API, used by VentaCart Pro) |

Short names for code files:

| Name | File |
|---|---|
| QX | `VS/app/Couriers/QuadX/QuadXFulfillment.php` |
| SPX | `VS/app/Couriers/Spx/SpxFulfillment.php` |
| LBC-F | `VS/app/Couriers/Lbc/LbcFulfillment.php` |
| LBC-B | `VS/app/Couriers/Lbc/LbcPickupRequestBuilder.php` |
| LBC-P | `VS/app/Couriers/Lbc/LbcParcel.php` |
| LBC-K | `VS/app/Couriers/Lbc/LbcPackaging.php` |
| CO | `VS/app/Couriers/CourierOrder.php` |
| COPT | `VS/app/Shipments/CourierOptions.php` |
| SC | `VS/app/Shipments/ShipmentCreator.php` |
| VREQ | `VS/app/Http/Requests/Api/V1/Concerns/ValidatesShipmentParts.php` |
| CREQ | `VS/app/Http/Requests/Api/V1/CreateShipmentRequest.php` |
| UREQ | `VS/app/Http/Requests/Ui/ShipmentFormRequest.php` |
| UFORM | `VS/resources/views/shipments/form.blade.php` |
| VC-QX | `VC/src/Couriers/QuadX/QuadXFulfillment.php` |
| VC-SPX | `VC/src/Couriers/Spx/SpxFulfillment.php` |
| VC-LBC | `VC/src/Couriers/Lbc/LbcFulfillment.php` |
| VC-FC | `VC/src/Http/Controllers/FulfillmentController.php` |
| VC-ERP | `VC/src/Http/Controllers/ErpFulfillmentController.php` |
| VC-IDX | `VC/resources/views/index.blade.php` |

### How a form value reaches a courier in Ventaship

1. The UI form posts the same shape as the API (UREQ:11-15).
2. `ShipmentCreator::attributes()` stores it. Parcel, pickup_date, pickup_slot, shipping_payment and insure go into `options` (SC:221-253).
3. At booking, `CourierOptions::for()` flattens it into `$opts`: `service`, `parcel` (= `parcel.type`), `weight_kg`, `length_cm`, `width_cm`, `height_cm`, `pickup_slot`, `pickup_date`, `shipping_payment`, `buyer_payment` (= payment_method), `insure` and `pickup` (COPT:20-33).
4. The order side is built by `CourierOrder::fromApi()` (CO:87-129).
5. Three money rules live in CourierOrder:
   - `total` = `total ?? declared_value ?? cod_amount ?? 0` (CO:102).
   - The insured value = `declared_value`, or `total` when declared_value is not numeric (CO:153-158).
   - The COD amount = `cod_amount` only (CO:138-147).

---

## A. Required booking payload, per courier

Legend for the "Ventaship sends it" column:

| Code | Source |
|---|---|
| R | recipient |
| P | pickup / sender |
| PC | parcel |
| PAY | payment |
| IT | items |
| SET | account setting |
| FIX | hard-coded |
| DER | derived |

### A1. QuadX: `POST /orders` (qx.md:3201-3316)

Address block fields are listed for `delivery_address`. `pickup_address` has the same fields. The pickup address is required once the order status is for_pickup (qx.md:3213), and Ventaship always sends `status: for_pickup` (QX:993), so both addresses are required.

| QuadX field | Required? | Type / limits | Meaning | Ventaship sends it? | VentaCart sends it? | VentaCart Pro via Ventaship API | Notes |
|---|---|---|---|---|---|---|---|
| `delivery_address.name` | Y (qx.md:3217) | string 255 | consignee | R first+last, else "Customer" (QX:1030) | same (VC-QX:942) | `recipient.first_name`/`last_name` | |
| `delivery_address.mobile_number` | optional (qx.md:3222) | string 50 | | R phone (QX:1031) | same | `recipient.phone` | |
| `delivery_address.line_1` | Y (qx.md:3225) | string 500 | | R address_1, else "-" (QX:1032) | same (VC-QX:944) | `recipient.address_1` | `line_2` (optional, qx.md:3226) is never sent; `address_2` is dropped (see C3) |
| `delivery_address.district` | optional (qx.md:3224) | string 300 | | R district, else barangay (QX:1033) | same (VC-QX:945) | `recipient.district` / `barangay` | |
| `delivery_address.city` | Y (qx.md:3227) | string 300 | | R city, normalised (QX:1034) | same | `recipient.city` | |
| `delivery_address.state` | Y (qx.md:3228) | string 300 | | R state, normalised (QX:1035) | same | `recipient.state` | |
| `delivery_address.postal_code` | **Y** (qx.md:3229) | string 50 | | R postcode, else "-" (QX:1036) | same | `recipient.postcode` (API: optional, VREQ:162) | Required by QuadX, optional in the API; "-" is the fallback |
| `delivery_address.country` | Y (qx.md:3230) | ISO-2 | | FIX "PH" (QX:1037) | same | `country` | |
| `delivery_address.remarks` | optional (qx.md:3231) | string 500 | notes | `note` → courier_comment (QX:1046-1049) | courier_comment | `note` | The UI form has no note field (see C2) |
| `pickup_address.*` | Y for for_pickup (qx.md:3213) | as above | sender | P contact_name/phone/address (QX:1063-1077) | pickup address record | `pickup.*` | |
| `items[]` | Y (qx.md:3239) | array | waybill, insurance and cost basis | IT (QX:1079-1094) | same (VC-QX:1000-1014) | `items[]` | Must include at least one `product` (qx.md:3255-3259) |
| `items[].type` | Y (qx.md:3243) | `product`/`shipping`/`tax`/`fee`/`insurance`/`discount` | | FIX `product` (QX:1084) | same | — | |
| `items[].description` | Y (qx.md:3244) | string 500 | | IT name (QX:1085) | same | `items[].name` | |
| `items[].amount` | Y (qx.md:3245) | float 2dp | unit amount | IT price, 0 when null (QX:1086) | same | `items[].price` (API: optional, CREQ:41) | |
| `items[].quantity` | Y (qx.md:3246) | int | | IT quantity (QX:1087) | same | `items[].quantity` | |
| `total` | **Y, "at least 1"** (qx.md:3276) | float | "must match sum total of order items" | COD: `cod_amount`. Prepaid: CO `total` (QX:1008) | always order total (VC-QX:920) | `payment.cod_amount` / `total` | **Bug risk (see C1-1):** a UI prepaid shipment has total 0 |
| `currency` | Y (qx.md:3277) | 3 chars | | FIX `PHP` (QX:1005) | same | — | |
| `payment_method` | Y (qx.md:3278) | `credit_card`,`debit_card`,`otc`,`cod`,`other` | | DER `cod` or `other` (QX:1003) | same, overridable by `buyer_payment` (VC-QX:902-916) | `payment.method` | |
| `payment_provider` | Y (qx.md:3279) | `asiapay`,`dragonpay`,`lbc`,`lbcx`,`paypal`,`other` | | FIX `other` (QX:1002) | same | — | |
| `seller_payment_method` | optional (qx.md:3280) | `cash`,`gcash`,null | how the seller pays the fee at pickup; omitted = billed/deducted (qx.md:3284) | from `shipping_payment` when cash/gcash, omitted for net_off (QX:1019-1022) | same (VC-QX:931-934) | `payment.shipping_payment` | gcash is refused live (QX:158-163). The UI has no control, so it always omits the field (net_off), although `bookingDefaults()` says `cash` (QX:195) |
| `shipment` | **Y** (qx.md:3294) | string 50: `small-pouch`, `medium-pouch`, `big-pouch`, `box`, `oversized`, `custom` | package type | PC `parcel.type`, else setting `quadx_default_parcel`, else `box` (QX:667-671, 1006) | modal select, required (VC-FC:625; VC-QX:571-575) | `parcel.type` | `custom` "requires parcel dimensions and weight" (qx.md:3309-3316). Neither app offers `custom` |
| `parcel.dimensions{length,width,height,uom}`, `parcel.weight{value,uom}` | only for `custom` (qx.md:3309-3316, 3372-3383) | cm / kg | | **not sent** (QX:1000-1001) | not sent (VC-QX:913-914) | `parcel.length_cm`…`weight_kg` (ignored for QuadX) | |
| `buyer_name` | Y (qx.md:3295) | string 100 | | R first+last (QX:999) | same | recipient name | |
| `email` / `contact_number` | one of them Y (qx.md:3297-3298) | string 50 | | `contact_number` = R phone (QX:998). Email never sent | same | `recipient.phone` | Recipient email is not needed by QuadX |
| `status` | optional (qx.md:3299) | `pending`,`confirmed`,`for_pickup` | | FIX `for_pickup` (QX:993) | same | — | |
| `reference_id` | optional (qx.md:3300) | string | | DER `courier_ref` such as VS000123 (QX:995) | order id (VC-QX:908) | — (Ventaship-generated) | |
| `service` | not in the docs table. Shown in the response (qx.md:3453); rates take `service_type` "next_day \| same_day_delivery" (qx.md:5246) | `same_day_pickup`, `next_day` | pickup schedule | `service`, else setting `quadx_default_service`, else `next_day` (QX:661-665, 994) | modal "Schedule", required (VC-FC:624) | `service` | The docs write `same_day_delivery`; the code sends `same_day_pickup` (QX:137). Which one is right is unverified |
| `declared_value` | not in the docs table. Echoed in the response (qx.md:3515) and used by rates (qx.md:5266) | float | insured value | `insure` ? insuredValue : 0 (QX:678-681, 1014) | `insure` ? order total : 0 (VC-QX:582-585) | `insure` + `payment.declared_value` / `total` | Always sent: when omitted, QuadX defaults it to the total (QX:1009-1013) |
| `own_print`, `pickup_total`, `metadata`, `return_address` | optional (qx.md:3301-3303, 3357) | | | not sent | not sent | — | |

### A2. SPX: Create Order V2, `batch_create_order` (spx.md:332-395)

| SPX field | Required? | Type / limits | Meaning | Ventaship sends it? | VentaCart sends it? | VentaCart Pro via Ventaship API | Notes |
|---|---|---|---|---|---|---|---|
| `order_id` | N (spx.md:350) | string ≤32 | client id | DER courier_ref (SPX:1464) | order id | — | |
| `base_info.service_type` | Y (spx.md:351) | int, `1` = standard | | FIX `1` (SPX:1465) | same | `service` (`standard`, SPX:239-244; ignored) | |
| `sender_info.sender_state` / `_city` | Y (spx.md:352-353) | ≤64 | | P state/city (SPX:1515-1516) | pickup address | `pickup.state` / `city` | |
| `sender_info.sender_district` | Y for PH (spx.md:354) | ≤64 | | P district, else city (SPX:1510-1517) | same | `pickup.district` | |
| `sender_info.sender_street` | Y for PH (spx.md:355) | ≤64 | | P **barangay** (SPX:1518) | same | `pickup.barangay` (API: optional) | Ventaship refuses to book when it is blank (SPX:1506) |
| `sender_info.sender_post_code` | Y for PH (spx.md:356) | ≤16 | | P postcode, refused when blank (SPX:1506) | same | `pickup.postcode` (API: optional) | |
| `sender_info.sender_name` / `_phone` / `_detail_address` | Y (spx.md:359-361) | name ≤64; phone 11 digits starting 0, or 12 starting 63; addr ≤256 | | P contact_name, contact_phone, address_1 (SPX:1519-1522) | same | `pickup.contact_name` / `contact_phone` / `address_1` | |
| `fulfillment_info.payment_role` | Y (spx.md:362) | `1` sender pay, `2` receiver pay | who pays the fee | DER: `receiver_pay` → 2, else 1 (SPX:1623) | modal "You pay shipping" (VC-IDX:1299-1303) | `payment.shipping_payment` = `sender_pay` / `receiver_pay` (SPX:270-276) | The UI has no control, so it is always 1 |
| `fulfillment_info.cod_collection` | Y (spx.md:363) | 0/1 | | DER payment_method (SPX:1625-1628) | same | `payment.method` | |
| `fulfillment_info.cod_amount` | Y when COD (spx.md:364) | **integer pesos, PH max 50,000** (spx.md:364; spxg.md:604) | | `cod_amount`; centavos refused (SPX:304-308, 1632) | `round(order total)` (VC-SPX:1631) | `payment.cod_amount` | **No 50,000 pre-check** in either app |
| `fulfillment_info.collect_type` | Y (spx.md:365) | `1` pickup, `2` drop off | | DER: 1 when a valid `pickup_slot` is present, else 2 (SPX:1642-1650) | from modal "Pickup date" select; "Drop off myself" = blank (VC-IDX:1379-1384) | `pickup_slot` from `POST /pickup-slots` (api.md:859, 914) | The UI has no slot control, so it is always drop-off |
| `pickup_time`, `pickup_time_range_id`, `pickup_time_range` | Y when collect_type=1 (spx.md:366-368) | int, int, "09:00 - 13:00" | | decoded from the `pickup_slot` JSON (SPX:1655-1668) | same | `pickup_slot` | |
| `deliver_info.deliver_state` / `_city` | Y (spx.md:370-371) | ≤64 | | R state/city (SPX:1572-1573) | same | `recipient.state` / `city` | |
| `deliver_info.deliver_district` | Y for PH (spx.md:372) | ≤64 | | R district, else city (SPX:1553-1558) | same | `recipient.district` | |
| `deliver_info.deliver_street` | Y for PH (spx.md:373) | ≤64 | | R barangay, else city (SPX:1559-1564) | same | `recipient.barangay` | |
| `deliver_info.deliver_post_code` | Y for PH (spx.md:374) | ≤16 | | R postcode, **refused when blank** (SPX:1550) | same | `recipient.postcode` (API: optional) | |
| `deliver_info.deliver_name` / `_phone` / `_detail_address` | Y (spx.md:377-379) | ≤64; PH phone; ≤256 | | R name, phone, address_1 (SPX:1577-1579) | same | `recipient.*` | |
| `deliver_info.deliver_instruction` | N (spx.md:380) | ≤256 | | `note` (SPX:1585-1588) | courier_comment | `note` | |
| `parcel_info.parcel_weight` | **Y** (spx.md:381) | kg, PH < 50 (spxg.md:600) | | **the preset's `max_weight_kg`, not the actual weight**, unless `parcel=custom` (SPX:1684, 1709-1746) | same logic | `parcel.weight_kg` (only read for `custom`) | See C1-3 |
| `parcel_info.parcel_length` / `_width` / `_height` | **Y** (spx.md:382-384) | cm, PH max 150 each (spxg.md:601-603) | | preset dimensions, or custom (SPX:1685-1687) | same | `parcel.length_cm` etc. (only for `custom`) | API accepts ≤1000 (VREQ:122-125): no 150/50 check |
| `parcel_info.parcel_item_name` | Y (spx.md:385) | ≤64 | | first item name, cut to 60 (SPX:1680-1681) | same | `items[0].name` | |
| `parcel_info.parcel_item_quantity` | N (spx.md:386) | int | | Σ item quantity (SPX:1673-1678) | same | `items[].quantity` | |
| `parcel_info.express_insured_value` | **Y** (spx.md:387) | number, "Parcel value" | | `insure` ? insuredValue : 0 (SPX:1694) | `insure` ? order total : 0 | `insure`, `payment.declared_value` / `total` | Cannot be edited after booking (spxg.md:533-536) |
| `parcel_info.parcel_item_type` | **Y** (spx.md:388) | `Food and beverage` / `Clothing` / `Electronics` / `Medical` / `Groceries` / `General merchandise` / `Fragile` | category | FIX `General merchandise` (SPX:84, 1695) | FIX same (VC-SPX:82) | **no API field** | See C1-4 |
| `parcel_info.item_list[]` (`item_name`, `item_weight` in g, `item_price`, `item_quantity`, `item_category`) | the list is optional (spxb.md:66); its subfields are Y when it is sent (spx.md:389-395) | | itemised detail | not sent | not sent | `items[]` could feed it | |
| `fulfillment_info.voucher_code` | N (spx.md:369) | | | not sent | not sent | — | |

There is no packaging or pouch concept in the SPX API. The "pouch presets" are a Ventaship and VentaCart invention that turns a choice into L/W/H/weight (SPX:66-77; VC-SPX:71).

### A3. LBC: Pickup Request `InsertPickupRequest`, XML (lbcP Table 1.0, lines 229-419)

| LBC node | Required? (lbcP) | Type / limits | Meaning | Ventaship sends it? (LBC-B:98-148) | VentaCart | VentaCart Pro via API | Notes |
|---|---|---|---|---|---|---|---|
| `ShipmentMode` | M, always 1 (238-239) | bigint, `1` AIR | | FIX `1` (99) | **LBC booking not built** (VC-LBC:547-550) | — | |
| `TrackingNo` | M (244) | bigint, from the issued series | client-issued tracking number | DER allocator | — | — | No uniqueness check at LBC (lbcN:168-172) |
| `Origin` / `DestinationCode` | M (251, 413) | `MMA`/`NLA`/`SLA`/`VIS`/`MIN` | area code | DER from the address via LbcAreaResolver (100, 146) | — | from `pickup` / `recipient` state + `codes.state` | |
| `TransactionDate` | M (256) | MM/DD/YYYY HH:MM:SS | | DER now (102) | — | — | |
| `ODZ` | M (259) | bit | out of delivery zone | DER from serviceability (103) | — | — | |
| `ShipperAccountNo` | M (262) | from LBC sales | | SET (104) | — | — | |
| `Shipper`, `ShipperStBldg` | M (265, 268) | 150 / 250 | | P contact_name, address_1 (105-106) | — | `pickup.contact_name`, `address_1` | |
| `ShipperBrgy` / `ShipperCityMuncipality` / `ShipperProvince` | city row marked M (274); the rows sit together | 250 each | | resolved names (107-109) | — | `pickup.barangay` / `city` / `state` | |
| `ShipperContactNumber` | M (283) | 250 | | P phone (110) | — | `pickup.contact_phone` | |
| `ShipperMobileNumber`, `ShipperSendSMS` | non-M, default 0 (286-287) | | | phone, `0` (111-112) | — | | |
| `ProductLine` | M (289) | int, `2` CARGO | | FIX `2` (113) | — | | |
| `ServiceMode` | M (293) | int, `8` DOOR TO DOOR | | FIX `8` (114) | — | | |
| `CODAmount` | M (297) | decimal(18,2), 0 if not COD | exact amount to collect | `cod_amount` or 0 (115; LBC-F:491-494) | — | `payment.cod_amount` | |
| `PreferredDate` | M (301) | MM/DD/YYYY HH:MM:SS | pickup date | from `pickup_slot` (Y-m-d), else the next business day at 10:00 (LBC-F:517-544, 607) | — | **`pickup_slot`** (not `pickup_date`) | See C1-6 |
| `Consignee`, `ConsigneeStBldg` | M (305, 309) | 150 / 250 | | R name, address_1 (117-118) | — | recipient | |
| `ConsigneeBrgy` / `City` / `Province` | city M (316) | 250 | | resolved (119-121) | — | recipient | |
| `ConsigneeContactNumber` | M (322) | 50 | | R phone (122) | — | `recipient.phone` | |
| `ConsigneeSendSMS` | listed non-M (327-329) | | | FIX `1`; 0 is refused live with code 127 (123; lbcN:269-271) | — | | |
| `Quantity` | M (332) | int | number of packages | FIX 1 (LBC-P:117; 125) | — | — | |
| `PKG` | M (343) | int, Table 2.0 (lbcP:485-506) | packaging product | PC `parcel.type`, must be a known code; no default (LBC-F:563-566; 433-440) | — | `parcel.type` = PKG code string | |
| `ACTWTkgs` | M (347, 352) | decimal(18,2) kg | actual weight | PC weight_kg, else Σ item weights; refused when missing (LBC-F:573-578) | — | `parcel.weight_kg` or `items[].weight_kg` | |
| `LengthCM` / `WidthCM` / `HeightCM` | M; "important to input the exact size" (350-357) | decimal(18,2) cm | | PC dimensions. **Required only for PKG 16**; 0 otherwise (LBC-P:130-136) | — | `parcel.length_cm` etc. | The AWB says "LWH Mandatory if Cargo" (lbcA:214-217). See C1-7 |
| `VolWTcbm` | M (361) | L×W×H/3500 | | DER (LBC-P:163-166) | — | — | |
| `CBM` | M (365), sea only | | | FIX 0 (132) | — | — | |
| `ChargeableWT` | M (368) | max(actual, vol) | | DER (LBC-P:168-171) | — | — | |
| `DeclaredValue` | M (372) | decimal(18,2); the insurance coverage basis | | 0, or max(insuredValue, **500**) when insured; COD is always insured (LBC-F:481-488, 592; LBC-P:107, 144-147) | — | `insure`, `payment.declared_value` / `total` | See C1-2 |
| `Description` | M (377) | 250 | goods | DER "2 x Name, 1 x Other" (LBC-B:218-223) | — | `items[]` | |
| `Commodity` | in the sample, not in the table text | | | FIX `1` (136) | — | | Legend is an image (lbcN:280-283) |
| `ForCrating` | non-M, default 0 (380-382) | bit | | FIX 0 (137) | — | | |
| `AttachmentName/ReferenceNo One..Four` | non-M, default "0" (384-410) | 50 | | ORDERNO + reference; MODE + `REGULAR`/`DROPOFF`; rest "0" (138-145) | — | `service` = `REGULAR` (LBC-F:402-412) | DROPOFF is disabled (LBC-F:106) |
| `Client` | M (418) | nvarchar(5) | client code | SET (147) | — | — | |

The Air Waybill (`GeneratePOD`) is built from the pickup fields. It needs nothing extra from the form (`VS/app/Couriers/Lbc/LbcWaybillBuilder.php:23-58`).

---

## B. Packaging options per courier

### B1. QuadX: `shipment` (qx.md:3294)

| Code (verbatim) | Label (QX:142-151 / VC-QX:67-76) | Needs weight? | Needs dimensions? |
|---|---|---|---|
| `small-pouch` | White Small Pouch | no | no |
| `medium-pouch` | Blue Medium Pouch | no | no |
| `big-pouch` | Red Large Pouch | no | no |
| `box` | Box | no | no |
| `oversized` | Oversized | no | no |
| `custom` | (not offered by either app) | **yes** | **yes** (qx.md:3309-3316) |

- **Pricing:** QuadX prices by category. The rates endpoint returns a fee per parcel type when `shipment` is omitted (qx.md:5265). VentaCart shows each fee inside the option label, e.g. "Box (₱120.00)" (VC-IDX:1735-1739).
- **Oversized:** the docs give no size or weight rule for it.
- **Own packaging:** QuadX has no explicit "own packaging" option. The closest fits are `box` (category price) or `custom` with real dimensions. Using `custom` for this is a guess.
- **Default:** setting `quadx_default_parcel`, else `box` (QX:667-671, 776-782).

### B2. SPX: no API enum. Ventaship presets become dimensions plus weight.

| Value | Label (SPX:247-267; default presets SPX:73-77) | Dimensions sent | Weight sent |
|---|---|---|---|
| `small` | Small (25×20×10cm, up to 3kg) | 25×20×10 | **3** (the preset maximum) |
| `medium` | Medium (35×25×15cm, up to 5kg) | 35×25×15 | **5** |
| `large` | Large (45×35×20cm, up to 10kg) | 45×35×20 | **10** |
| `custom` | Custom dimensions… | typed L/W/H (all >0) | typed weight (SPX:1713-1726) |

- **Presets:** they can be edited through the account setting `spx_pouch_presets`, which holds JSON (SPX:1381-1384, 1748-1753). The value is `Str::slug(label)`.
- **Unknown or blank value:** it silently falls back to the first preset (SPX:1737-1745).
- **Limits:** weight < 50 kg and each side ≤ 150 cm (spxg.md:600-603).
- **Own packaging:** this is `custom`.
- **VentaCart:** same presets. L/W/H/weight inputs appear only for SPX + `custom` (VC-IDX:1359-1377).
- **Item category** (`parcel_item_type`) is a second required choice that neither app shows.

### B3. LBC: `PKG` (lbcP Table 2.0, lines 485-506; all ProductLine 2)

| PKG | LBC name (verbatim) | Line | Ventaship label (LBC-K:27-45) | VentaCart label (VC-LBC:524-537) | Dimensions in Ventaship |
|---|---|---|---|---|---|
| `16` | BOX | CARGO | Own box | Own packaging | **required** (LBC-P:131-133) |
| `165` | KILOBOX MINI | CARGO | Kilobox Mini | KB Mini | optional (all three or none) |
| `6` | KILOBOX-S | CARGO | Kilobox Small | KB Small | optional |
| `164` | KILOBOX SLIM | CARGO | Kilobox Slim | KB Slim | optional |
| `63` | KILOBOX-M | CARGO | Kilobox Medium | KB Medium | optional |
| `7` | KILOBOX-L | CARGO | Kilobox Large | KB Large | optional |
| `64` | KILOBOX-XL | CARGO | Kilobox XL | KB XL | optional |
| `45` | N-PACK SMALL | DOCUMENTS | N-Pack Small | N-Pack Small | optional |
| `8` | N-PACK LARGE | DOCUMENTS | N-Pack Large | N-Pack Large | optional |
| `62` | N-POUCH SS (NPSS) | DOCUMENTS | N-Pouch SS | (not listed) | optional |
| `3` | N-POUCH REGULAR (NPR) | DOCUMENTS | N-Pouch Regular | (not listed) | optional |
| `61` | N-POUCH XL (NPXL) | DOCUMENTS | N-Pouch XL | (not listed) | optional |
| `9` | X POUCH | DOCUMENTS | X-Pouch | (not listed) | optional |
| `2` | PLASTIC | CARGO | Plastic | (owner removed it, VC-LBC:508-511) | optional |
| `4` | CRATE | CARGO | Crate | (removed) | optional |
| `79` | PIECE | CARGO | Piece | (removed) | optional |
| `80` | SACK | CARGO | Sack | (removed) | optional |

- **Weight:** always required (LBC-F:573-578).
- **Dimensions:** the LBC docs call L/W/H mandatory (lbcP:350-357), and the AWB says "LWH Mandatory if Cargo" (lbcA:216-217). Ventaship requires them only for PKG 16 and sends 0 otherwise. Whether LBC accepts 0 is "still to be confirmed" (LBC-P:94-99).
- **Default:** none on purpose (LBC-F:427-440).
- **"N-Pack Sakto":** the owner ships it, but it has no known PKG code yet (VC-LBC:518-522).
- **VentaCart:** lists 9 options, but LBC booking is not implemented there (VC-LBC:491-497, 547-550).

### B4. How each app presents packaging today

- **VentaCart modal:**
  - Required "Schedule", "Parcel" and "You pay shipping" selects that open on "Choose one" (VC-FC:624-626; VC-IDX:1270-1303).
  - Parcel labels carry the courier's quoted fee.
  - An "Insure shipment (declared at order total)" toggle (VC-IDX:1291-1295).
  - SPX only: custom dimensions and a pickup-date select (VC-IDX:1359-1400).
  - A "Ships from" select (VC-IDX:1246-1253).
- **Ventaship form:**
  - Always-required "Weight (kg)" (UFORM:200-202; UREQ:139).
  - A collapsed "Size" fold with a **free-text "Type" input** and L/W/H (UFORM:204-216).
  - No list of parcel options, although `GET /couriers` exposes them (api.md:426-438).
  - No pickup slot, shipping payment, item category or note fields.

---

## C. Gaps

### C1. Required by a courier but missing, hard-coded or badly derived in Ventaship

1. **QuadX `total` on a UI prepaid shipment is 0.**
   - The UI never posts `total`. UREQ rules have no `total` (UREQ:127-139), so `shipments.total` is null.
   - The form forces `declared_value = '0'` when Insure is off (UREQ:45).
   - CourierOrder then gives `total = null ?? '0.00'` = 0 (CO:102), and QuadX gets `total: 0` (QX:1008).
   - QuadX says total is "required; at least 1" and "must match sum total of order items" (qx.md:3276).
   - On COD from the API, `total = cod_amount`, which also need not equal Σ items (api.md:936).
   - Whether QuadX enforces this is unverified live, but the docs rule is broken.
2. **LBC COD declared value collapses to ₱500.**
   - LBC insures every COD parcel (LBC-F:466-470, 592) at `max(insuredValue(), 500)` (LBC-F:487).
   - insuredValue() returns `declared_value` whenever it is numeric (CO:155-157).
   - The UI sends `declared_value = '0'` unless Insure is ticked (UREQ:45), so a ₱3,000 COD parcel booked from the UI without Insure declares **₱500**, not the amount collected.
   - The API path has the same result when a client sends `declared_value: 0`.
3. **SPX weight is fiction.**
   - `parcel_weight` is the preset's max (3/5/10 kg), not the weight typed in, unless `parcel.type=custom` (SPX:1684, 1709-1746).
   - The UI's required Weight is ignored by SPX in every case except custom.
   - The UI "Type" is free text, so a typed value that is not exactly `small`/`medium`/`large`/`custom` silently books the **Small** preset (SPX:1737-1745).
4. **SPX `parcel_item_type` is hard-coded** to `General merchandise` (SPX:84, 1695). It is mandatory, it has 7 values (spx.md:388), and there is no API or UI field for it.
5. **SPX limits are not checked before booking.** There is no 50 kg / 150 cm check (spxg.md:600-603) and no COD 50,000 cap (spx.md:364). The API allows up to 1000 (VREQ:122-125), so these fail only at SPX.
6. **LBC pickup date:**
   - LBC reads `pickup_slot`, a Y-m-d string (LBC-F:607, 904-917). **The API's `pickup_date` is stored but never read by any courier.** The only hit for `pickup_date` in `app/Couriers` is LBC-F:705, which writes meta.
   - The UI has neither field, so LBC always books the next business day.
7. **LBC dimensions:** they are sent as 0 for every PKG except 16 (LBC-P:94-99, 130-136), although LBC lists L/W/H as mandatory (lbcP:350-357) and the AWB needs LWH for CARGO, i.e. the Kilobox family (lbcA:216-217). LBC acceptance of 0 is still unconfirmed.
8. **Packaging choice is free text in the UI** (UFORM:207-209):
   - QuadX: blank → setting/`box`; a typo → refused by QuadX.
   - LBC: must be a digit string like `16`, else "Choose an LBC parcel." (LBC-F:563-566).
   - SPX: see item 3.
9. **Shipping payment has no UI control** (UFORM has none):
   - QuadX: it books as net_off (omits `seller_payment_method`, QX:1019), while its own `bookingDefaults()` says `cash` (QX:190-196). VentaCart requires the choice (VC-FC:626).
   - SPX: it is always `payment_role` 1 (SPX:1623).
10. **SPX pickup is never offered in the UI.** With no `pickup_slot`, `collect_type` is always 2 (drop-off) (SPX:1642-1650). VentaCart offers it (VC-IDX:1379-1384).
11. **Postcode** is required by QuadX (qx.md:3229) and SPX for PH (spx.md:356, 374), but optional in the API (VREQ:162):
    - QuadX gets "-" (QX:1036).
    - SPX refuses at booking (SPX:1506, 1550).
12. **Barangay** is mandatory for SPX `sender_street` (Ventaship refuses a blank one, SPX:1506), but it is optional in the API (VREQ:158).

### C2. Ventaship vs VentaCart differences that matter for compatibility

| Topic | VentaCart | Ventaship | Impact |
|---|---|---|---|
| QuadX `total` | order total (VC-QX:920) | COD: cod_amount; prepaid: total/declared (QX:1008) | A Pro client must send `total` = Σ items for prepaid |
| SPX COD with centavos | rounded (VC-SPX:1631) | refused (SPX:304-308) | Pro must send whole pesos |
| COD amount source | order total (VC-SPX:1631) | explicit `payment.cod_amount`, never inferred (VREQ:129-141) | Pro must map its order total to `cod_amount` |
| Insured value | order total (VC-QX:582-585) | declared_value, else total (CO:153-158) | Pro should send `payment.declared_value` = order total when insuring |
| Parcel / service / shipping_payment | required choices in the modal (VC-FC:624-626); ERP API optional (VC-ERP:45-49) | optional; defaults apply (api.md:915-918, 924) | same names: `service`, `parcel.type`, `payment.shipping_payment` |
| `buyer_payment` | opt that can override (VC-QX:902-903; VC-ERP:48) | must match `payment.method`, else refused (CO:166-172) | Pro maps its value to `payment.method` |
| LBC packaging list | 9 options, "Own packaging", "KB …" (VC-LBC:524-537) | 17 options, "Own box", "Kilobox …" (LBC-K:27-45) | Same codes, different labels and subset; Pro reads `GET /couriers` |
| LBC booking | not implemented (VC-LBC:547-550) | built | — |
| Pickup origin | `pickup_address_id`, a saved address (VC-FC:659) | full `pickup{}` object (VREQ:95-102) | Pro sends the address itself |
| Pickup slot | SPX select (VC-IDX:1379-1384) | API `pickup_slot` (api.md:914); **no UI** | UI parity gap |
| Delivery note | `courier_comment` (customer's) feeds QuadX `remarks` / SPX `deliver_instruction` | API `note` (api.md:933); **no UI field** | UI parity gap |
| Custom dimensions | SPX + custom only (VC-IDX:1362-1377) | UI weight always required + optional size (UFORM:197-217) | UI asks for weight when QuadX doesn't need it |
| Fee in the parcel label | yes (VC-IDX:1735-1739) | no (`POST /quotes` exists, api.md:811-838) | UX gap |

### C3. Fields the Ventaship UI asks for that no courier requires

| UI field | Evidence | Recommendation |
|---|---|---|
| Recipient **Email** (UFORM:19) | No courier code reads it. QuadX needs email *or* contact number (qx.md:3297-3298) and gets the phone | drop from the UI (keep it optional in the API) |
| **Unit / building** (`address_2`, UFORM:23) | Stored (SC:406) but **never sent**: CourierOrder has no address_2 (CO:27-41); QuadX `line_2` is not built (QX:1029-1038); SPX and LBC use address_1 only | drop, or fold it into `address_1` when sent (guess: concatenating is safe at ≤250/256 chars) |
| **Weight** as always required (UREQ:139) | QuadX ignores it (QX:1000-1001); SPX ignores it unless custom (SPX:1684) | required only for LBC and SPX custom |
| **Size: Type** free text (UFORM:207-209) | — | replace with a per-courier picker (D) |
| **L/W/H** shown for every courier (UFORM:210-214) | Needed only for LBC PKG 16 (plus Kilobox per the AWB doc), SPX custom and QuadX custom (not offered) | show only for own packaging / custom |
| **Order reference** (UFORM:239-246) | No courier needs it; couriers get `courier_ref` (QX:995; SPX:1464; LBC-B:139). Auto-generated when blank (UREQ:143-150) | keep optional, or fold it away |
| Recipient **Last name** | optional everywhere; joined into one name | keep optional |
| Item **unit price** | QuadX needs `items[].amount` and `total` (qx.md:3245, 3276). LBC and SPX don't, but the UI derives COD and the declared value from it (UREQ:36-46) | keep |

---

## D. Recommended minimal unified form

Rules applied: every field must feed at least one courier's required payload, or the money COD and insurance need. Courier-specific fields appear only for that courier. Values post in the API's own shape (api.md:904-934), so `ShipmentCreator` and VentaCart Pro share one contract.

### 1. Courier

| Field | Shown for | Default | API field |
|---|---|---|---|
| Courier tile | all | first usable account | `account_id` |
| Schedule | QuadX only, since it is the only courier with more than one usable service (QX:129-140; SPX:239-244 has one; LBC DROPOFF is disabled, LBC-F:106) | setting `quadx_default_service` | `service` |

### 2. Customer

| Field | Shown for | Default | API field |
|---|---|---|---|
| Name (first + optional last) | all | — | `recipient.first_name`, `last_name` |
| Mobile | all | — | `recipient.phone` |
| Street / building (one line) | all | — | `recipient.address_1` |
| Province → City → Barangay picker (with PSGC codes) | all | — | `recipient.state`, `city`, `barangay`, `district`, `codes.*` |
| Postcode | all; make it **required** for QuadX and SPX | autofill from the barangay where known | `recipient.postcode` |

### 3. Items

| Field | Shown for | Default | API field |
|---|---|---|---|
| Name, Qty, Unit price (rows) | all | 1 row | `items[].name`, `quantity`, `price` |
| (derived) Total | all | Σ qty×price | **send as `total`** (fixes C1-1) |

### 4. Payment

| Field | Shown for | Default | API field |
|---|---|---|---|
| COD / Prepaid | all | Prepaid | `payment.method` |
| Collect amount (derived from total, read-only) | COD | Σ items. SPX: whole pesos, ≤ 50,000 | `payment.cod_amount` |
| Insure | all; for LBC + COD shown as on and locked (LBC always insures COD) | off | `insure`; `payment.declared_value` = total (send the total, not `0`, so LBC COD stops falling to ₱500; fixes C1-2) |
| Who pays shipping | QuadX: `cash` / `gcash` / `net_off`. SPX: `sender_pay` / `receiver_pay`. LBC: hidden (only `account`) | QuadX `cash` (QX:195); SPX `sender_pay` | `payment.shipping_payment` |

### 5. Package (one tile picker per courier, values from `GET /couriers` → `parcel_options`)

| Courier | Choices (value → label) | Then ask | API |
|---|---|---|---|
| QuadX | `small-pouch` White Small Pouch · `medium-pouch` Blue Medium Pouch · `big-pouch` Red Large Pouch · `box` Box · `oversized` Oversized; fee from `POST /quotes` on each tile | nothing (no weight or dimensions) | `parcel.type` |
| SPX | `small` / `medium` / `large` presets · `custom` "Own packaging" | **Weight (kg)** always; L/W/H only for custom; **Item category** (7 values, default `General merchandise`) | `parcel.type`, `parcel.weight_kg`, `parcel.*_cm`; item category needs a new API field (e.g. `parcel.category`, a **guess**) |
| LBC | N-Pack Small `45`, N-Pack Large `8`, KB Mini `165`, KB Small `6`, KB Slim `164`, KB Medium `63`, KB Large `7`, KB XL `64`, Own packaging `16` (the VentaCart subset, VC-LBC:524-537); others behind "More" | **Weight (kg)** always; L/W/H required for `16`, and **recommended for every CARGO code** per lbcA:216-217 | `parcel.type`, `parcel.weight_kg`, `parcel.*_cm` |

Weight: prefill from Σ `items[].weight_kg` where known (`VS/app/Couriers/Support/OrderWeight.php:16-35`).

Recommended code change to make the SPX weight real: send the typed weight with the preset dimensions, instead of the preset maximum (SPX:1684). This is a **recommendation**, not current behaviour.

Labels: the owner's "pouch sizes / box / oversized / own packaging" mapping per courier:

| Owner's term | QuadX | SPX | LBC |
|---|---|---|---|
| Pouch sizes | the three pouches | the presets | N-Pack |
| Box | box | — | Kilobox |
| Oversized | oversized | — | — |
| Own packaging | **guess**: `box`, or the docs' `custom` with dimensions | `custom` | `16` |

### 6. Handover

| Field | Shown for | Default | API field |
|---|---|---|---|
| Pickup slot or "Drop off myself" | SPX (`POST /pickup-slots`) | drop-off | `pickup_slot` |
| Pickup date (dates from `POST /pickup-slots`) | LBC | next business day, Mon-Sat, ≤ 30 days ahead (LBC-F:112, 505-544) | `pickup_slot` (not `pickup_date`, see C1-6) |
| — | QuadX: nothing (QuadX schedules itself, QX:703-711) | | |

### 7. Sender

| Field | Shown for | Default | API field |
|---|---|---|---|
| Saved pickup address card + Change | all; barangay and postcode must be complete (SPX:1506) | the saved default | `pickup.*` |

### 8. Optional

| Field | Shown for | Default | API field |
|---|---|---|---|
| Delivery note | all (QuadX remarks ≤500, SPX instruction ≤256) | blank | `note` |
| Order reference | all | auto `M-Ymd-XXXXXX` | `client_reference` |

**Dropped from the UI:** recipient email, Unit/building, the free-text "Type", the always-shown L/W/H, and the always-required weight for QuadX.

### Compatibility check against the Ventaship API

**Unchanged.** Every field above already exists in `POST /shipments` (api.md:904-934). The one exception is the SPX item category, which needs a new optional field (for example `parcel.category`).

**Pro must send** all of these:
- `total` = Σ items.
- `payment.cod_amount` explicitly; for SPX, whole pesos.
- `payment.declared_value` when it wants a value other than `total`.
- `parcel.type` taken from `GET /couriers` (LBC has no default).
- `parcel.weight_kg` for LBC and SPX.
- `pickup_slot` for an SPX pickup or an LBC date.

**Server-side fixes this implies** (recommendations, not current behaviour):
- (a) Read `pickup_date` as LBC's PreferredDate when no slot is given, or document that it is ignored.
- (b) Validate `parcel.type` against the account's `parcel_options`.
- (c) Validate the SPX limits (50 kg, 150 cm, COD 50,000).
- (d) For an LBC COD parcel, declare at least `cod_amount` when `declared_value` is 0.

## Not verified / open

- Whether QuadX actually rejects `total: 0` or a total that differs from Σ items (docs rule only).
- `same_day_pickup` vs the docs' `same_day_delivery` (qx.md:5246).
- Whether LBC accepts 0 dimensions for CARGO PKGs.
- LBC's legends for Commodity / ServiceMode / PKG are images (lbcN:280-283).
- QuadX publishes no size or weight limits per pouch, or for oversized.
