# LBC integration notes

Everything here was **verified against the live gateway on 2026-08-14** using the owner's secondary (test) keys, not inferred from the PDFs. Where the PDFs disagree with what the API actually did, the API wins and the disagreement is noted.

## Hosts

| | |
|---|---|
| API gateway | `https://lbcapigateway.lbcapps.com` |
| Developer portal | `https://lbcapiservice.lbcapps.com` (register, subscribe, read keys) |

**There is no sandbox or UAT host.** The words sandbox, staging and UAT appear in none of the four PDFs, and every documented endpoint is on the one gateway.

**Test versus live is selected by WHICH KEY you send**, not by URL. LBC issues a **primary** and a **secondary** key per subscription, and treats the secondary as test. This is a vendor convention, not the Azure APIM meaning (where the pair is for zero-downtime rotation).

## Subscriptions

One subscription per API product, each with its own primary and secondary key. Seen on the portal profile page:

    LeiWardrobe_LBCPickupRequest   -> product "LBC Pickup Request"
    LeiWardrobe_LBCCommonRefV2     -> product "LBC Common API"

Naming convention is `<CompanyName>_<ApiName>`.

## The auth header is NOT uniform

This cost three attempts to discover and is the single most likely thing to waste someone's afternoon:

| API | Header |
|---|---|
| Common API (`camsbackend/*`) | `lbcOAkey` |
| Air Waybill | `lbcOAkey` |
| Track and Trace | `lbcOAkey` |
| **Pickup Request** | **`Ocp-Apim-Subscription-Key`** |

Sending `lbcOAkey` to Pickup returns `401 "Access denied due to missing subscription key"`, which reads like a bad key rather than a wrong header name.

## Endpoints, all confirmed 200

**Common API, address and serviceability**

    GET  /camsbackend/provinces/search?searchTerm=&limit=
    GET  /camsbackend/cities/search?searchTerm=&provinceId=&limit=
    GET  /camsbackend/barangays/search?searchTerm=&cityId=&provinceId=&limit=
    POST /camsbackend/validate/validateServicableArea   body {"psgc_code": <int>}

`searchTerm` is **required** on barangays/search; omitting it returns a 400 naming the field.

`validateServicableArea` returns status, rate and rate category in one call:

    Caloocan Barangay 161  psgc 1380100161
      {"status":"Serviceable","psgc_code":"1380100161",
       "rate_details":{"rate":250,"category":"METRO MANILA AREA","remarks":null}}

    Caluya / Alegria       psgc 600605001
      {"status":"Not Serviceable","psgc_code":null,
       "rate_details":{"rate":0,"category":null,"remarks":null}}

This is the best serviceability API of the three couriers. QuadX only answers via a 422 on its rates endpoint; SPX cannot answer before booking at all. All three independently agree Caluya is uncovered.

### Validate accepts names too, but NEVER use them

The explorer sample sends names alongside the code:

    {"psgc_code":1381300139,"province":"NATIONAL CAPITAL REGION NCR",
     "city_municipality":"QUEZON CITY","barangay":"BATASAN HILLS"}

Names alone (no `psgc_code`) do work, and the response even resolves the code back. **Do not rely on this.** The name match is exact and unforgiving, and our own spellings fail against it:

    {"province":"Metro Manila","city_municipality":"Caloocan","barangay":"Barangay 161"}
      -> {"status":"Not Serviceable","psgc_code":null}      <-- WRONG, Caloocan is serviceable

The failure is silent and indistinguishable from a genuine refusal: a name mismatch and a real out-of-coverage answer both return `status: Not Serviceable` with `psgc_code: null`. Validating by name would quietly tell operators that covered addresses are uncovered.

### The correct pipeline: search, then validate by code

