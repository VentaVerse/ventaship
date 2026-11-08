# QuadX API reference (X for Developers)

Local copy of QuadX's public developer documentation.

- **Source:** https://docs.quadx.xyz/ (Postman collection `12850780/TVKJyvMn`, version `latest`)
- **Fetched:** 2026-11-08
- **Raw collection:** `postman-collection.json`. Import it into Postman to try the requests.
- This file is generated from the collection and is otherwise unedited. The example JWTs are QuadX's own public samples (issued and expired in 2016).

## Contents

- [Introduction](#introduction)
- [Locations](#locations)
  - [Get Countries](#get-countries)
  - [Get Country by ID](#get-country-by-id)
  - [Get Regions by Country ID](#get-regions-by-country-id)
  - [Get States by Country ID](#get-states-by-country-id)
  - [Get Cities by Country ID](#get-cities-by-country-id)
  - [Get Region by ID](#get-region-by-id)
  - [Get Provinces by Region ID](#get-provinces-by-region-id)
  - [Get Province by ID](#get-province-by-id)
  - [Get Cities by Province ID](#get-cities-by-province-id)
  - [Get City by ID](#get-city-by-id)
  - [Get District by City ID](#get-district-by-city-id)
  - [Get District by ID](#get-district-by-id)
- [Orders](#orders)
  - [Create Orders](#create-orders)
  - [Get Orders](#get-orders)
  - [Get Orders by Tracking Number](#get-orders-by-tracking-number)
  - [Update Order](#update-order)
  - [Set Order Status to cancel](#set-order-status-to-cancel)
  - [Set Order Status to for_pickup](#set-order-status-to-forpickup)
  - [Get List of Statuses](#get-list-of-statuses)
  - [Get Rates](#get-rates)
- [AWB Printing](#awb-printing)
  - [AWB Label Single Print](#awb-label-single-print)
  - [AWB Label Bulk Print](#awb-label-bulk-print)
  - [Poll URL for Bulk Print](#poll-url-for-bulk-print)

## Introduction

Hello and welcome to X for Developers! Here you'll find everything that you need to integrate with us and book your orders. We provide APIs for order creation, status management, and visibility of the whole order lifecycle until fulfillment. 

### What We Will Be Providing 

 

##### Slack Channel & Support 

A Slack Channel will be provided as our medium of communication.  
Inquiries, concerns, and other discussions related to this document and the processes it involves may be raised in the given channel, such as error-handling, process flow clarifications, etc. 

 

Keys 

We will be providing you with a pair of API and Secret keys once you sign up for our services. The API key should be passed as the subject (sub) attribute of the JWT payload while the secret key is used to create the token signature. Never share your secret key and make sure that it would not be exposed at any time. 

### Authorization 

 

Some of the resources defined in this document are secured using [JSON Web Tokens (JWT)]. JWT is an [industry standard] for authorization and exchanging claims between two parties. 

Basic JSON Web Tokens are made up of three parts separated by dots, which are: 
 
- Header 
- Payload 
- Signature 
 

In turn, a JWT ends up looking like the following: 

```
aaaaa.bbbbb.ccccc

```

The following subsections briefly explain these three parts. 

##### Header 

The header is the first part of the token where the token type (typ) and hashing algorithm (alg) used to generate the signature are defined. Only `HMAC SHA256` is supported at the moment. 

###### JSON Header 

```
{
"alg": "HS256",
"typ": "JWT"
}

```

 

This header is then encoded using `Base64Url`. 

The following result is normally the header when dealing with our API: 

###### Base64 Encoded Header (URL-safe) 

```
eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9

```

##### Payload 

The payload is the second part of the token where the claims are defined. The following claims should always be present on the payload: 
 
- **iat** - Unix timestamp of when the token was created 
- **jti** - unique nonce value. Requests with a repeated jti value will be rejected. 
- **sub** - [your API key] 
 

###### JSON Payload 

```
{
"iat": 1463702400,
"jti": "abe953c8-3621-414b-99e9-a01d9461b129",
"sub": "2af9713592"
}

```

 

Like the header, this is then encoded using `Base64Url`. 

###### Sample Generated Base64 Encoded Payload (URL-safe) 

```
eyJpYXQiOjE0NjM3MDI0MDAsImp0aSI6ImFiZTk1M2M4LTM2MjEtNDE0Yi05OWU5LWEwMWQ5NDYxYjEyOSIsInN1YiI6IjJhZjk3MTM1OTIifQ

```

##### Signature 

The last part to be generated is the signature.  
To generate this, concatenate the base64 encoded header and payload with a dot and generate the hash using HMAC SHA256. 

###### For clarity, the process is as follows: 

```
signature = base64EncodeURL( hmac_sha256( base64EncodeURL(header) + '.' + base64EncodeURL(payload), SECRET_KEY ) )

```

 

###### For the purpose of comparison and checking, the produced signature for our previous header and payload examples is: 

```
// when SECRET_KEY = 'itsasecret'
AXXvB0EgZWWTBQZsImnq_M6bHsEbw7H8k7tU_cYZz98

```

##### Resulting JWT 

The token is then built by concatenating dots in between the produced header, payload, and signature. 

It should appear as follows: 

```
(header).(payload).(signature)

```

###### Substituting the header, payload, and signature placeholders with their actual values, we finally have our JWT: 

```
eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpYXQiOjE0NjM3MDI0MDAsImp0aSI6ImFiZTk1M2M4LTM2MjEtNDE0Yi05OWU5LWEwMWQ5NDYxYjEyOSIsInN1YiI6IjJhZjk3MTM1OTIifQ.AXXvB0EgZWWTBQZsImnq_M6bHsEbw7H8k7tU_cYZz98

```

##### Sending the JWT 

The token should be sent as a part of your HTTP Request Header. 

###### Use the Authorization Bearer schema, as seen below: 

```
Authorization: Bearer {token}

```

 

###### Sample Header, using the JWT we generated in the previous example: 

```
POST /v1/orders HTTP/1.1
Host: api.lbcx.ph
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpYXQiOjE0NjM3MDI0MDAsImp0aSI6ImFiZTk1M2M4LTM2MjEtNDE0Yi05OWU5LWEwMWQ5NDYxYjEyOSIsInN1YiI6IjJhZjk3MTM1OTIifQ.3ZbAieutFTwQ0RXidGRCDO_hkSqZNydNz3fcwj3WAbA

```

 

##### Additional Information 

For more clarity on the subject of JWT, you can read the [Official JWT documentation] to get a better idea regarding the token-generating process. You can also download their [3rd party libraries and SDKs] to use in your APIs. 

### Responses (Success, Errors) 

 

Standard HTTP status codes are returned on every response. `200` means that the request was successful, `4xx` indicates failure, and `500` means that something went wrong on our side. The error message and details are sent back as a JSON-encoded string in the response body. Here's a list of status codes that F3 uses: 

| **Status** | **Message** | **Description** |
|---|---|---|
| 200 | OK | Success |
| 400 | Bad Request | We didn't understand your request. Please make sure that you're sending a valid JSON payload in the request body. |
| 401 | Unauthorized | The token is not valid or may have already expired. It could also be that you don't have sufficient privileges to access the resource. |
| 404 | Not Found | The entity that you're looking for does not exist. |
| 413 | Request Entity Too Large | You have exceeded the maximum allowable upload size of 1MB. |
| 422 | Unprocessable Entity | Some of the parameters failed validation. |
| 429 | Too Many Requests | Your account has been temporarily suspended because you have exceeded the allowable number of requests. |
| 500 | Internal Server Error | Something went wrong. We're looking into it. |

 

###### Sample Successful Response 

```
    HTTP/1.1 200 OK
    Content-Type: application/json

```

 

```
  {
    "id": 21,
    "currency": "PHP",
    "reference_id": "7336180193817RYKP",
    "tracking_number": "0000-0021-ZTMR",
    "payment_method": "cod",
    "status": "pending",
    "email": "johndoe@email.com",
    "contact_number": "+639172274819",
    "total": "3103.25",
    "paid": 0,
    "metadata": {
    "key_1": "value_1",
    "key_2": "value_2"
    },
    "preferred_pickup_time": "morning",
    "preferred_delivery_time": "3pm - 5pm",
    "pickup_address": {
    "name": "John Doe",
    "phone_number": "5713429",
    "mobile_number": "+639197421753",
    "line_1": "8F U825 Building A",
    "line_2": "711 Nulla St.",
    "city": "Makati",
    "state": "NCR",
    "postal_code": "1200",
    "remarks": "Optional notes / remarks go here."
    },
    "delivery_address": {
    "name": "Jane Doe",
    "phone_number": "6358972",
    "mobile_number": "+63907117421",
    "line_1": "3F U311 Bldg. C",
    "line_2": "Jade St.",
    "city": "Taguig",
    "state": "NCR",
    "postal_code": "1600",
    "remarks": "Optional notes / remarks go here."
    },
    "charge": {
    "status": "pending",
    "payment_method": "cod",
    "total_amount": 3103.25,
    "tendered_amount": 0,
    "change_amount": 0,
    "remarks": null
    },
    "items": [
      {
        "type": "product",
        "description": "Red Shirt",
        "amount": "1250.00",
        "quantity": 1,
        "metadata": {
          "size": "medium",
          "color": "red"
        }
      },
      {
        "type": "product",
        "description": "Blue Shirt",
        "amount": "700.00",
        "quantity": 2,
        "metadata": {
          "size": "medium",
          "color": "blue"
        }
      },
      {
        "type": "tax",
        "description": "Tax",
        "amount": "132.50",
        "quantity": 1,
        "metadata": null
      },
      {
        "type": "shipping",
        "description": "Expedited Shipping",
        "amount": "250.00",
        "quantity": 1,
        "metadata": null
      },
      {
        "type": "fee",
        "description": "Handling Fee",
        "amount": "20.00",
        "quantity": 1,
        "metadata": null
      },
      {
        "type": "fee",
        "description": "Gift Wrapping Fee",
        "amount": "50.75",
        "quantity": 1,
        "metadata": null
      }
    ]
  }

```

 

###### Sample Error Response (422) 

```
    HTTP/1.1 422 Unprocessable Entity
    Content-Type: application/json

```

 

```
  {
    "status": 422,
    "message": "Unprocessable Entity",
    "description": "The given data failed to pass validation.",
    "parameters": {
      "payment_method": [
        "The selected payment method is invalid."
      ]
    }
  }

```

 

### Pagination 

 

All API resources that return lists or bulk data support pagination and share the same parameters.  
All of these parameters are optional and have default values.  
Pagination data is returned on both the header and response body for convenience. 

| **Name** | **Type** | **Description** | **Required?** | **Default Value** |
|---|---|---|---|---|
| page | integer | Page Number | optional | 1 |
| per_page | integer | Count per Page | optional | 25 |

 

###### Sample Response with Pagination 

```
    HTTP/1.1 200 OK
    Content-Type: application/json
    Link: <https://api.lbcx.ph/v1/orders?page=2>; rel="next"
    X-Pagination-Current-Page: 1
    X-Pagination-Page-Count: 8
    X-Pagination-Per-Page: 25
    X-Pagination-Total-Count: 192
    X-Powered-By: PHP/5.6.2

```

 

```
  {
    "total": 192,
    "per_page": 25,
    "current_page": 1,
    "last_page": 8,
    "next_page_url": "https://api.lbcx.ph/v1/orders?page=2",
    "prev_page_url": null,
    "from": 1,
    "to": 25,
    "data": [
      {
        "id": 1,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0001-BSWF",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 2,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0002-SRFT",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 3,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0003-QMVL",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 4,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0004-VLSN",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 5,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0005-NQYS",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 6,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0006-ACZM",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 7,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0007-MAGY",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 8,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0008-KQNS",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 9,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0009-VDYH",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 10,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0010-JQXZ",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 11,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0011-ATHR",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 12,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0012-WCFM",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 13,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0013-ERTC",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 14,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0014-VLKU",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 15,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0015-TRXY",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 16,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0016-VHLD",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 17,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0017-WXHS",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 18,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0018-EKSQ",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 19,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0019-SYCA",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 20,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0020-WZGL",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 28,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0028-TWMB",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 21,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0021-ZTMR",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 22,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0022-RSBY",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 23,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0023-NDEH",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      },
      {
        "id": 24,
        "currency": "PHP",
        "reference_id": "7336180193817RYKP",
        "tracking_number": "0000-0024-PXYM",
        "payment_method": "cod",
        "status": "pending",
        "email": "johndoe@email.com",
        "contact_number": "+639172274819",
        "total": "3103.25",
        "paid": 0,
        "metadata": {
          "key_1": "value_1",
          "key_2": "value_2"
        },
        "preferred_pickup_time": "morning",
        "preferred_delivery_time": "3pm - 5pm"
      }
    ]
  }

```

 

### Webhooks 

 

A webhook is an HTTP callback that you define to process incoming events or status updates from our API.  
We currently support both **HTTP** and **HTTPS** URLs but we advise you to use HTTPS as much as possible to ensure security. An alternate security measure you can do is to append a token to the URL in order to make it “unguessable” to the public.  
We only send JSON data to your webhook URL via HTTP POST and expect a **200 HTTP status code** in the response. Anything other than a 200 status code will be treated as failure and the request will be retried at a later time.  
There is currently no user interface for setting the webhook URL so please send us an email or message us on your designated slack channel if you need to have it changed. 

#### **Multiple Events per Tracking Number** 

The webhook payload may contain multiple events associated with the same tracking number. This can occur when there are updates to the shipment status or other relevant information during the delivery process. 

Clients can distinguish between these events using the following approaches: 
 
1. **Event ID**: Each event is assigned a unique incremental `event_id`. Events for the same tracking number will have different `event_id` values, allowing you to identify and sort them accordingly. 
1. **Event Timestamp**: The `status_updated_at` field represents the timestamp when the event occurred. Events for the same tracking number will have different `status_updated_at` values, enabling you to order them chronologically. 
 

It is recommended to process the events in the order they are received or based on the `status_updated_at` field to ensure accurate tracking of the shipment's progress. Clients can ignore outdated events and consider only the latest event from the payload for the most up-to-date shipment status. 

**Special Handling for End States** 

There is a possibility that `status_updated_at` dates for events representing end states (e.g., delivered, cancelled, or returned) may be erroneous. To account for this, clients should accept all end state events, regardless of any date issues, and consider them as valid updates for the shipment status. 

#### Format 

Every JSON payload sent to your webhook will have the following attributes: 

| Name | Type | Description | Required? | Default Value |
|---|---|---|---|---|
| `event` | string | type of event | yes | `status_update` |
| `timestamp` | integer | Date and time in unix time when the data was sent to the webhook. | yes | N/A |
| `data` | json array | Array of events; historical data. | yes | N/A |

 

###### Sample Payload 

```
    POST /your/webhook HTTP/1.1
    Content-Type: application/json

```

 

```
  {
    "event": "status_update",
    "timestamp": 1512551041,
    "data": [{
        "event_id": 35862967,
        "tracking_number": "0495-0754-VZXJ",
        "reference_id": "17120514285DPEK.42ecdce0-d185-4356-a30f-d33ea14cff05",
        "created_at": "2017-12-06 16:41:31.898782+08",
        "status_updated_at": "2017-12-06 16:41:21+08",
        "status": "picked_up",
        "remarks": "Package successfully picked up",
        "fees": {
          "shipping_fee": 100,
          "insurance_fee": 9,
          "transaction_fee": 27
        },
        "party": {
          "id": 6,
          "external_id": null
        },
        "parent": {
          "id": null,
          "tracking_number": null,
          "reference_id": null
        }
      },
      {
        "event_id": 35862969,
        "tracking_number": "0476-8835-FHUL",
        "reference_id": "17120110305AQXW.db11da3c-15f9-4a79-a605-2aa59600790f",
        "created_at": "2017-12-06 16:41:31.939001+08",
        "status_updated_at": "2017-12-06 16:04:06+08",
        "status": "in_transit",
        "remarks": "FORWARDED TO PUERTO PRINCESA DELIVERY HUB",
        "fees": {
          "shipping_fee": 100,
          "insurance_fee": 5,
          "transaction_fee": 20
        },
        "party": {
          "id": 6,
          "external_id": null
        },
        "parent": {
          "id": null,
          "tracking_number": null,
          "reference_id": null
        }
      },
      {
        "event_id": 35862972,
        "tracking_number": "0479-8119-CWAX",
        "reference_id": "17120109493993N.88b7c455-cf29-404c-a91f-a398278ad1fd",
        "created_at": "2017-12-06 16:41:32.475252+08",
        "status_updated_at": "2017-12-06 16:20:19+08",
        "status": "in_transit",
        "remarks": "RECEIVED AT SOUTH LUZON AREA",
        "fees": {
          "shipping_fee": 100,
          "insurance_fee": 6.1,
          "transaction_fee": 20
        },
        "party": {
          "id": 6,
          "external_id": null
        },
        "parent": {
          "id": null,
          "tracking_number": null,
          "reference_id": null
        }
      },
      {
        "event_id": 35862977,
        "tracking_number": "0481-9668-HAJN",
        "reference_id": "17120113387HYRH.3623ee76-ee52-44a0-b890-f2bdc661078e",
        "created_at": "2017-12-06 16:41:33.064148+08",
        "status_updated_at": "2017-12-06 16:37:43+08",
        "status": "in_transit",
        "remarks": "RECEIVED AT SOUTH LUZON AREA",
        "fees": {
          "shipping_fee": 100,
          "insurance_fee": 5.52,
          "transaction_fee": 20
        },
        "party": {
          "id": 6,
          "external_id": null
        },
        "parent": {
          "id": null,
          "tracking_number": null,
          "reference_id": null
        }
      },
      {
        "event_id": 35862981,
        "tracking_number": "0492-6744-JFQD",
        "reference_id": "17120411264R1YD.8b95ac0c-4466-4053-bd03-ce1c9723b8fc",
        "created_at": "2017-12-06 16:41:33.193278+08",
        "status_updated_at": "2017-12-06 16:41:22+08"
      }
    ]
  }

```

 

#### Retries 

Our system has a retry protocol whenever we get an error response from the client (i.e. a non-200 HTTP status code).  
Our retry phases are as follows: 
 
1. Retry the failed message every 5 minutes for a maximum of 5 times. 
1. If the status update still fails after the 5th retry, its contents go to our dead letter queue (DLQ) for manual investigation. 
1. We (QuadX) report the failure to the client. 
 

!

## Locations

The following endpoints return information regarding Locations. It is advised to use this API in order to have a uniform format for addresses. Country, Region, State, City, Province, and District are the parameters that make up the address format.

### Get Countries

`GET {{base_url}}/locations/countries`

Returns a list of countries.

**Example response: Get Countries** (200 OK)

```json
{
  "total": 5,
  "per_page": 100,
  "current_page": 1,
  "last_page": 1,
  "next_page_url": null,
  "prev_page_url": null,
  "from": 1,
  "to": 5,
  "data": [
    {
      "id": 89844,
      "code": "CN",
      "name": "China",
      "type": "country",
      "parent_id": null,
      "postal_code": null
    },
    {
      "id": 36275,
      "code": "MY",
      "name": "Malaysia",
      "type": "country",
      "parent_id": null,
      "postal_code": null
    },
    {
      "id": 1,
      "code": "PH",
      "name": "Philippines",
      "type": "country",
      "parent_id": null,
      "postal_code": null
    },
    {
      "id": 143413,
      "code": "UK",
      "name": "United Kingdom",
      "type": "country",
      "parent_id": null,
      "postal_code": null
    },
    {
      "id": 36273,
      "code": "US",
      "name": "United States Of America",
      "type": "country",
      "parent_id": null,
      "postal_code": null
    }
  ]
}
```

**Example response: Get Countries** (200 OK)

```json
{
  "total": 5,
  "per_page": 100,
  "current_page": 1,
  "last_page": 1,
  "next_page_url": null,
  "prev_page_url": null,
  "from": 1,
  "to": 5,
  "data": [
    {
      "id": 89844,
      "code": "CN",
      "name": "China",
      "type": "country",
      "parent_id": null,
      "postal_code": null
    },
    {
      "id": 36275,
      "code": "MY",
      "name": "Malaysia",
      "type": "country",
      "parent_id": null,
      "postal_code": null
    },
    {
      "id": 1,
      "code": "PH",
      "name": "Philippines",
      "type": "country",
      "parent_id": null,
      "postal_code": null
    },
    {
      "id": 143413,
      "code": "UK",
      "name": "United Kingdom",
      "type": "country",
      "parent_id": null,
      "postal_code": null
    },
    {
      "id": 36273,
      "code": "US",
      "name": "United States Of America",
      "type": "country",
      "parent_id": null,
      "postal_code": null
    }
  ]
}
```

### Get Country by ID

`GET {{base_url}}/locations/countries/{{id}}`

Returns a country by ID or the two letter ISO country code (eg. PH,EU,etc.).

**Example response: Example** (200 OK)

```json
{
  "id": 44295,
  "code": "PH",
  "name": "Philippines",
  "type": "country",
  "parent_id": null,
  "postal_code": null
}
```

### Get Regions by Country ID

`GET {{base_url}}/locations/countries/{{id}}/regions`

Returns a list of regions for the given country.

**Example response: Example** (200 OK)

```json
{
  "total": 4,
  "per_page": 100,
  "current_page": 1,
  "last_page": 1,
  "next_page_url": null,
  "prev_page_url": null,
  "from": 1,
  "to": 4,
  "data": [
    {
      "id": 1,
      "code": null,
      "name": "Luzon",
      "type": "region",
      "parent_id": 44295,
      "postal_code": null
    },
    {
      "id": 4,
      "code": null,
      "name": "Metro Manila",
      "type": "region",
      "parent_id": 44295,
      "postal_code": null
    },
    {
      "id": 3,
      "code": null,
      "name": "Mindanao",
      "type": "region",
      "parent_id": 44295,
      "postal_code": null
    },
    {
      "id": 2,
      "code": null,
      "name": "Visayas",
      "type": "region",
      "parent_id": 44295,
      "postal_code": null
    }
  ]
}
```

### Get States by Country ID

`GET {{base_url}}/locations/countries/{{id}}/provinces`

Returns a list of states for the given country.

**Example response: Example** (200 OK)

```json
{
  "total": 81,
  "per_page": 100,
  "current_page": 1,
  "last_page": 1,
  "next_page_url": null,
  "prev_page_url": null,
  "from": 1,
  "to": 81,
  "data": [
    {
      "id": 5,
      "code": null,
      "name": "Abra",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 1420,
      "code": null,
      "name": "Agusan del Norte",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 2436,
      "code": null,
      "name": "Agusan del Sur",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 336,
      "code": null,
      "name": "Aklan",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 681,
      "code": null,
      "name": "Albay",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 1827,
      "code": null,
      "name": "Antique",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 1686,
      "code": null,
      "name": "Apayao",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 2765,
      "code": null,
      "name": "Aurora",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 5285,
      "code": null,
      "name": "Basilan",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 2925,
      "code": null,
      "name": "Bataan",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 4091,
      "code": null,
      "name": "Batanes",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 5554,
      "code": null,
      "name": "Batangas",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 3176,
      "code": null,
      "name": "Benguet",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 3461,
      "code": null,
      "name": "Biliran",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 4127,
      "code": null,
      "name": "Bohol",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 3602,
      "code": null,
      "name": "Bukidnon",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 6668,
      "code": null,
      "name": "Bulacan",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 8520,
      "code": null,
      "name": "Cagayan",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 9683,
      "code": null,
      "name": "Camarines Norte",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 10469,
      "code": null,
      "name": "Camarines Sur",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 9619,
      "code": null,
      "name": "Camiguin",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 9978,
      "code": null,
      "name": "Capiz",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 11570,
      "code": null,
      "name": "Catanduanes",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 11897,
      "code": null,
      "name": "Cavite",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 7262,
      "code": null,
      "name": "Cebu",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 9370,
      "code": null,
      "name": "Compostela Valley",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 29313,
      "code": null,
      "name": "Cotabato (North Cotabato)",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 13590,
      "code": null,
      "name": "Davao del Norte",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 13054,
      "code": null,
      "name": "Davao del Sur",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 12859,
      "code": null,
      "name": "Davao Oriental",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 12751,
      "code": null,
      "name": "Dinagat Islands",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 13825,
      "code": null,
      "name": "Eastern Samar",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 14446,
      "code": null,
      "name": "Guimaras",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 15643,
      "code": null,
      "name": "Ifugao",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 17776,
      "code": null,
      "name": "Ilocos Norte",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 18357,
      "code": null,
      "name": "Ilocos Sur",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 15830,
      "code": null,
      "name": "Iloilo",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 14550,
      "code": null,
      "name": "Isabela",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 19160,
      "code": null,
      "name": "Kalinga",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 21006,
      "code": null,
      "name": "Laguna",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 21713,
      "code": null,
      "name": "Lanao del Norte",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 22243,
      "code": null,
      "name": "Lanao del Sur",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 23443,
      "code": null,
      "name": "La Union",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 19321,
      "code": null,
      "name": "Leyte",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 25294,
      "code": null,
      "name": "Maguindanao",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 28491,
      "code": null,
      "name": "Marinduque",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 24040,
      "code": null,
      "name": "Masbate",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 25877,
      "code": null,
      "name": "Metro Manila",
      "type": "state",
      "parent_id": 4,
      "postal_code": null
    },
    {
      "id": 24786,
      "code": null,
      "name": "Misamis Occidental",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 27960,
      "code": null,
      "name": "Misamis Oriental",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 29158,
      "code": null,
      "name": "Mountain Province",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 29875,
      "code": null,
      "name": "Negros Occidental",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 31453,
      "code": null,
      "name": "Negros Oriental",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 32036,
      "code": null,
      "name": "Northern Samar",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 30570,
      "code": null,
      "name": "Nueva Ecija",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 32630,
      "code": null,
      "name": "Nueva Vizcaya",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 24612,
      "code": null,
      "name": "Occidental Mindoro",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 28716,
      "code": null,
      "name": "Oriental Mindoro",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 32921,
      "code": null,
      "name": "Palawan",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 33380,
      "code": null,
      "name": "Pampanga",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 33941,
      "code": null,
      "name": "Pangasinan",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 35493,
      "code": null,
      "name": "Quezon",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 35354,
      "code": null,
      "name": "Quirino",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 36778,
      "code": null,
      "name": "Rizal",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 36981,
      "code": null,
      "name": "Romblon",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 38386,
      "code": null,
      "name": "Samar (Western Samar)",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 37456,
      "code": null,
      "name": "Sarangani",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 39364,
      "code": null,
      "name": "Siquijor",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 39505,
      "code": null,
      "name": "Sorsogon",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 37218,
      "code": null,
      "name": "South Cotabato",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 37866,
      "code": null,
      "name": "Southern Leyte",
      "type": "state",
      "parent_id": 2,
      "postal_code": null
    },
    {
      "id": 37604,
      "code": null,
      "name": "Sultan Kudarat",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 40748,
      "code": null,
      "name": "Sulu",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 40391,
      "code": null,
      "name": "Surigao del Norte",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 40062,
      "code": null,
      "name": "Surigao del Sur",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 41178,
      "code": null,
      "name": "Tarlac",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 41708,
      "code": null,
      "name": "Tawi-Tawi",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 41923,
      "code": null,
      "name": "Zambales",
      "type": "state",
      "parent_id": 1,
      "postal_code": null
    },
    {
      "id": 42185,
      "code": null,
      "name": "Zamboanga del Norte",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 42904,
      "code": null,
      "name": "Zamboanga del Sur",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    },
    {
      "id": 43712,
      "code": null,
      "name": "Zamboanga Sibugay",
      "type": "state",
      "parent_id": 3,
      "postal_code": null
    }
  ]
}
```

### Get Cities by Country ID

`GET {{base_url}}/locations/countries/{{id}}/cities`

Returns a list of cities for the given country.

**Example response: Example** (200 OK)

```json
{
  "total": 1634,
  "per_page": 100,
  "current_page": 1,
  "last_page": 17,
  "next_page_url": "http://api.staging.lbcx.ph/v1/locations/countries/PH/cities?page=2",
  "prev_page_url": null,
  "from": 1,
  "to": 100,
  "data": [
    {
      "id": 32936,
      "code": null,
      "name": "Aborlan",
      "type": "city",
      "parent_id": 32921,
      "postal_code": null
    },
    {
      "id": 24613,
      "code": null,
      "name": "Abra de Ilog",
      "type": "city",
      "parent_id": 24612,
      "postal_code": null
    },
    {
      "id": 2926,
      "code": null,
      "name": "Abucay",
      "type": "city",
      "parent_id": 2925,
      "postal_code": null
    },
    {
      "id": 8564,
      "code": null,
      "name": "Abulug",
      "type": "city",
      "parent_id": 8520,
      "postal_code": null
    },
    {
      "id": 19394,
      "code": null,
      "name": "Abuyog",
      "type": "city",
      "parent_id": 19321,
      "postal_code": null
    },
    {
      "id": 17777,
      "code": null,
      "name": "Adams",
      "type": "city",
      "parent_id": 17776,
      "postal_code": null
    },
    {
      "id": 35514,
      "code": null,
      "name": "Agdangan",
      "type": "city",
      "parent_id": 35493,
      "postal_code": null
    },
    {
      "id": 35355,
      "code": null,
      "name": "Aglipay",
      "type": "city",
      "parent_id": 35354,
      "postal_code": null
    },
    {
      "id": 34045,
      "code": null,
      "name": "Agno",
      "type": "city",
      "parent_id": 33941,
      "postal_code": null
    },
    {
      "id": 5575,
      "code": null,
      "name": "Agoncillo",
      "type": "city",
      "parent_id": 5554,
      "postal_code": null
    },
    {
      "id": 23469,
      "code": null,
      "name": "Agoo",
      "type": "city",
      "parent_id": 23443,
      "postal_code": null
    },
    {
      "id": 34063,
      "code": null,
      "name": "Aguilar",
      "type": "city",
      "parent_id": 33941,
      "postal_code": null
    },
    {
      "id": 15675,
      "code": null,
      "name": "Aguinaldo",
      "type": "city",
      "parent_id": 15643,
      "postal_code": null
    },
    {
      "id": 32956,
      "code": null,
      "name": "Agutaya",
      "type": "city",
      "parent_id": 32921,
      "postal_code": null
    },
    {
      "id": 15905,
      "code": null,
      "name": "Ajuy",
      "type": "city",
      "parent_id": 15830,
      "postal_code": null
    },
    {
      "id": 5303,
      "code": null,
      "name": "Akbar",
      "type": "city",
      "parent_id": 5285,
      "postal_code": null
    },
    {
      "id": 35494,
      "code": null,
      "name": "Alabat",
      "type": "city",
      "parent_id": 35493,
      "postal_code": null
    },
    {
      "id": 37457,
      "code": null,
      "name": "Alabel",
      "type": "city",
      "parent_id": 37456,
      "postal_code": null
    },
    {
      "id": 29334,
      "code": null,
      "name": "Alamada",
      "type": "city",
      "parent_id": 29313,
      "postal_code": null
    },
    {
      "id": 33942,
      "code": null,
      "name": "Alaminos",
      "type": "city",
      "parent_id": 33941,
      "postal_code": null
    },
    {
      "id": 21007,
      "code": null,
      "name": "Alaminos",
      "type": "city",
      "parent_id": 21006,
      "postal_code": null
    },
    {
      "id": 19322,
      "code": null,
      "name": "Alangalang",
      "type": "city",
      "parent_id": 19321,
      "postal_code": null
    },
    {
      "id": 5286,
      "code": null,
      "name": "Al-Barka",
      "type": "city",
      "parent_id": 5285,
      "postal_code": null
    },
    {
      "id": 19377,
      "code": null,
      "name": "Albuera",
      "type": "city",
      "parent_id": 19321,
      "postal_code": null
    },
    {
      "id": 4128,
      "code": null,
      "name": "Alburquerque",
      "type": "city",
      "parent_id": 4127,
      "postal_code": null
    },
    {
      "id": 33982,
      "code": null,
      "name": "Alcala",
      "type": "city",
      "parent_id": 33941,
      "postal_code": null
    },
    {
      "id": 8585,
      "code": null,
      "name": "Alcala",
      "type": "city",
      "parent_id": 8520,
      "postal_code": null
    },
    {
      "id": 7328,
      "code": null,
      "name": "Alcantara",
      "type": "city",
      "parent_id": 7262,
      "postal_code": null
    },
    {
      "id": 36982,
      "code": null,
      "name": "Alcantara",
      "type": "city",
      "parent_id": 36981,
      "postal_code": null
    },
    {
      "id": 7263,
      "code": null,
      "name": "Alcoy",
      "type": "city",
      "parent_id": 7262,
      "postal_code": null
    },
    {
      "id": 7272,
      "code": null,
      "name": "Alegria",
      "type": "city",
      "parent_id": 7262,
      "postal_code": null
    },
    {
      "id": 40392,
      "code": null,
      "name": "Alegria",
      "type": "city",
      "parent_id": 40391,
      "postal_code": null
    },
    {
      "id": 29314,
      "code": null,
      "name": "Aleosan",
      "type": "city",
      "parent_id": 29313,
      "postal_code": null
    },
    {
      "id": 11925,
      "code": null,
      "name": "Alfonso",
      "type": "city",
      "parent_id": 11897,
      "postal_code": null
    },
    {
      "id": 32640,
      "code": null,
      "name": "Alfonso Castaneda",
      "type": "city",
      "parent_id": 32630,
      "postal_code": null
    },
    {
      "id": 15644,
      "code": null,
      "name": "Alfonso Lista (Potia)",
      "type": "city",
      "parent_id": 15643,
      "postal_code": null
    },
    {
      "id": 30571,
      "code": null,
      "name": "Aliaga",
      "type": "city",
      "parent_id": 30570,
      "postal_code": null
    },
    {
      "id": 43713,
      "code": null,
      "name": "Alicia",
      "type": "city",
      "parent_id": 43712,
      "postal_code": null
    },
    {
      "id": 4157,
      "code": null,
      "name": "Alicia",
      "type": "city",
      "parent_id": 4127,
      "postal_code": null
    },
    {
      "id": 14611,
      "code": null,
      "name": "Alicia",
      "type": "city",
      "parent_id": 14550,
      "postal_code": null
    },
    {
      "id": 18358,
      "code": null,
      "name": "Alilem",
      "type": "city",
      "parent_id": 18357,
      "postal_code": null
    },
    {
      "id": 15831,
      "code": null,
      "name": "Alimodian",
      "type": "city",
      "parent_id": 15830,
      "postal_code": null
    },
    {
      "id": 5555,
      "code": null,
      "name": "Alitagtag",
      "type": "city",
      "parent_id": 5554,
      "postal_code": null
    },
    {
      "id": 8611,
      "code": null,
      "name": "Allacapan",
      "type": "city",
      "parent_id": 8520,
      "postal_code": null
    },
    {
      "id": 32037,
      "code": null,
      "name": "Allen",
      "type": "city",
      "parent_id": 32036,
      "postal_code": null
    },
    {
      "id": 38387,
      "code": null,
      "name": "Almagro",
      "type": "city",
      "parent_id": 38386,
      "postal_code": null
    },
    {
      "id": 3462,
      "code": null,
      "name": "Almeria",
      "type": "city",
      "parent_id": 3461,
      "postal_code": null
    },
    {
      "id": 7338,
      "code": null,
      "name": "Aloguinsan",
      "type": "city",
      "parent_id": 7262,
      "postal_code": null
    },
    {
      "id": 24787,
      "code": null,
      "name": "Aloran",
      "type": "city",
      "parent_id": 24786,
      "postal_code": null
    },
    {
      "id": 337,
      "code": null,
      "name": "Altavas",
      "type": "city",
      "parent_id": 336,
      "postal_code": null
    },
    {
      "id": 27961,
      "code": null,
      "name": "Alubijid",
      "type": "city",
      "parent_id": 27960,
      "postal_code": null
    },
    {
      "id": 11898,
      "code": null,
      "name": "Amadeo",
      "type": "city",
      "parent_id": 11897,
      "postal_code": null
    },
    {
      "id": 32631,
      "code": null,
      "name": "Ambaguio",
      "type": "city",
      "parent_id": 32630,
      "postal_code": null
    },
    {
      "id": 31454,
      "code": null,
      "name": "Amlan (Ayuquitan)",
      "type": "city",
      "parent_id": 31453,
      "postal_code": null
    },
    {
      "id": 25295,
      "code": null,
      "name": "Ampatuan",
      "type": "city",
      "parent_id": 25294,
      "postal_code": null
    },
    {
      "id": 8639,
      "code": null,
      "name": "Amulung",
      "type": "city",
      "parent_id": 8520,
      "postal_code": null
    },
    {
      "id": 37867,
      "code": null,
      "name": "Anahawan",
      "type": "city",
      "parent_id": 37866,
      "postal_code": null
    },
    {
      "id": 41179,
      "code": null,
      "name": "Anao",
      "type": "city",
      "parent_id": 41178,
      "postal_code": null
    },
    {
      "id": 4140,
      "code": null,
      "name": "Anda",
      "type": "city",
      "parent_id": 4127,
      "postal_code": null
    },
    {
      "id": 34004,
      "code": null,
      "name": "Anda",
      "type": "city",
      "parent_id": 33941,
      "postal_code": null
    },
    {
      "id": 14551,
      "code": null,
      "name": "Angadanan",
      "type": "city",
      "parent_id": 14550,
      "postal_code": null
    },
    {
      "id": 6669,
      "code": null,
      "name": "Angat",
      "type": "city",
      "parent_id": 6668,
      "postal_code": null
    },
    {
      "id": 33381,
      "code": null,
      "name": "Angeles City",
      "type": "city",
      "parent_id": 33380,
      "postal_code": null
    },
    {
      "id": 36779,
      "code": null,
      "name": "Angono",
      "type": "city",
      "parent_id": 36778,
      "postal_code": null
    },
    {
      "id": 15883,
      "code": null,
      "name": "Anilao",
      "type": "city",
      "parent_id": 15830,
      "postal_code": null
    },
    {
      "id": 1828,
      "code": null,
      "name": "Anini-y",
      "type": "city",
      "parent_id": 1827,
      "postal_code": null
    },
    {
      "id": 4173,
      "code": null,
      "name": "Antequera",
      "type": "city",
      "parent_id": 4127,
      "postal_code": null
    },
    {
      "id": 29381,
      "code": null,
      "name": "Antipas",
      "type": "city",
      "parent_id": 29313,
      "postal_code": null
    },
    {
      "id": 36790,
      "code": null,
      "name": "Antipolo",
      "type": "city",
      "parent_id": 36778,
      "postal_code": null
    },
    {
      "id": 33415,
      "code": null,
      "name": "Apalit",
      "type": "city",
      "parent_id": 33380,
      "postal_code": null
    },
    {
      "id": 8521,
      "code": null,
      "name": "Aparri",
      "type": "city",
      "parent_id": 8520,
      "postal_code": null
    },
    {
      "id": 32922,
      "code": null,
      "name": "Araceli",
      "type": "city",
      "parent_id": 32921,
      "postal_code": null
    },
    {
      "id": 29352,
      "code": null,
      "name": "Arakan",
      "type": "city",
      "parent_id": 29313,
      "postal_code": null
    },
    {
      "id": 33428,
      "code": null,
      "name": "Arayat",
      "type": "city",
      "parent_id": 33380,
      "postal_code": null
    },
    {
      "id": 7282,
      "code": null,
      "name": "Argao",
      "type": "city",
      "parent_id": 7262,
      "postal_code": null
    },
    {
      "id": 23444,
      "code": null,
      "name": "Aringay",
      "type": "city",
      "parent_id": 23443,
      "postal_code": null
    },
    {
      "id": 32647,
      "code": null,
      "name": "Aritao",
      "type": "city",
      "parent_id": 32630,
      "postal_code": null
    },
    {
      "id": 24041,
      "code": null,
      "name": "Aroroy",
      "type": "city",
      "parent_id": 24040,
      "postal_code": null
    },
    {
      "id": 13826,
      "code": null,
      "name": "Arteche",
      "type": "city",
      "parent_id": 13825,
      "postal_code": null
    },
    {
      "id": 34023,
      "code": null,
      "name": "Asingan",
      "type": "city",
      "parent_id": 33941,
      "postal_code": null
    },
    {
      "id": 15665,
      "code": null,
      "name": "Asipulo",
      "type": "city",
      "parent_id": 15643,
      "postal_code": null
    },
    {
      "id": 7354,
      "code": null,
      "name": "Asturias",
      "type": "city",
      "parent_id": 7262,
      "postal_code": null
    },
    {
      "id": 13591,
      "code": null,
      "name": "Asuncion (Saug)",
      "type": "city",
      "parent_id": 13590,
      "postal_code": null
    },
    {
      "id": 35527,
      "code": null,
      "name": "Atimonan",
      "type": "city",
      "parent_id": 35493,
      "postal_code": null
    },
    {
      "id": 3177,
      "code": null,
      "name": "Atok",
      "type": "city",
      "parent_id": 3176,
      "postal_code": null
    },
    {
      "id": 14646,
      "code": null,
      "name": "Aurora",
      "type": "city",
      "parent_id": 14550,
      "postal_code": null
    },
    {
      "id": 42905,
      "code": null,
      "name": "Aurora",
      "type": "city",
      "parent_id": 42904,
      "postal_code": null
    },
    {
      "id": 31463,
      "code": null,
      "name": "Ayungon",
      "type": "city",
      "parent_id": 31453,
      "postal_code": null
    },
    {
      "id": 10470,
      "code": null,
      "name": "Baao",
      "type": "city",
      "parent_id": 10469,
      "postal_code": null
    },
    {
      "id": 19458,
      "code": null,
      "name": "Babatngon",
      "type": "city",
      "parent_id": 19321,
      "postal_code": null
    },
    {
      "id": 682,
      "code": null,
      "name": "Bacacay",
      "type": "city",
      "parent_id": 681,
      "postal_code": null
    },
    {
      "id": 17779,
      "code": null,
      "name": "Bacarra",
      "type": "city",
      "parent_id": 17776,
      "postal_code": null
    },
    {
      "id": 4227,
      "code": null,
      "name": "Baclayon",
      "type": "city",
      "parent_id": 4127,
      "postal_code": null
    },
    {
      "id": 23519,
      "code": null,
      "name": "Bacnotan",
      "type": "city",
      "parent_id": 23443,
      "postal_code": null
    },
    {
      "id": 28717,
      "code": null,
      "name": "Baco",
      "type": "city",
      "parent_id": 28716,
      "postal_code": null
    },
    {
      "id": 21714,
      "code": null,
      "name": "Bacolod",
      "type": "city",
      "parent_id": 21713,
      "postal_code": null
    },
    {
      "id": 29876,
      "code": null,
      "name": "Bacolod City",
      "type": "city",
      "parent_id": 29875,
      "postal_code": null
    },
    {
      "id": 22315,
      "code": null,
      "name": "Bacolod-Kalawi (Bacolod Grande)",
      "type": "city",
      "parent_id": 22243,
      "postal_code": null
    },
    {
      "id": 33459,
      "code": null,
      "name": "Bacolor",
      "type": "city",
      "parent_id": 33380,
      "postal_code": null
    },
    {
      "id": 31488,
      "code": null,
      "name": "Bacong",
      "type": "city",
      "parent_id": 31453,
      "postal_code": null
    }
  ]
}
```

### Get Region by ID

`GET {{base_url}}/locations/regions/{{id}}`

Returns a region by ID.

**Example response: Example** (200 OK)

```json
{
  "total": 4,
  "per_page": 100,
  "current_page": 1,
  "last_page": 1,
  "next_page_url": null,
  "prev_page_url": null,
  "from": 1,
  "to": 4,
  "data": [
    {
      "id": 1,
      "code": null,
      "name": "Luzon",
      "type": "region",
      "parent_id": 44295,
      "postal_code": null
    },
    {
      "id": 4,
      "code": null,
      "name": "Metro Manila",
      "type": "region",
      "parent_id": 44295,
      "postal_code": null
    },
    {
      "id": 3,
      "code": null,
      "name": "Mindanao",
      "type": "region",
      "parent_id": 44295,
      "postal_code": null
    },
    {
      "id": 2,
      "code": null,
      "name": "Visayas",
      "type": "region",
      "parent_id": 44295,
      "postal_code": null
    }
  ]
}
```

### Get Provinces by Region ID

`GET {{base_url}}/locations/regions/{{id}}/provinces`

Returns a list of provinces for the given region.

**Example response: Example** (200 OK)

```json
{
  "total": 17,
  "per_page": 100,
  "current_page": 1,
  "last_page": 1,
  "next_page_url": null,
  "prev_page_url": null,
  "from": 1,
  "to": 17,
  "data": [
    {
      "id": 25878,
      "code": null,
      "name": "Caloocan City",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 26070,
      "code": null,
      "name": "Las Pinas",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 26131,
      "code": null,
      "name": "Makati",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 26279,
      "code": null,
      "name": "Malabon",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 26097,
      "code": null,
      "name": "Mandaluyong",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 26302,
      "code": null,
      "name": "Manila",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 27272,
      "code": null,
      "name": "Marikina",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 27290,
      "code": null,
      "name": "Muntinlupa",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 27305,
      "code": null,
      "name": "Navotas",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 27404,
      "code": null,
      "name": "Paranaque City",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 27444,
      "code": null,
      "name": "Pasay City",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 27321,
      "code": null,
      "name": "Pasig City",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 27433,
      "code": null,
      "name": "Pateros",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 27662,
      "code": null,
      "name": "Quezon City",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 27857,
      "code": null,
      "name": "San Juan",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 27890,
      "code": null,
      "name": "Taguig City",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    },
    {
      "id": 27923,
      "code": null,
      "name": "Valenzuela",
      "type": "city",
      "parent_id": 25877,
      "postal_code": null
    }
  ]
}
```

### Get Province by ID

`GET {{base_url}}/locations/provinces/{{id}}`

Returns a state/province by ID.

**Example response: Example** (200 OK)

```json
{
  "id": 5554,
  "code": null,
  "name": "Batangas",
  "type": "state",
  "parent_id": 1,
  "postal_code": null
}
```

### Get Cities by Province ID

`GET {{base_url}}/locations/provinces/{{id}}/cities`

Returns a list of cities for the given province.

**Example response: Get Cities by Province ID** (200 OK)

```json
{
  "total": 4,
  "per_page": 100,
  "current_page": 1,
  "last_page": 1,
  "next_page_url": null,
  "prev_page_url": null,
  "from": 1,
  "to": 4,
  "data": [
    {
      "id": 18131,
      "code": null,
      "name": "Bangued",
      "type": "city",
      "parent_id": 18130,
      "postal_code": null
    },
    {
      "id": 18146,
      "code": null,
      "name": "Pidigan",
      "type": "city",
      "parent_id": 18130,
      "postal_code": null
    },
    {
      "id": 18156,
      "code": null,
      "name": "San Quintin",
      "type": "city",
      "parent_id": 18130,
      "postal_code": null
    },
    {
      "id": 18160,
      "code": null,
      "name": "Tayum",
      "type": "city",
      "parent_id": 18130,
      "postal_code": null
    }
  ]
}
```

### Get City by ID

`GET {{base_url}}/locations/cities/{{id}}`

Returns a city by ID.

**Example response: Example** (200 OK)

```json
{
  "id": 79,
  "code": null,
  "name": "Dolores",
  "type": "city",
  "parent_id": 5,
  "postal_code": null
}
```

### Get District by City ID

`GET {{base_url}}/locations/cities/{{id}}/districts`

Returns a list of districts for the given city.

**Example response: Example** (200 OK)

```json
{
  "total": 15,
  "per_page": 100,
  "current_page": 1,
  "last_page": 1,
  "next_page_url": null,
  "prev_page_url": null,
  "from": 1,
  "to": 15,
  "data": [
    {
      "id": 80,
      "code": null,
      "name": "Bayaan",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    },
    {
      "id": 81,
      "code": null,
      "name": "Cabaroan",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    },
    {
      "id": 82,
      "code": null,
      "name": "Calumbaya",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    },
    {
      "id": 83,
      "code": null,
      "name": "Cardona",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    },
    {
      "id": 84,
      "code": null,
      "name": "Isit",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    },
    {
      "id": 85,
      "code": null,
      "name": "Kimmalaba",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    },
    {
      "id": 86,
      "code": null,
      "name": "Libtec",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    },
    {
      "id": 87,
      "code": null,
      "name": "Lub-lubba",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    },
    {
      "id": 88,
      "code": null,
      "name": "Mudiit",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    },
    {
      "id": 89,
      "code": null,
      "name": "Namit-ingan",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    },
    {
      "id": 90,
      "code": null,
      "name": "Pacac",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    },
    {
      "id": 91,
      "code": null,
      "name": "Poblacion",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    },
    {
      "id": 92,
      "code": null,
      "name": "Salucag",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    },
    {
      "id": 93,
      "code": null,
      "name": "Talogtog",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    },
    {
      "id": 94,
      "code": null,
      "name": "Taping",
      "type": "district",
      "parent_id": 79,
      "postal_code": "2801"
    }
  ]
}
```

### Get District by ID

`GET {{base_url}}/locations/district/{{id}}`

Returns a district by ID. 

!

**Example response: Example** (200 OK)

```json
{
  "id": 107,
  "code": null,
  "name": "Poblacion",
  "type": "district",
  "parent_id": 103,
  "postal_code": "2824"
}
```

## Orders

The following endpoints return information regarding Orders. This API is the core of all order-related processes, from order creation, to getting orders and their statuses.

### Create Orders

`POST {{base_url}}/orders`

Creates an order for shipment. Only authorized clients of QuadX can create orders. 

An order is defined by a sender/shipper, a pickup address, the items (or parcels) being sent, the buyer, the recipient/consignee (possibly different from buyer), their delivery address, and payment information. 

! 

##### Address Data 

A delivery address is required to create an order. A pickup address can be provided, but is only required once the order has been tagged as "ready for pickup". An address is comprised of the following fields: 

| Field Name, Type, Character Limit, Required? | Sample Value | Definition |
|---|---|---|
| `name` *string* 255 characters *required* | `John Doe` | name of the sender/consignee |
| `company` *string* 255 characters *optional* | `QuadX` | company name (if applicable) |
| `title` *string* 50 characters *optional* | `Mr.` | title (eg, Mr., Mrs., Ms., Officer) |
| `email` *string* 50 characters *optional* | johndoe@quadx.xyz | email address |
| `phone_number` *string* 50 characters *optional* | `4331234` | phone number (landline) |
| `mobile_number` *string* 50 characters *optional* | `09171234567` | mobile number |
| `fax_number` *string* 50 characters *optional* | `4331234` | fax number |
| `district` *string* 300 characters *optional* | `Poblacion` | district |
| `line_1` *string* 500 characters *required* | `2F U221 Bldg. A` | address line 1 |
| `line_2` *string* 500 characters *optional* | `Emerald St.` | address line 2 (if applicable) |
| `city` *string* 300 characters *required* | `Makati` | city |
| `state` *string* 300 characters *required* | `Metro Manila` | state or province |
| `postal_code` *string* 50 characters *required* | `1600` | postal or zip code |
| `country` *string* 50 characters *required* | `PH` | 2-letter [ISO country code](https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2) (eg, US) |
| `remarks` *string* 500 characters *optional* | `imported items; kindly double check` | additional descriptive notes |

 

! 

##### Product Data 

It is also required to pass the items which comprise the order. This is used as the basis for printing the waybill, as well as computing insurance, shipping and transaction costs. "items" is an array of items, each of which is described by the following fields: 

| Field Name, Type, Character Limit, Required? | Sample Value | Definition |
|---|---|---|
| `type` *string* 10 characters *required* | `tax` | one of product, shipping, tax, fee, insurance, discount (see below for definitions) |
| `description` *string* 500 characters *required* | `Bracelet` | item description |
| `amount` *float* *required* | `123.45` | amount of item (rounded to two decimal places) |
| `quantity` *integer* *required* | `2` | number of items (defaults to 1, applicable only to products) |
| `preferred_pickup_time` *string* 100 characters *optional* | `morning` | descriptive string re: preferred time of pickup |
| `preferred_delivery_time` *string* 100 characters *optional* | `2pm-5pm` | descriptive string re: preferred time of pickup |
| `metadata` *jsonb* *optional* | {"product_image": "https://cdn.checkmeout.ph/v1/e87.png"} | additional JSON-encoded metadata |

 

##### Item Type Data 

Each item can be one of several types. At least one "product" must be present, with the others being optional. 

| Field Name | Required? | Definition |
|---|---|---|
| `product` | yes; at least 1 | at least 1 product entry is required |
| `shipping` | optional | shipping fee charged, if any |
| `tax` | optional | tax charged, if any |
| `fee` | optional | additional fees charged, if any |
| `insurance` | optional | insurance charged, if any |
| `discount` | optional | discounts applied, if any (this must <= 0)) |

 

! 

##### Payment Data 

Payment details consist of the currency, total amount, payment method and provider: 

| Field Name, Type, Character Limit, Required? | Sample Value | Definition |
|---|---|---|
| `total` *float* *required; at least 1* | `305.63` | total value of the order, must match sum total of order items |
| `currency` *string* 5 characters *required* | `PHP` | 3-letter [ISO currency code](https://en.wikipedia.org/wiki/ISO_4217) (eg, PHP) |
| `payment_method` *string* 15 characters *required* | `credit_card` | must be one of the following: `credit_card`, `debit_card`, `otc`, `cod`, or `other` |
| `payment_provider` *string* 15 characters *required* | `paypal` | must be one of the following: `asiapay`, `dragonpay`, `lbc`, `lbcx`, `paypal`, or `other` |
| `seller_payment_method` *string* *optional* | `cash` | This field represents the payment method utilized to collect service fees, encompassing shipping and insurance costs, at the point of pickup from the seller. available options: `cash`, `gcash` or `null` |

 

**Note:** If `seller_payment_method` is omitted, service fees will be automatically billed and/or deducted from the disbursal as applicable. 

! 

##### Remaining Fields 

The remaining fields of an order are: 

| Field Name, Type, Character Limit, Required? | Sample Value | Definition |
|---|---|---|
| `shipment` *string* 50 characters *required* | `small-pouch` | type of package with possible values: `small-pouch, medium-pouch, big-pouch, box, oversized, custom (requires parcel dimensions and weight)` |
| `buyer_name` *string* 100 characters *required; at least 1* | `Jane Doe` | buyer's name |
| `buyer_id` *integer* | `123` | buyer's account id |
| `email` *string* 50 characters *required* | `janedoe@quadx.xyz` | buyer's email address (one of email/contact number is required) |
| `contact_number` *string* 50 characters *required* | `4331234` | buyer's contact number (one of email/contact number is required) |
| `status` *string* 15 characters *optional* | `pending` | one of the following: `pending`, `confirmed` or `for_pickup` (defaults to pending) |
| `reference_id` *string* *optional* | `QGZ63AWA` | unique reference id for order |
| `own_print` *boolean* *optional* | `true` | if `true`, client will print label, if `false`, QuadX will print the label. defaults to `false` if not passed. |
| `pickup_total` *float* *optional* | `200.00` | indicates the amount the rider needs to collect from the merchant. defaults to 0 if not passed. if passed, should be a value greater than 0. |
| `metadata` *jsonb* *optional* | {"shop_id": "12345"} | additional JSON-encoded metadata |

 

###### Custom Package Requirements 

When selecting `custom` as the package type, you must provide the parcel dimensions and weight: 
 
- 

**Dimensions**: Length, width, and height of the parcel in centimeters (cm).  
- 

**Weight**: Weight of the parcel in kilograms (kg).

**Headers**

| Key | Value | Notes |
|---|---|---|
| `Content-Type` | `application/json` |  |
| `Authorization` | `{{authorization}}` |  |

**Request body**

```json
{
  "status": "for_pickup",
  "delivery_address": {
    "name": "Test buyer",
    "company": "Maxis",
    "phone_number": "6358972",
    "mobile_number": "+63907117421",
    "line_1": "3F U311 Bldg. C",
    "line_2": "Jade St.",
    "district": "San Fernando",
    "city": "Mangaldan",
    "state": "Pangasinan",
    "postal_code": "4233",
    "country": "PH",
    "remarks": "Optional notes / remarks go here."
  },
  "pickup_address": {
    "name": "JJJ. Doe",
    "company": "Maxis",
    "phone_number": "6358972",
    "mobile_number": "+63907117421",
    "line_1": "3F U311 Bldg. C",
    "line_2": "Jade St.",
    "city": "Baguio City",
    "state": "Benguet",
    "postal_code": "1226",
    "country": "PH",
    "remarks": "Optional notes / remarks go here."
  },
  "return_address": {
    "name": "JJ. ABC",
    "company": "Maxis",
    "phone_number": "6358972",
    "mobile_number": "+63907117421",
    "line_1": "3F U311 Bldg. C",
    "line_2": "Jade St.",
    "city": "Baguio City",
    "state": "Benguet",
    "postal_code": "1226",
    "country": "PH",
    "remarks": "Optional notes / remarks go here."
  },
  "contact_number": "639178117983",
  "buyer_name": "Angelica Mae Anacion",
  "parcel": {
    "dimensions": {
      "width": 10,
      "length": 10,
      "uom": "cm",
      "height": 10
    },
    "weight": {
      "value": 4.0,
      "uom": "kg"
    }
  },
  "payment_provider": "other",
  "payment_method": "other",
  "items": [
    {
      "type": "product",
      "description": "Red Shirt",
      "amount": 1250,
      "quantity": 1,
      "metadata": {
        "size": "medium",
        "color": "red"
      }
    },
    {
      "type": "product",
      "description": "Blue Shirt",
      "amount": 700,
      "quantity": 2,
      "metadata": {
        "size": "medium",
        "color": "blue"
      }
    },
    {
      "type": "tax",
      "description": "Tax",
      "amount": 132.5
    },
    {
      "type": "shipping",
      "description": "Expedited Shipping",
      "amount": 150
    },
    {
      "type": "fee",
      "description": "Handling Fee",
      "amount": 20
    },
    {
      "type": "fee",
      "description": "Gift Wrapping Fee",
      "amount": 50.75
    },
    {
      "type": "insurance",
      "description": "Insurance",
      "amount": 150
    }
  ],
  "currency": "PHP",
  "shipment": "custom",
  "total": 3153.25
}
```

**Example response: Create Orders** (200 OK)

```json
{
  "id": 8074443,
  "party_id": 20596,
  "service_id": 10801,
  "currency": "PHP",
  "reference_id": null,
  "tracking_number": "5ALH-AKML-3JPE",
  "payment_method": "other",
  "payment_provider": "other",
  "seller_payment_method": null,
  "seller_payment_provider": null,
  "service": "next_day",
  "status": "for_pickup",
  "status_id": "1200",
  "status_remarks": null,
  "status_updated_at": "2025-10-29 09:18:18.957433+00",
  "buyer_name": "Angelica Mae Anacion",
  "email": null,
  "contact_number": "639178117983",
  "subtotal": "2650.00",
  "tax": "0.00",
  "fee": "0.00",
  "insurance": "0.00",
  "discount": "0.00",
  "shipping": "0.00",
  "total": "2650.00",
  "transaction_fee": "0.00",
  "insurance_fee": "21.50",
  "shipping_fee": "130.00",
  "return_fee": "86.50",
  "metadata": {
    "original_fees": {
      "shipping_fee": 130
    },
    "original_shipment": "custom"
  },
  "pickup_time_slot": null,
  "delivery_time_slot": null,
  "created_at": "2025-10-29 09:18:18+00",
  "tat": {
    "sla_start": 1761753600,
    "for_pickup": 1761729498,
    "first_pickup_at": 1761753600,
    "first_estimated_delivery_date": 1761955200
  },
  "shipment": "custom",
  "remarks": null,
  "buyer_id": null,
  "parcel": {
    "for_pickup": {
      "weight": {
        "uom": "kg",
        "value": 4
      },
      "dimensions": {
        "uom": "cm",
        "width": 10,
        "height": 10,
        "length": 10
      },
      "parcel_type": "pouch",
      "tpl_parcel_type": "medium-pouch",
      "chargeable_weight": {
        "uom": "kg",
        "value": 4
      }
    }
  },
  "estimated_delivery_date": "2025-11-01 00:00:00+00",
  "updated_at": "2025-10-29 09:18:19+00",
  "pickup_at": "2025-10-30 00:00:00+0800",
  "pickup_attempts": 0,
  "channel_id": null,
  "declared_value": "2650.00",
  "tracking_links": null,
  "client": "GOGO",
  "own_print": false,
  "agent": null,
  "failure_reason": null,
  "fees_adjustment": null,
  "rebooking": null,
  "pickup_total": "0.00",
  "channel": null,
  "organization": {
    "party_id": 20596,
    "name": "GOGO Staging",
    "external_id": "sLGYskcOZpPFycVCTBeFctUxv4N2",
    "is_new_merchant": true
  },
  "pickup_address": {
    "id": 22214011,
    "code": null,
    "name": "JJJ. Doe",
    "title": null,
    "email": null,
    "phone_number": "6358972",
    "mobile_number": "+63907117421",
    "fax_number": null,
    "company": "Maxis",
    "line_1": "3F U311 Bldg. C",
    "line_2": "Jade St.",
    "district": "Pio del Pilar",
    "city": "Makati City",
    "state": "Metro Manila",
    "postal_code": "1226",
    "region": "MMB",
    "country": {
      "code": "PH",
      "name": "Philippines"
    },
    "xcode": null,
    "location": null,
    "address_type": null,
    "remarks": "Optional notes / remarks go here.",
    "city_approximation": "Makati City",
    "branch_code": null
  },
  "delivery_address": {
    "id": 22214012,
    "code": null,
    "name": "Test buyer",
    "title": null,
    "email": null,
    "phone_number": "6358972",
    "mobile_number": "+63907117421",
    "fax_number": null,
    "company": "Maxis",
    "line_1": "3F U311 Bldg. C",
    "line_2": "Jade St.",
    "district": "San Fernando",
    "city": "Mangaldan",
    "state": "Pangasinan",
    "postal_code": "4233",
    "region": "NL",
    "country": {
      "code": "PH",
      "name": "Philippines"
    },
    "xcode": null,
    "location": null,
    "address_type": null,
    "remarks": "Optional notes / remarks go here.",
    "city_approximation": "Mangaldan"
  },
  "return_address": {
    "id": 22214013,
    "code": null,
    "name": "JJ. ABC",
    "title": null,
    "email": null,
    "phone_number": "6358972",
    "mobile_number": "+63907117421",
    "fax_number": null,
    "company": "Maxis",
    "line_1": "3F U311 Bldg. C",
    "line_2": "Jade St.",
    "district": "Pio del Pilar",
    "city": "Makati City",
    "state": "Metro Manila",
    "postal_code": "1226",
    "region": "MMB",
    "country": {
      "code": "PH",
      "name": "Philippines"
    },
    "xcode": null,
    "location": null,
    "address_type": null,
    "remarks": "Optional notes / remarks go here."
  },
  "dropoff_address": {
    "id": null,
    "code": null,
    "name": null,
    "title": null,
    "email": null,
    "phone_number": null,
    "mobile_number": null,
    "fax_number": null,
    "company": null,
    "line_1": null,
    "line_2": null,
    "district": null,
    "city": null,
    "state": null,
    "postal_code": null,
    "region": null,
    "country": {
      "code": null,
      "name": null
    },
    "xcode": null,
    "address_type": null,
    "remarks": null
  },
  "charge": {
    "order_id": null,
    "status": null,
    "reference_id": null,
    "total_amount": null,
    "tendered_amount": null,
    "change_amount": null,
    "remarks": null,
    "created_at": null,
    "updated_at": null
  },
  "claim": {
    "status": null,
    "amount": null,
    "shipping_fee_flag": null,
    "insurance_fee_flag": null,
    "transaction_fee_flag": null,
    "assets": null,
    "reason": null,
    "remarks": null,
    "created_at": null,
    "updated_at": null,
    "tat": null,
    "reference_id": null
  },
  "buyer_name_parsed": {
    "first_name": "Angelica Mae Anacion",
    "middle_name": null,
    "last_name": null
  },
  "items": [
    {
      "type": "product",
      "description": "Red Shirt",
      "amount": "1250.00",
      "quantity": 1,
      "total": "1250.00",
      "metadata": {
        "size": "medium",
        "color": "red"
      },
      "insured_value": null
    },
    {
      "type": "product",
      "description": "Blue Shirt",
      "amount": "700.00",
      "quantity": 2,
      "total": "1400.00",
      "metadata": {
        "size": "medium",
        "color": "blue"
      },
      "insured_value": null
    }
  ],
  "segments": [
    {
      "courier": "Central Hub",
      "type": "pick_up",
      "barcode_format": "qr",
      "tat": {
        "end_date": null,
        "start_date": null
      },
      "shipping_type": "land",
      "currency": null,
      "amount": null,
      "reference_id": "5ALH-AKML-3JPE",
      "active": true,
      "hub_code": "CTR",
      "pickup_address": {
        "name": "JJJ. Doe",
        "title": null,
        "email": null,
        "phone_number": "6358972",
        "mobile_number": "+63907117421",
        "fax_number": null,
        "company": "Maxis",
        "line_1": "3F U311 Bldg. C",
        "line_2": "Jade St.",
        "district": "Pio del Pilar",
        "city": "Makati City",
        "state": "Metro Manila",
        "postal_code": "1226",
        "region": "MMB",
        "country": {
          "code": "PH",
          "name": "Philippines"
        },
        "xcode": null,
        "remarks": "Optional notes / remarks go here."
      },
      "delivery_address": {
        "name": "Central Hub",
        "title": null,
        "email": null,
        "phone_number": null,
        "mobile_number": null,
        "fax_number": null,
        "company": null,
        "line_1": "Allegro Center",
        "line_2": null,
        "district": "Magallanes",
        "city": "Makati",
        "state": "Metro Manila",
        "postal_code": "1232",
        "region": "MMB",
        "country": {
          "code": "PH",
          "name": "Philippines"
        },
        "xcode": null,
        "remarks": null
      }
    },
    {
      "courier": "Dagupan01 Hub",
      "type": "delivery",
      "barcode_format": "qr",
      "tat": {
        "end_date": null,
        "start_date": null
      },
      "shipping_type": "land",
      "currency": null,
      "amount": null,
      "reference_id": "71001381169128",
      "active": false,
      "hub_code": "DGP",
      "pickup_address": {
        "name": "Dagupan01 HUB",
        "title": null,
        "email": null,
        "phone_number": null,
        "mobile_number": null,
        "fax_number": null,
        "company": null,
        "line_1": "BHF Family Plaza Warehouse",
        "line_2": null,
        "district": "Mayombo",
        "city": "Dagupan City",
        "state": "Pangasinan",
        "postal_code": "-",
        "region": "NL",
        "country": {
          "code": "PH",
          "name": "Philippines"
        },
        "xcode": null,
        "remarks": null
      },
      "delivery_address": {
        "name": "Test buyer",
        "title": null,
        "email": null,
        "phone_number": "6358972",
        "mobile_number": "+63907117421",
        "fax_number": null,
        "company": "Maxis",
        "line_1": "3F U311 Bldg. C",
        "line_2": "Jade St.",
        "district": "San Fernando",
        "city": "Mangaldan",
        "state": "Pangasinan",
        "postal_code": "4233",
        "region": "NL",
        "country": {
          "code": "PH",
          "name": "Philippines"
        },
        "xcode": null,
        "remarks": "Optional notes / remarks go here."
      }
    }
  ],
  "children": []
}
```

### Get Orders

`GET {{base_url}}/orders`

Returns a list of orders. Only basic order details such as id, currency, amount, etc. will be returned. Pagination details will also be included on both the JSON payload and response headers.

**Headers**

| Key | Value | Notes |
|---|---|---|
| `Authorization` | `{{authorization}}` |  |

**Example response: Example** (200 OK)

```json
{
  "total": 2,
  "per_page": 25,
  "current_page": 1,
  "last_page": 1,
  "next_page_url": null,
  "prev_page_url": null,
  "from": 1,
  "to": 2,
  "data": [
    {
      "id": 58,
      "currency": "PHP",
      "reference_id": "PZZSJDYC",
      "tracking_number": "0000-0058-WJGH",
      "payment_method": "cod",
      "payment_provider": "lbcx",
      "status": "for_pickup",
      "buyer_name": "John Doe",
      "email": "johndoe@email.com",
      "contact_number": "+639172274819",
      "subtotal": "2650.00",
      "tax": "132.50",
      "fee": "70.75",
      "insurance": "150.00",
      "shipping": "250.00",
      "total": "3253.25",
      "transaction_fee": "97.60",
      "insurance_fee": "32.53",
      "shipping_fee": "150.00",
      "metadata": {
        "key_1": "value_1",
        "key_2": "value_2"
      },
      "preferred_pickup_time": "morning",
      "preferred_delivery_time": "3pm - 5pm",
      "created_at": "2016-12-01 07:29:58+08",
      "buyer_name_parsed": {
        "first_name": "John",
        "middle_name": null,
        "last_name": "Doe"
      }
    },
    {
      "id": 57,
      "currency": "PHP",
      "reference_id": "ZMVA34FC",
      "tracking_number": "0000-0057-GWVQ",
      "payment_method": "cod",
      "payment_provider": "lbcx",
      "status": "delivered",
      "buyer_name": "John Doe",
      "email": "johndoe@email.com",
      "contact_number": "+639172274819",
      "subtotal": "2650.00",
      "tax": "132.50",
      "fee": "70.75",
      "insurance": "150.00",
      "shipping": "250.00",
      "total": "3253.25",
      "transaction_fee": "97.60",
      "insurance_fee": "32.53",
      "shipping_fee": "150.00",
      "metadata": {
        "key_1": "value_1",
        "key_2": "value_2"
      },
      "preferred_pickup_time": "morning",
      "preferred_delivery_time": "3pm - 5pm",
      "created_at": "2016-11-29 13:01:30+08",
      "buyer_name_parsed": {
        "first_name": "John",
        "middle_name": null,
        "last_name": "Doe"
      }
    }
  ]
}
```

### Get Orders by Tracking Number

`GET {{base_url}}/orders/0075-7540-EMBN`

Returns the details of an order by tracking number. Use the unique ID that was returned when you created an order. All order details are returned including the order items, charge details, pick up and delivery addresses.

**Headers**

| Key | Value | Notes |
|---|---|---|
| `Authorization` | `{{authorization}}` |  |

**Example response: Get Orders by Tracking Number** (200 OK)

```json
{
  "id": 7961326,
  "party_id": 7941,
  "service_id": 7941,
  "currency": "PHP",
  "reference_id": null,
  "tracking_number": "RVM7-6TPJ-8XA1",
  "payment_method": "cod",
  "payment_provider": "lbcx",
  "seller_payment_method": null,
  "seller_payment_provider": null,
  "service": "next_day",
  "status": "delivered",
  "status_id": "2200",
  "status_remarks": "Parcel received by Consignee Thirdy",
  "status_updated_at": "2023-07-04 02:11:14+00",
  "buyer_name": "Thirdy",
  "email": "test@mailinator.com",
  "contact_number": "09478746796",
  "subtotal": "1000.00",
  "tax": "0.00",
  "fee": "0.00",
  "insurance": "0.00",
  "discount": "0.00",
  "shipping": "0.00",
  "total": "1000.00",
  "transaction_fee": "20.00",
  "insurance_fee": "10.00",
  "shipping_fee": "100.00",
  "return_fee": "210.00",
  "metadata": {
    "item_type": "Not Dangerous",
    "jobData_otp": "0000",
    "original_fees": {
      "shipping_fee": 100
    },
    "original_shipment": "medium-pouch",
    "allowable_parcel_sizes": null,
    "fieldData_receiver_name": "NA"
  },
  "preferred_pickup_time": null,
  "preferred_delivery_time": null,
  "created_at": "2023-06-30 13:08:07+00",
  "tat": {
    "delivered": 1688436674,
    "picked_up": 1688130904,
    "sla_start": 1688140800,
    "for_pickup": 1688130487,
    "out_for_pickup": 1688130883,
    "failed_delivery": 1688131298,
    "first_pickup_at": 1688140800,
    "out_for_delivery": 1688436592,
    "pickup_rider_found": 1688130837,
    "received_at_pickup_hub": 1688130964,
    "received_at_delivery_hub": 1688131014,
    "first_estimated_delivery_date": 1688428800
  },
  "shipment": "medium-pouch",
  "remarks": null,
  "buyer_id": null,
  "parcel": {
    "for_pickup": {
      "parcel_type": "pouch"
    }
  },
  "estimated_delivery_date": "2023-07-04 16:00:00+00",
  "updated_at": "2023-07-04 02:11:27.987139+00",
  "pickup_at": "2023-06-30 16:00:00+00",
  "pickup_attempts": 0,
  "channel_id": null,
  "client": "Meork",
  "own_print": false,
  "agent": {
    "hub": "makati hub",
    "code": "ebel_fe_01_lbc",
    "name": "ebel rider",
    "location": {
      "latitude": 14.1321236,
      "longitude": 120.9554095
    },
    "mobile_number": "09478746796"
  },
  "failure_reason": null,
  "fees_adjustment": null,
  "rebooking": null,
  "pickup_total": "0.00",
  "channel": null,
  "organization": {
    "party_id": 7941,
    "name": "Meork",
    "external_id": "4718b791-46ed-403c-b615-558ef5369674"
  },
  "pickup_address": {
    "id": 21871410,
    "code": null,
    "name": "Niki",
    "title": null,
    "email": "niki@yopmail.com",
    "phone_number": "639279502775",
    "mobile_number": "639279502775",
    "fax_number": null,
    "company": "Test Shop",
    "line_1": "San Lorenzo Ruiz Monument, Rizal Park, Roxas Blvd., Ermita, Manila, Metro Manila",
    "line_2": null,
    "district": "Ermita",
    "city": "Manila",
    "state": "Metro Manila",
    "postal_code": "1000",
    "region": "MMB",
    "country": {
      "code": "PH",
      "name": "Philippines"
    },
    "xcode": "MM06000",
    "location": null,
    "address_type": null,
    "city_approximation": "Manila",
    "branch_code": null
  },
  "delivery_address": {
    "id": 21871411,
    "code": null,
    "name": "Thirdy",
    "title": null,
    "email": null,
    "phone_number": "639279502775",
    "mobile_number": "639279502775",
    "fax_number": null,
    "company": null,
    "line_1": "1242 Sto. Nino, Ssmpaloc, Manila",
    "line_2": "",
    "district": "Barangay 586",
    "city": "Sampaloc",
    "state": "Metro Manila",
    "postal_code": "3020",
    "region": "MMB",
    "country": {
      "code": "PH",
      "name": "Philippines"
    },
    "xcode": "MM06529",
    "location": null,
    "address_type": null,
    "city_approximation": "Sampaloc"
  },
  "return_address": {
    "id": 21871412,
    "code": null,
    "name": "Niki",
    "title": null,
    "email": "niki@yopmail.com",
    "phone_number": "639279502775",
    "mobile_number": "639279502775",
    "fax_number": null,
    "company": "Test Shop",
    "line_1": "San Lorenzo Ruiz Monument, Rizal Park, Roxas Blvd., Ermita, Manila, Metro Manila",
    "line_2": null,
    "district": "Ermita",
    "city": "Manila",
    "state": "Metro Manila",
    "postal_code": "1000",
    "region": "MMB",
    "country": {
      "code": "PH",
      "name": "Philippines"
    },
    "xcode": "MM06000",
    "location": null,
    "address_type": null
  },
  "dropoff_address": {
    "id": null,
    "code": null,
    "name": null,
    "title": null,
    "email": null,
    "phone_number": null,
    "mobile_number": null,
    "fax_number": null,
    "company": null,
    "line_1": null,
    "line_2": null,
    "district": null,
    "city": null,
    "state": null,
    "postal_code": null,
    "region": null,
    "country": {
      "code": null,
      "name": null
    },
    "xcode": null,
    "address_type": null
  },
  "charge": {
    "order_id": 7961326,
    "status": "paid",
    "reference_id": "71001380954496",
    "total_amount": "1000.00",
    "tendered_amount": "1000.00",
    "change_amount": "0.00",
    "remarks": null,
    "created_at": "2023-06-30 13:08:07+00",
    "updated_at": "2023-07-04 02:11:28+00"
  },
  "claim": {
    "status": null,
    "amount": null,
    "shipping_fee_flag": null,
    "insurance_fee_flag": null,
    "transaction_fee_flag": null,
    "assets": null,
    "reason": null,
    "remarks": null,
    "created_at": null,
    "updated_at": null,
    "tat": null,
    "reference_id": null
  },
  "buyer_name_parsed": {
    "first_name": "Thirdy",
    "middle_name": null,
    "last_name": null
  },
  "items": [
    {
      "type": "product",
      "description": "Bike",
      "amount": "1000.00",
      "quantity": 1,
      "total": "1000.00",
      "metadata": null,
      "insured_value": null
    }
  ],
  "segments": [
    {
      "courier": "Manila Hub",
      "type": "pick_up",
      "barcode_format": "qr",
      "tat": {
        "end_date": null,
        "start_date": null
      },
      "shipping_type": "land",
      "currency": null,
      "amount": null,
      "reference_id": "RVM7-6TPJ-8XA1",
      "active": false,
      "hub_code": "MNL",
      "pickup_address": {
        "name": "Niki",
        "title": null,
        "email": "niki@yopmail.com",
        "phone_number": "639279502775",
        "mobile_number": "639279502775",
        "fax_number": null,
        "company": "Test Shop",
        "line_1": "San Lorenzo Ruiz Monument, Rizal Park, Roxas Blvd., Ermita, Manila, Metro Manila",
        "line_2": null,
        "district": "Ermita",
        "city": "Manila",
        "state": "Metro Manila",
        "postal_code": "1000",
        "region": "MMB",
        "country": {
          "code": "PH",
          "name": "Philippines"
        },
        "xcode": "MM06000"
      },
      "delivery_address": {
        "name": "MANILA HUB",
        "title": null,
        "email": null,
        "phone_number": null,
        "mobile_number": null,
        "fax_number": null,
        "company": null,
        "line_1": "7A MH Del Pilar St, East Grace Park",
        "line_2": null,
        "district": "Barangay 41",
        "city": "Caloocan City",
        "state": "Metro Manila",
        "postal_code": "-",
        "region": "Metro Manila",
        "country": {
          "code": "PH",
          "name": "Philippines"
        },
        "xcode": null
      },
      "hub_zone": "Z01"
    },
    {
      "courier": "Manila Hub",
      "type": "delivery",
      "barcode_format": "qr",
      "tat": {
        "end_date": null,
        "start_date": null
      },
      "shipping_type": "land",
      "currency": null,
      "amount": null,
      "reference_id": "71001380954496",
      "active": true,
      "hub_code": "MNL",
      "pickup_address": {
        "name": "MANILA HUB",
        "title": null,
        "email": null,
        "phone_number": null,
        "mobile_number": null,
        "fax_number": null,
        "company": null,
        "line_1": "7A MH Del Pilar St, East Grace Park",
        "line_2": null,
        "district": "Barangay 41",
        "city": "Caloocan City",
        "state": "Metro Manila",
        "postal_code": "-",
        "region": "Metro Manila",
        "country": {
          "code": "PH",
          "name": "Philippines"
        },
        "xcode": null
      },
      "delivery_address": {
        "name": "Thirdy",
        "title": null,
        "email": null,
        "phone_number": "639279502775",
        "mobile_number": "639279502775",
        "fax_number": null,
        "company": null,
        "line_1": "1242 Sto. Nino, Ssmpaloc, Manila",
        "line_2": "",
        "district": "Barangay 586",
        "city": "Sampaloc",
        "state": "Metro Manila",
        "postal_code": "3020",
        "region": "MMB",
        "country": {
          "code": "PH",
          "name": "Philippines"
        },
        "xcode": "MM06529"
      },
      "hub_zone": "Z01"
    }
  ],
  "events": [
    {
      "id": 19474405,
      "status": "pickup_rider_found",
      "status_id": "5600",
      "created_at": "2023-06-30 13:14:08.474865+00",
      "remarks": "Pickup rider found",
      "agent": {
        "hub": "north hub",
        "location": {
          "latitude": null,
          "longitude": null
        }
      },
      "hubs": {
        "origin": "north hub"
      },
      "failure_reason": null,
      "photo": null,
      "signature": null,
      "status_updated_at": "2023-06-30 13:13:57+00",
      "status_name": "Pickup rider found"
    },
    {
      "id": 19474419,
      "status": "out_for_pickup",
      "status_id": "5300",
      "created_at": "2023-06-30 13:15:02.862216+00",
      "remarks": "Rider is out for pickup",
      "agent": {
        "hub": "north hub",
        "code": "fe_deguzman_lbc",
        "name": "fe rider deguzman",
        "location": {
          "latitude": 14.7868708,
          "longitude": 121.0118349
        },
        "mobile_number": "09999999999"
      },
      "hubs": {
        "origin": "north hub"
      },
      "failure_reason": null,
      "photo": null,
      "signature": null,
      "status_updated_at": "2023-06-30 13:14:43+00",
      "status_name": "Out for pickup"
    },
    {
      "id": 19474424,
      "status": "picked_up",
      "status_id": "1400",
      "created_at": "2023-06-30 13:15:21.281774+00",
      "remarks": "Package successfully picked up",
      "agent": {
        "hub": "north hub",
        "code": "fe_deguzman_lbc",
        "name": "fe rider deguzman",
        "location": {
          "latitude": 14.7868708,
          "longitude": 121.0118349
        },
        "mobile_number": "09999999999"
      },
      "hubs": {
        "origin": "north hub"
      },
      "failure_reason": null,
      "photo": "https://epod.staging.quadx.xyz/photos/epod-RVM7-6TPJ-8XA1-1688130904.jpg",
      "signature": "https://epod.staging.quadx.xyz/signatures/epod-RVM7-6TPJ-8XA1-1688130904.jpg",
      "status_updated_at": "2023-06-30 13:15:04+00",
      "status_name": "Picked up"
    },
    {
      "id": 19474435,
      "status": "received_at_pickup_hub",
      "status_id": "2900",
      "created_at": "2023-06-30 13:16:17.38148+00",
      "remarks": "Received by NORTH HUB",
      "agent": {
        "hub": "north hub",
        "location": {
          "latitude": null,
          "longitude": null
        }
      },
      "hubs": {
        "origin": "north hub"
      },
      "failure_reason": null,
      "photo": null,
      "signature": null,
      "status_updated_at": "2023-06-30 13:16:04+00",
      "status_name": "Received at pickup hub"
    },
    {
      "id": 19474446,
      "status": "received_at_delivery_hub",
      "status_id": "3100",
      "created_at": "2023-06-30 13:17:08.791099+00",
      "remarks": "Ready for delivery",
      "agent": {
        "hub": "makati hub",
        "code": "deguzman_lbc",
        "location": {
          "latitude": 0,
          "longitude": 0
        }
      },
      "hubs": {
        "origin": "makati hub"
      },
      "failure_reason": null,
      "photo": null,
      "signature": null,
      "status_updated_at": "2023-06-30 13:16:54+00",
      "status_name": "Received at delivery hub"
    },
    {
      "id": 19474469,
      "status": "out_for_delivery",
      "status_id": "1501",
      "created_at": "2023-06-30 13:19:28.672761+00",
      "remarks": "Out for delivery",
      "agent": {
        "hub": "north hub",
        "code": "fe_deguzman_lbc",
        "name": "fe rider deguzman",
        "location": {
          "latitude": 14.7868708,
          "longitude": 121.0118349
        },
        "mobile_number": "09999999999"
      },
      "hubs": {
        "origin": "north hub"
      },
      "failure_reason": null,
      "photo": null,
      "signature": null,
      "status_updated_at": "2023-06-30 13:19:13+00",
      "status_name": "Out for delivery"
    },
    {
      "id": 19474489,
      "status": "failed_delivery",
      "status_id": "1700",
      "created_at": "2023-06-30 13:21:54.097649+00",
      "remarks": "Delivery unsuccessful due to: Acts of God - Weather Disturbance",
      "agent": {
        "hub": "north hub",
        "code": "fe_deguzman_lbc",
        "name": "fe rider deguzman",
        "location": {
          "latitude": 14.7868708,
          "longitude": 121.0118349
        },
        "mobile_number": "09999999999"
      },
      "hubs": {
        "origin": "north hub"
      },
      "failure_reason": {
        "reason": "Acts of God - Weather Disturbance",
        "classification": "Fortuitous Event"
      },
      "photo": "https://epod.staging.quadx.xyz/photos/epod-RVM7-6TPJ-8XA1-1688131298.jpg",
      "signature": null,
      "status_updated_at": "2023-06-30 13:21:38+00",
      "status_name": "Failed delivery"
    },
    {
      "id": 19505144,
      "status": "out_for_delivery",
      "status_id": "1501",
      "created_at": "2023-07-04 02:10:06.202906+00",
      "remarks": "Out for delivery",
      "agent": {
        "hub": "makati hub",
        "code": "ebel_fe_01_lbc",
        "name": "ebel rider",
        "location": {
          "latitude": 14.1321236,
          "longitude": 120.9554095
        },
        "mobile_number": "09478746796"
      },
      "hubs": {
        "origin": "makati hub"
      },
      "failure_reason": null,
      "photo": null,
      "signature": null,
      "status_updated_at": "2023-07-04 02:09:52+00",
      "status_name": "Out for delivery"
    },
    {
      "id": 19505153,
      "status": "delivered",
      "status_id": "2200",
      "created_at": "2023-07-04 02:11:27.987139+00",
      "remarks": "Parcel received by Consignee Thirdy",
      "agent": {
        "hub": "makati hub",
        "code": "ebel_fe_01_lbc",
        "name": "ebel rider",
        "location": {
          "latitude": 14.1321236,
          "longitude": 120.9554095
        },
        "mobile_number": "09478746796"
      },
      "hubs": {
        "origin": "makati hub"
      },
      "failure_reason": null,
      "photo": "https://epod.staging.quadx.xyz/photos/epod-RVM7-6TPJ-8XA1-1688436674.jpg",
      "signature": "https://epod.staging.quadx.xyz/signatures/epod-RVM7-6TPJ-8XA1-1688436674.jpg",
      "status_updated_at": "2023-07-04 02:11:14+00",
      "status_name": "Delivered"
    }
  ],
  "children": []
}
```

### Update Order

`PUT {{base_url}}/orders/{{tracking_number}}`

Updates `pickup_address`.

**Headers**

| Key | Value | Notes |
|---|---|---|
| `Content-Type` | `application/json` |  |
| `Authorization` | `{{authorization}}` |  |

**Example response: Update Pickup Address** (200 OK)

```json
{
  "id": 70652,
  "party_id": 85,
  "currency_id": 113,
  "reference_id": "4",
  "pickup_address_id": 112793,
  "delivery_address_id": 112795,
  "return_address_id": null,
  "active_segment_id": null,
  "tracking_number": "0007-0652-ZRFE",
  "payment_method": "cod",
  "payment_provider": "lbcx",
  "status": "pending",
  "buyer_name": "Marvin Calizo",
  "email": "mcalizo@olx.ph",
  "contact_number": "+639178973012",
  "subtotal": "3000.00",
  "shipping": "0.00",
  "tax": "0.00",
  "fee": "0.00",
  "insurance": "0.00",
  "discount": "0.00",
  "grand_total": "3000.00",
  "total_collected": "0.00",
  "shipping_fee": "50.00",
  "insurance_fee": "30.00",
  "transaction_fee": "75.00",
  "metadata": null,
  "parcel": null,
  "ip_address": "172.34.31.6",
  "preferred_pickup_time": null,
  "preferred_delivery_time": null,
  "tat": "{\"pending\": 1490876423}",
  "remarks": null,
  "pickup_attempts": 0,
  "delivery_attempts": 0,
  "status_updated_at": "2018-11-30",
  "flagged": 0,
  "created_at": "2017-03-30 20:20:23+08",
  "updated_by": null,
  "updated_at": {
    "date": "2018-11-27 08:39:59.120154",
    "timezone_type": 3,
    "timezone": "UTC"
  },
  "shipment": "small-pouch",
  "pickup_method": "for_pickup",
  "buyer_id": null,
  "parent_id": null,
  "dropoff_address_id": null,
  "service_id": null,
  "estimated_delivery_date": "2018-12-04 00:00:00+08",
  "return_fee": "0.00",
  "seller_payment_method": null,
  "seller_payment_provider": null,
  "pickup_address": {
    "id": 112793,
    "party_id": 85,
    "type": "pickup",
    "name": "Jane Doee",
    "title": null,
    "email": "johndoe@email.caom",
    "phone_number": "4218244",
    "mobile_number": "+639172947494",
    "fax_number": null,
    "company": null,
    "line_1": "3F U311 Bldg. C",
    "line_2": null,
    "district": null,
    "city": "Cebu City",
    "state": "Cebu",
    "postal_code": "1600",
    "region": "VIS",
    "country_id": 1,
    "remarks": "cebubugtest",
    "hash": "8d014cc49d3ad6368c3f466dd72c7008",
    "created_by": null,
    "created_at": "2017-03-13 19:54:27.532821+08",
    "updated_by": null,
    "updated_at": {
      "date": "2018-11-27 08:39:59.157129",
      "timezone_type": 3,
      "timezone": "UTC"
    },
    "code": null,
    "deleted_at": null,
    "deleted_by": null,
    "preferred": 0,
    "latitude": null,
    "longitude": null,
    "v_address": null,
    "xcode": null
  },
  "segments": [
    {
      "id": 166918,
      "order_id": 70652,
      "courier_party_id": 13,
      "shipping_type": "land",
      "type": "pick_up",
      "status": "pending",
      "currency_id": null,
      "amount": null,
      "reference_id": "0007-0652-ZRFE",
      "pickup_address_id": 112794,
      "delivery_address_id": 2,
      "barcode_format": "qr",
      "tat": null,
      "flagged": 0,
      "created_at": "2018-11-26 11:26:12.46617+08",
      "updated_at": "2018-11-26 12:35:49+08",
      "delivery_address": {
        "id": 2,
        "party_id": 13,
        "type": "business",
        "name": "LBCX Allegro",
        "title": null,
        "email": null,
        "phone_number": null,
        "mobile_number": null,
        "fax_number": null,
        "company": null,
        "line_1": "Allegro Center",
        "line_2": null,
        "district": "Magallanes",
        "city": "Makati",
        "state": "Metro Manila",
        "postal_code": "1232",
        "region": "MMB",
        "country_id": 1,
        "remarks": null,
        "hash": "3b54e7bb435e741a514cd806de84810a",
        "created_by": null,
        "created_at": "2017-03-07 01:26:51.702674+08",
        "updated_by": null,
        "updated_at": null,
        "code": null,
        "deleted_at": null,
        "deleted_by": null,
        "preferred": 0,
        "latitude": null,
        "longitude": null,
        "v_address": null,
        "xcode": null
      }
    },
    {
      "id": 166919,
      "order_id": 70652,
      "courier_party_id": 13,
      "shipping_type": "land",
      "type": "delivery",
      "status": "pending",
      "currency_id": null,
      "amount": null,
      "reference_id": "0007-0652-ZRFE",
      "pickup_address_id": 112794,
      "delivery_address_id": 112795,
      "barcode_format": "qr",
      "tat": null,
      "flagged": 0,
      "created_at": "2018-11-26 11:26:12.46617+08",
      "updated_at": null,
      "delivery_address": {
        "id": 112795,
        "party_id": 85,
        "type": "delivery",
        "name": "Marvin Calizo",
        "title": null,
        "email": null,
        "phone_number": null,
        "mobile_number": null,
        "fax_number": null,
        "company": "OLX Philippines",
        "line_1": "40F Unionbank Plaza",
        "line_2": "Meralco Ave., Ortigas Center",
        "district": null,
        "city": "Pasig City",
        "state": "NCR",
        "postal_code": "1605",
        "region": "Mmb",
        "country_id": 1,
        "remarks": "Prod Engineering Team",
        "hash": "6d50b4954316fa4e84733e247eb6cadd",
        "created_by": null,
        "created_at": "2017-03-13 20:03:22.182296+08",
        "updated_by": null,
        "updated_at": null,
        "code": null,
        "deleted_at": null,
        "deleted_by": null,
        "preferred": 0,
        "latitude": null,
        "longitude": null,
        "v_address": null,
        "xcode": null
      }
    }
  ],
  "organization": {
    "party_id": 85,
    "name": "Kimstore"
  }
}
```

**Example response: Update Pickup Date** (200 OK)

```json
{
  "id": 70652,
  "party_id": 85,
  "currency_id": 113,
  "reference_id": "4",
  "pickup_address_id": 112793,
  "delivery_address_id": 112795,
  "return_address_id": null,
  "active_segment_id": null,
  "tracking_number": "0007-0652-ZRFE",
  "payment_method": "cod",
  "payment_provider": "lbcx",
  "status": "pending",
  "buyer_name": "Marvin Calizo",
  "email": "mcalizo@olx.ph",
  "contact_number": "+639178973012",
  "subtotal": "3000.00",
  "shipping": "0.00",
  "tax": "0.00",
  "fee": "0.00",
  "insurance": "0.00",
  "discount": "0.00",
  "grand_total": "3000.00",
  "total_collected": "0.00",
  "shipping_fee": "50.00",
  "insurance_fee": "30.00",
  "transaction_fee": "75.00",
  "metadata": null,
  "parcel": null,
  "ip_address": "172.34.31.6",
  "preferred_pickup_time": null,
  "preferred_delivery_time": null,
  "tat": "{\"pending\": 1490876423}",
  "remarks": null,
  "pickup_attempts": 0,
  "delivery_attempts": 0,
  "status_updated_at": "2018-11-30",
  "flagged": 0,
  "created_at": "2017-03-30 20:20:23+08",
  "updated_by": null,
  "updated_at": {
    "date": "2018-11-27 08:39:59.120154",
    "timezone_type": 3,
    "timezone": "UTC"
  },
  "shipment": "small-pouch",
  "pickup_method": "for_pickup",
  "buyer_id": null,
  "parent_id": null,
  "dropoff_address_id": null,
  "service_id": null,
  "estimated_delivery_date": "2018-12-04 00:00:00+08",
  "return_fee": "0.00",
  "seller_payment_method": null,
  "seller_payment_provider": null,
  "pickup_address": {
    "id": 112793,
    "party_id": 85,
    "type": "pickup",
    "name": "Jane Doee",
    "title": null,
    "email": "johndoe@email.caom",
    "phone_number": "4218244",
    "mobile_number": "+639172947494",
    "fax_number": null,
    "company": null,
    "line_1": "3F U311 Bldg. C",
    "line_2": null,
    "district": null,
    "city": "Cebu City",
    "state": "Cebu",
    "postal_code": "1600",
    "region": "VIS",
    "country_id": 1,
    "remarks": "cebubugtest",
    "hash": "8d014cc49d3ad6368c3f466dd72c7008",
    "created_by": null,
    "created_at": "2017-03-13 19:54:27.532821+08",
    "updated_by": null,
    "updated_at": {
      "date": "2018-11-27 08:39:59.157129",
      "timezone_type": 3,
      "timezone": "UTC"
    },
    "code": null,
    "deleted_at": null,
    "deleted_by": null,
    "preferred": 0,
    "latitude": null,
    "longitude": null,
    "v_address": null,
    "xcode": null
  },
  "segments": [
    {
      "id": 166918,
      "order_id": 70652,
      "courier_party_id": 13,
      "shipping_type": "land",
      "type": "pick_up",
      "status": "pending",
      "currency_id": null,
      "amount": null,
      "reference_id": "0007-0652-ZRFE",
      "pickup_address_id": 112794,
      "delivery_address_id": 2,
      "barcode_format": "qr",
      "tat": null,
      "flagged": 0,
      "created_at": "2018-11-26 11:26:12.46617+08",
      "updated_at": "2018-11-26 12:35:49+08",
      "delivery_address": {
        "id": 2,
        "party_id": 13,
        "type": "business",
        "name": "LBCX Allegro",
        "title": null,
        "email": null,
        "phone_number": null,
        "mobile_number": null,
        "fax_number": null,
        "company": null,
        "line_1": "Allegro Center",
        "line_2": null,
        "district": "Magallanes",
        "city": "Makati",
        "state": "Metro Manila",
        "postal_code": "1232",
        "region": "MMB",
        "country_id": 1,
        "remarks": null,
        "hash": "3b54e7bb435e741a514cd806de84810a",
        "created_by": null,
        "created_at": "2017-03-07 01:26:51.702674+08",
        "updated_by": null,
        "updated_at": null,
        "code": null,
        "deleted_at": null,
        "deleted_by": null,
        "preferred": 0,
        "latitude": null,
        "longitude": null,
        "v_address": null,
        "xcode": null
      }
    },
    {
      "id": 166919,
      "order_id": 70652,
      "courier_party_id": 13,
      "shipping_type": "land",
      "type": "delivery",
      "status": "pending",
      "currency_id": null,
      "amount": null,
      "reference_id": "0007-0652-ZRFE",
      "pickup_address_id": 112794,
      "delivery_address_id": 112795,
      "barcode_format": "qr",
      "tat": null,
      "flagged": 0,
      "created_at": "2018-11-26 11:26:12.46617+08",
      "updated_at": null,
      "delivery_address": {
        "id": 112795,
        "party_id": 85,
        "type": "delivery",
        "name": "Marvin Calizo",
        "title": null,
        "email": null,
        "phone_number": null,
        "mobile_number": null,
        "fax_number": null,
        "company": "OLX Philippines",
        "line_1": "40F Unionbank Plaza",
        "line_2": "Meralco Ave., Ortigas Center",
        "district": null,
        "city": "Pasig City",
        "state": "NCR",
        "postal_code": "1605",
        "region": "Mmb",
        "country_id": 1,
        "remarks": "Prod Engineering Team",
        "hash": "6d50b4954316fa4e84733e247eb6cadd",
        "created_by": null,
        "created_at": "2017-03-13 20:03:22.182296+08",
        "updated_by": null,
        "updated_at": null,
        "code": null,
        "deleted_at": null,
        "deleted_by": null,
        "preferred": 0,
        "latitude": null,
        "longitude": null,
        "v_address": null,
        "xcode": null
      }
    }
  ],
  "organization": {
    "party_id": 85,
    "name": "Kimstore"
  }
}
```

### Set Order Status to cancel

`PUT {{base_url}}/orders/{{tracking_number}}/cancel`

Change the order status to canceled as long as it hasn't been assigned to a rider.

**Headers**

| Key | Value | Notes |
|---|---|---|
| `Authorization` | `{{authorization}}` |  |

### Set Order Status to for_pickup

`PUT {{base_url}}/orders/{{tracking_number}}/for-pickup`

Sets the order status to for_pickup.

**Headers**

| Key | Value | Notes |
|---|---|---|
| `Authorization` | `{{authorization}}` |  |
| `Content-Type` | `application/json` |  |

**Request body**

```json
{
  "pickup_address": {
    "name": "Mart Rules 2",
    "company": "Company name",
    "phone_number": "12345",
    "mobile_number": "54321",
    "line_1": "Street 2",
    "line_2": "District 2",
    "city": "City 2",
    "state": "Abra 2",
    "postal_code": "1630-2",
    "country": "PH",
    "remarks": "Remarks here"
  }
}
```

### Get List of Statuses

`GET `

Returns the list of statuses

**Example response: Get List of Statuses** (200 OK)

```json
[
  {
    "name": "pending",
    "label": "Pending"
  },
  {
    "name": "for_pickup",
    "label": "Ready for pickup"
  },
  {
    "name": "picked_up",
    "label": "Picked up"
  },
  {
    "name": "failed_pickup",
    "label": "Failed pickup"
  },
  {
    "name": "failed_delivery",
    "label": "Failed delivery"
  },
  {
    "name": "in_transit",
    "label": "In transit"
  },
  {
    "name": "claimed",
    "label": "Claimed"
  },
  {
    "name": "delivered",
    "label": "Delivered"
  },
  {
    "name": "return_in_transit",
    "label": "Returned - in transit"
  },
  {
    "name": "returned",
    "label": "Returned"
  },
  {
    "name": "failed_return",
    "label": "Failed return"
  },
  {
    "name": "out_for_delivery",
    "label": "Out for delivery"
  },
  {
    "name": "confirmed",
    "label": "Confirmed"
  },
  {
    "name": "canceled",
    "label": "Canceled"
  },
  {
    "name": "for_consolidation",
    "label": "For consolidation"
  },
  {
    "name": "consolidated",
    "label": "Consolidated"
  },
  {
    "name": "for_dropoff",
    "label": "Ready for dropoff"
  },
  {
    "name": "dropped_off",
    "label": "Dropped off"
  },
  {
    "name": "for_acceptance",
    "label": "For acceptance"
  },
  {
    "name": "accepted",
    "label": "Accepted"
  },
  {
    "name": "out_for_return",
    "label": "Out for return"
  },
  {
    "name": "for_payment",
    "label": "For payment"
  },
  {
    "name": "paid",
    "label": "Paid"
  },
  {
    "name": "for_transfer",
    "label": "For transfer"
  },
  {
    "name": "received_at_pickup_hub",
    "label": "Received at pickup hub"
  },
  {
    "name": "received_at_delivery_hub",
    "label": "Received at delivery hub"
  },
  {
    "name": "received_at_delivery_area",
    "label": "Received at delivery area"
  },
  {
    "name": "at_3pl_facility",
    "label": "At 3PL facility"
  },
  {
    "name": "at_sorting_center",
    "label": "At sorting center"
  },
  {
    "name": "in_transit_to_delivery_area",
    "label": "In transit to delivery area"
  },
  {
    "name": "transferred_to_partner",
    "label": "Transferred to partner"
  },
  {
    "name": "for_return",
    "label": "For return"
  },
  {
    "name": "arrived_at_rts_hub",
    "label": "Arrived at RTS hub"
  },
  {
    "name": "arrived_at_rts_area",
    "label": "Arrived at RTS area"
  },
  {
    "name": "at_sorting_center_rts",
    "label": "At sorting center RTS"
  },
  {
    "name": "for_claims",
    "label": "For Claims"
  },
  {
    "name": "claims_in_transit",
    "label": "Claims in transit"
  },
  {
    "name": "arrived_at_claims_area",
    "label": "Arrived at claims area"
  },
  {
    "name": "disposed",
    "label": "Disposed"
  },
  {
    "name": "rejected",
    "label": "Rejected"
  },
  {
    "name": "damaged",
    "label": "Damaged"
  },
  {
    "name": "lost",
    "label": "Lost"
  },
  {
    "name": "rider_found",
    "label": "Rider found"
  },
  {
    "name": "picking_up",
    "label": "Picking up"
  },
  {
    "name": "for_delivery",
    "label": "For delivery"
  },
  {
    "name": "for_release",
    "label": "For release"
  },
  {
    "name": "out_for_pickup",
    "label": "Out for pickup"
  },
  {
    "name": "failed_pickup_attempt",
    "label": "Failed pickup attempt"
  },
  {
    "name": "runsheet_closure",
    "label": "Runsheet closure"
  },
  {
    "name": "pickup_rider_found",
    "label": "Pickup rider found"
  },
  {
    "name": "delivery_rider_found",
    "label": "Delivery rider found"
  },
  {
    "name": "for_disposal",
    "label": "For disposal"
  },
  {
    "name": "pending_disposition",
    "label": "Pending disposition"
  },
  {
    "name": "rcd_at_sorting_center",
    "label": "RCD at sorting center"
  },
  {
    "name": "rcd_in_transit",
    "label": "RCD in transit"
  },
  {
    "name": "received_at_claims_recovery_hub",
    "label": "Received at Claims/Recovery hub"
  },
  {
    "name": "dws_update",
    "label": "DWS update"
  },
  {
    "name": "searching_for_rider",
    "label": "Searching for Rider"
  }
]
```

### Get Rates

`POST {{base_url}}/orders/estimates/rates`

Provides the parcel shipping costs based on the specified pickup and delivery locations.

**Headers**

| Key | Value | Notes |
|---|---|---|
| `Content-Type` | `application/json` |  |
| `Authorization` | `{{authorization}}` |  |

**Request body**

```json
{
    "data": {
        "attributes": {
            "service_type": "next_day", // next_day | same_day_delivery
            "pickup_address": {
                "line_1": "Home 1",
                "line_2": null,
                "district": null,
                "city": "Makati",
                "state": "Metro Manila",
                "postal_code": "1234",
                "country": "PH"
            },
            "delivery_address": {
                "line_1": "Home 2",
                "line_2": null,
                "district": null,
                "city": "Taguig",
                "state": "Metro Manila",
                "postal_code": "1234",
                "country": "PH"
            },
            "shipment": "box", // returns all parcel rates if shipment is not present
            "declared_value": 2600,
            "payment_method": "cod" // cod | non-cod. If not present, default is non-cod
        }
    }
}
```

**Example response: {{base_url}}/orders/estimates/rates** (200 OK)

```json
{
  "data": {
    "type": "class",
    "id": "",
    "attributes": {
      "shipping_fee": 200,
      "insurance_fee": 21,
      "transaction_fee": 0,
      "return_fee": 221,
      "id": null,
      "links": {
        "uri": "/v1/orders/estimates/rates"
      }
    }
  },
  "links": {
    "self": "http://orders.api.staging.lbcx.ph/api/v1/orders/estimates/rates"
  }
}
```

## AWB Printing

Endpoints for developers for generating pdf files of AWB/POD labels for printing.

### AWB Label Single Print

`GET {{base_url}}/print/awb/{{tracking_number}}?type=zebra`

Gets a single pdf of the requested label. Tracking Number is required, along with the queried type (zebra or fuji).

**Headers**

| Key | Value | Notes |
|---|---|---|
| `Authorization` | `{{authorization}}` |  |

**Query parameters**

| Key | Example | Notes |
|---|---|---|
| `type` | `zebra` | Valid values are 'zebra' or 'fuji' |

**Example response: AWB Label Single Print** ( )

```json
{
  "success": true,
  "message": {
    "tracking_number": "0570-6897-BHWJ",
    "date_pickup_at": "2020-11-26",
    "estimated_delivery_date": "2020-11-29",
    "pickup_hub": "NTH",
    "pickup_hub_zone": "Z112",
    "delivery_hub": "NTH",
    "awb": "https://s3.ap-southeast-1.amazonaws.com/awbpod.staging.quadx.xyz/zebra/AWB-0570-6897-BHWJ.pdf?AWSAccessKeyId=ASIA3SHA62V66MEVV67I&Expires=1606439747&Signature=G4POeYpI72XC3IfZgEEhpZmm6e4%3D&x-amz-security-token=IQoJb3JpZ2luX2VjEIH%2F%2F%2F%2F%2F%2F%2F%2F%2F%2FwEaDmFwLXNvdXRoZWFzdC0xIkcwRQIgGQJ4AOp%2FvJcvJr5gjJa0db1GjYb%2FuxUX39590FuRktECIQCjsu1QZ4l2o%2FELYrcDOf3m79xzT7aSSzRhO7zfvHY5Jir1AQj6%2F%2F%2F%2F%2F%2F%2F%2F%2F%2F8BEAEaDDc5NTA0MDczMjU0MSIM2TkFDCXQoHp7pyhEKskBQ86QhdH9wSh%2FZfEQ90oslEskIZHc3LX7BE%2F2AuaLZ%2BB%2FnVWpFub1A6Z%2FwU31lR%2BLyvOk4uqyUXu8tpMHY8BjqXDlmxdMo%2BIqlk%2FFE1PArWf9BzYaq2%2BJD8XFlFlpugx22TG6HfLEgi1sGqXfsA%2FTFziEVgMAbBzPVCZgt7GXu4fmb5T4g9MyGgtbM6oDVfdEeJyIuZe3Wwdt8kbdBb76aZiT8EQCuPQp%2BaPOgqCA3Xz2C5XcHUtZzq08Lp3zzulJxgMIwwfIXKzUMLmD%2FP0FOuABm8jIBSLXzCx1q5Tk%2BbsdDh%2FtNOUGmnah4P6%2F%2BXBgUObdzSFqaVKyBfYZ4C1djBxwFQT5B5wd3L%2BC7nS64d0uXTlJhthCeqepy2wWWQFynQVFGwfATlJ98Xst7nn4K8wbFnDi%2B%2BRIPRkh%2B1w83McLZJKhUSKpxomF96Vp7WgNVG3yBVTykifK04shNWTKvTfs0uUDNHF1%2F74rxJOC2%2B7LJXeYpf3A1dXMGRdPcpvF0E6bWjN9o62Al0HHmoA0gB5vorDcLrbAGuWsbvGDl8EmZrHPrpq%2FPUUSWNArX5HH0W4%3D"
  }
}
```

### AWB Label Bulk Print

`POST {{base_url}}/print/awb?type=zebra`

Requests for a pdf compiling multiple tracking numbers. 

Body contains an array of the tracking numbers that must be printed, while type (zebra, fuji) is queried via the url.

**Headers**

| Key | Value | Notes |
|---|---|---|
| `Authorization` | `{{authorization}}` |  |

**Query parameters**

| Key | Example | Notes |
|---|---|---|
| `type` | `zebra` |  |

**Request body**

```json
{
  "tracking_number": [
    "0248-8053-WMHB"
  ]
}
```

**Example response: AWB Label Bulk Print** ( )

```json
{
  "pollUrl": "https://api.staging.quadx.xyz/v1/print/awb/poll/1606353498666",
  "success": true,
  "id": 1606353498666,
  "numOfBatches": 1,
  "message": null
}
```

### Poll URL for Bulk Print

`GET {{base_url}}/print/awb/poll/{{awb_poll_id}}`

The purpose of this endpoint is for the client to check if the requested pdfs have already been created on the backend.

**Headers**

| Key | Value | Notes |
|---|---|---|
| `Authorization` | `{{authorization}}` |  |

**Example response: Poll URL for Bulk Print** ( )

```json
[
  {
    "batchgroupid": "1606353498666",
    "labelType": "awb",
    "sequence": 1,
    "batchid": "1606353498666-1-awb",
    "printer": "zebra",
    "status": "done",
    "file": "awb-1606353498666-1-of-1.pdf",
    "url": "https://s3.ap-southeast-1.amazonaws.com/awbpod.staging.quadx.xyz/batch/awb-1606353498666-1-of-1.pdf?AWSAccessKeyId=ASIA3SHA62V67NF5ICXL&Expires=1606439922&Signature=Up6ca68qwr9daLGYjdJ20cgaXWY%3D&x-amz-security-token=IQoJb3JpZ2luX2VjEIH%2F%2F%2F%2F%2F%2F%2F%2F%2F%2FwEaDmFwLXNvdXRoZWFzdC0xIkYwRAIgWXLf4bHhf%2BzpbygCmYJkCEPNrUf7qMhkAQNUyNCnKZsCIEjvF%2BWWR3Ad9x076NMm8zERx5INOxwJ5AkBgOzEp7%2BWKvYBCPr%2F%2F%2F%2F%2F%2F%2F%2F%2F%2FwEQARoMNzk1MDQwNzMyNTQxIgyUoZL3K6P1ucoUISgqygHTOajkUlavUW%2F%2FUAOHH3WItWkTyBu5odQJ5FindW00Vw93GvfrfT49CcIjwtcyTv2r4Lf%2BuaQL15SEmxwy5oVK%2FIzOGpU9oX27f%2BLjScDllADGF73fxFPROMfo0N5bcD1Oa3zMR%2FYvYfk91z%2Fb7dDtarJ2UiO12FVr0MnBBKM0z1QNwogJKRJXt2z2A5C7%2FNBiHePqUooFXmYykQIMPfiT0oNe%2F0mZzNFqiGs1MNx7lq1ZN1Jo2vIhR5ccdXaGx7WyxhVmPoceZzROMPCE%2FP0FOuEBbjv6RGg2rTMmBDc5LszuvgJlAPnKE9TAxH2djW68DOOqsvcGQn%2Ftu3cpnP8v0ON3rOdv8%2FPH0F3WqxIxFRcsF4dAMqoOPI1KJ47hKrspy%2FCv4rP0BeeEUkhCVmWJQ9JVn7TfKWKsES6DPqx3TLB82T%2Bfj6luV14Wjo7qQhKqgxuoE2WfR3uzdGU%2BPHIgHVrWNVe8J1NhT9KXRFtLRk2k6hEuYJ4lcoOP1evWOBN6HJtI%2FcOl7MCKyfn6RgoAYiSY7MgvBI1%2B8M72n3lOPXHOMM4H0PMyjoox6bLlrD5yG6aR"
  }
]
```