Their search endpoints ARE fuzzy (the docs say partial and case-insensitive) and tolerate our spellings, so resolve first and validate on the code they hand back. Proven end to end:

    GET  /camsbackend/cities/search?searchTerm=caloocan
         -> cityId 1380100000, "CITY OF CALOOCAN"
    GET  /camsbackend/barangays/search?searchTerm=161&cityId=1380100000
         -> barangayId 1380100161, "BARANGAY 161",
            fullLocationName "NATIONAL CAPITAL REGION NCR, CITY OF CALOOCAN, BARANGAY 161"
    POST /camsbackend/validate/validateServicableArea {"psgc_code":1380100161}
         -> Serviceable, rate 250, METRO MANILA AREA

**Fuzzy in, exact out.** This also means no local PSGC crosswalk is needed for LBC at all: their own search is the resolver, and `fullLocationName` is a ready-made confirmation string for the operator.

**Air Waybill**

    POST /lbcwaybill/v1/api/POD/GeneratePOD   body {"TrackingNo": "..."}
    -> {"Rendered_Byte": "<base64 PDF>"}

**It does not validate the tracking number.** A request for `000000000000` returned 200 with a rendered PDF. A 200 is not evidence the shipment exists.

The real payload is far larger than the probe above; it is the whole label, and LBC renders whatever you send:

    Barcode1D, Barcode2D, TrackingNo, ConsigneeName, ConsigneeAddress,
    ShipperName, ShipperAddress, NoOfItems, TransactionDate,
    DeliveryRequirement, SpecialInstructions, DRNo, DRNo2,
    Origin, Destination, DeclaredValue, VolumeWt, LWH, ActualWt, ChargeableWT,
    PayMode, ServiceMode, ShipmentMode, Product, ProductName,
    TotalFreightCharge, Amount, CreatedBy, PrintedBy

Sending only `TrackingNo` therefore produced a near-empty 4KB waybill, not an error.

**`Origin` and `Destination` are LBC AREA codes, not PSGC.** The samples use `MMB`, `SLA` and `VIS`, which look like Metro Manila, South Luzon and Visayas. The Pickup payload uses the same vocabulary for `Origin` and `DestinationCode`. We still need LBC's list of these codes and the rule for deriving one from a customer address.

**Pickup Request** (XML, not JSON, despite its document describing it as JSON over HTTP)

    POST /lbcpickuprequest/v1/api/DirectInjection/InsertPickupRequest
    Content-Type: text/xml, root element <PickupRequestEntity>
    -> <ReturnEntity><IsSuccess>true|false</IsSuccess><MessageList>...

Validation failures come back as `<ResponseMessageEntity><Code>NNN</Code>` where the codes are exactly Table 4.0 of the Pickup PDF (101 to 152). Success is `Code 100` with the TrackingNo echoed.

**Track and Trace** (SOAP over .NET, `tempuri.org`)

    POST /lbctrackingapi2/v2/     Content-Type: text/xml
    <soap:Envelope><soap:Body>
      <LBCTrackAndTrace xmlns="http://tempuri.org/"><TrackingNo>...</TrackingNo></LBCTrackAndTrace>
    </soap:Body></soap:Envelope>
    -> StatusCode 0001 SUCCESS!, then TrackingDetails entries

## The tracking number model, and why it differs from every other courier

From the Air Waybill data dictionary, verbatim:

> `TrackingNo` bigint — **"LBC Tracking Number series from sales assigned to clients"** — sample `28310000000040` — Mandatory

**LBC does not issue the tracking number. The client does**, from a block LBC's sales team assigns. That inverts how QuadX and SPX work, where booking returns a number.

Consequences:

1. `TrackingNo` is the **join key** across all four APIs. The same number goes into the Pickup Request, the Air Waybill, and the tracking query.
2. An Air Waybill can be generated with no pickup behind it, because the number is ours to claim.
3. **Venta must own a tracking-number allocator**: issue the next unused number from the assigned series, never reuse one, and warn before the block is exhausted. This is persistent state neither other courier required.

## The assigned series, and there is no cancel

**LBC issued the series on 2026-09-05**, verbatim:

> Assigned series NGD MUSICAL GOODS TRADING 37686XXX 14-digit
>
> **37686000000001 - 37686000003000**
>
> DO NOT generate series on their own, e.g. adding from the end of officially-issued series. Only use series issued from IT-DBA Team, else it might cause duplicate TNs in our system. Ensure to properly monitor the TN usage so that you can request in advance.

3,000 numbers, per store, from LBC's IT-DBA team. This is what the allocator described above must draw from, and running out is a human process on LBC's side, so the warning has to come early.

**There is no cancel, void or delete endpoint.** Searched all four vendor PDFs and these notes: zero mentions. A pickup request, once accepted, cannot be withdrawn through the API.

Two consequences:

1. A booking mistake cannot be undone by us. Whatever the adapter does for `cancelShipment()` will be a local state change plus, at best, a phone call. It must not pretend to have cancelled anything at LBC.
2. **Whether a number returns to the pool after a local cancel is therefore OUR decision, not LBC's.** Given they warn about duplicates and do not enforce uniqueness themselves, burning the number is the safer default. Confirm with LBC before choosing otherwise.

### Re-verified 2026-09-05

Keys still live, on the test (secondary) subscription:

    GET  /camsbackend/cities/search?searchTerm=caloocan   -> 200, real data
    POST /lbcpickuprequest/v1/api/DirectInjection/InsertPickupRequest
         -> 200 <IsSuccess>true</IsSuccess> <Code>100</Code> "Successfully saved."

The probe used `TrackingNo 2026090500001`, deliberately OUTSIDE the assigned block, so no issued number was spent proving the endpoint still answers. That is only safe because LBC does not enforce uniqueness, which is the same property that makes our allocator a correctness requirement.

## LBC does NOT enforce tracking number uniqueness

The identical pickup request was submitted twice with the same `TrackingNo 1234567894444` and **both returned `IsSuccess: true`, Code 100**. No duplicate error.

This makes Venta's allocator a **correctness requirement, not a convenience**. If the same number is issued twice, LBC accepts both, and two parcels then share an identity across the pickup, the waybill and tracking, with no way to tell them apart. Uniqueness must be enforced on our side because theirs does not.

## `Client` is free text, at least in test

The second submission used `<Client>NEWGEARDAY</Client>` instead of the sample's `TEST` and was accepted without complaint. So the field is not validated against any registry in the test environment.

**Do not auto-derive it** from the pickup contact name or store name. It is most likely how LBC attributes shipments to an account for billing and reporting; if it is validated or used for attribution in production, a plausible-looking guess would misfile real shipments and surface only at invoice time. Keep it an explicit settings field, blank until LBC confirms the value.

## AppKey is NOT required

The Pickup sample payload carries `<AppKey>7ea2cfa66609bfed47e03bc71df224b4</AppKey>`, and the owner confirmed that value is a **primary** subscription key.

A full pickup was created successfully **with the `<AppKey>` node removed entirely**, authenticating only on the `Ocp-Apim-Subscription-Key` header. So AppKey appears to be an older body-based auth style superseded by the header. **Do not add a settings field for it.**

An earlier build inferred it was a required credential from the sample payload alone. The live API disproved that.

## PSGC codes match QuadX exactly

    Caloocan Barangay 161    LBC 1380100161    QuadX 1380100161

Both use the current 10-digit PSGC generation. Venta's bundled `database/data/ph/*.json` is the older 9-digit generation, so **one crosswalk serves both couriers**, not two.

Gotcha: LBC returns these as JSON **numbers**, so leading zeros are lost. Caluya came back as `600605000`, which is really `0600605000`. Parse as strings and left-pad to 10, or every code outside NCR silently mismatches.

## Proven end to end

One number carried through three APIs on the test key:

    PICKUP  IsSuccess true, Code 100, "Successfully saved", TrackingNo 1234567894444
    TRACK   0001 SUCCESS! ... PICKED UP BY LBC ... LBC CARGO FACILITY-MANILA 2, CORPORATE
    AWB     200, 4,164 byte PDF

## Still needed from LBC before production

1. **Our tracking number series.** Pickup validates it via `spCheckPickupRequestTrackingNo`; a made-up number is rejected or, worse, collides with another client's.
2. **Our `Client` code** (error 152). The sample uses `TEST`.
3. **The meaning of `Origin` and `DestinationCode`** (error 151). The sample uses `MMB`, which appears to be an LBC hub or area code, not a PSGC value.
4. Whether **Attachment 1 to 4 and Reference 1 to 4** accept empty strings. All eight are reported mandatory.

## Worth exploring later

The product catalogue lists an **LBC 3PL webhook API**: *"a webhook listener for 3PL partners capable of callbackURL tracking their own internal status and events."* If usable, tracking could be **push** and the SOAP client avoided entirely.

`LBC Pickup Request` also advertises **"Pickup at LBC Branches"**, which is the branch-collection workflow the owner currently arranges by hand.

## 2026-08-17: the test tracking number is somebody else's 2024 test

The owner searched 1234567894444 on LBC's public tracker and found an
old shipment. Re-queried through Track and Trace V2 with our test key,
same answer: one event, PICKED UP BY LBC at LBC CARGO FACILITY-MANILA 2
(CORPORATE, branch SOT03), dated 10/09/2024, remarks "RBRON | TEST PICK
UP". Raw response archived in this session's notes.

What this establishes:

1. The number was already used by an earlier TEST (remarks say so;
   RBRON looks like an LBC staffer or another integrator) years before
   our 2026-08-14 pickup. It is not a live customer parcel.
2. The tracking event we credited to our own end-to-end test was THIS
   2024 entry all along. Our pickup was genuinely accepted (Code 100 is
   our own submission), and AWB rendering is real, but no tracking
   event of ours exists; events appear when a rider scans, and no rider
   scans a test pickup.
3. Track and Trace reads the PRODUCTION tracking database even on the
   test key, and the public website tracker sees the same records.
   There is no separate test tracking store.
4. Live proof of the uniqueness hazard: LBC accepted our pickup on a
   number that already carried history. The allocator remains a
   correctness requirement on our side.

## 2026-08-17: LBC answered the Client code

"Kindly use NGD." Stored in settings as lbc_client_code. In test, any
string was accepted (free text, see above); NGD is the value that
matters for production attribution. Tracking number series: LBC says
to follow.

## 2026-08-17: second test booking, this time with OUR data

Submitted with the store's real default pickup address (Paolo, San
Bartolome, Quezon City), Client NGD (LBC's confirmed value), and a
date-coded tracking number pre-checked clean on the tracker
(2026081700001 -> 201 "No Record Found" before booking).

    PICKUP  IsSuccess true, Code 100, "Successfully saved. 8/17/2026
            2:10:50 PM", TrackingNo 2026081700001
    TRACK   still 201 "No Record Found" after booking: a pickup request
            creates no tracking event; events appear only when a parcel
            is scanned. Verifying a test booking therefore means asking
            LBC to check the PICKUP REQUEST record, never the tracker.

Validation learned by sending our own values first:

1. Code 107 "Shipper Account No. is not valid" when sent as 0. The
   field is validated, and we do not have our account number: ask LBC.
   The successful submission borrowed the PDF sample's 2015062300003,
   clearly marked test.
2. Code 127 "Consignee Send SMS is required" when ConsigneeSendSMS is
   0, while ShipperSendSMS 0 passes. 0 on the consignee flag is treated
   as missing; 1 was required to book.
3. Our own address spellings (Quezon City, Metro Manila, San Bartolome)
   passed pickup validation untouched, so pickup does NOT enforce the
   Common API's exact name vocabulary.
4. The payload archived at the session scratchpad and reproduced in
   full in the LBC correspondence. Attachment slots are NAME/VALUE
   pairs (AttachmentNameOne ORDERNO + ReferenceNoOne, etc.), unused
   slots filled with 0 per the sample: the "are attachments mandatory"
   question is now answered by the sample's own idiom.
5. The PDF's code tables (ServiceMode, ProductLine, PKG, Commodity
   legends, Tables 1.0 and 2.0) are IMAGES in the PDF and cannot be
   read as text: the meanings of ShipmentMode 1 / ProductLine 2 /
   ServiceMode 8 / PKG 16 / Commodity 1 still need LBC's legend.
