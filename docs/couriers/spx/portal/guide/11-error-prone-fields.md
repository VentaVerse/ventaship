# Error-prone fields

- Source: https://spx.ph/integration/en/guide/819534da135e4d80a3cf73918ecb5bf6
- Create Time: 08/06/2022
- Last Updated: 17/10/2024
- Harvested: 2026-11-10

---

## 1. tracking_no

When the request parameter has tracking_no_list or tracking_no, first make sure that tracking_no is the value returned by the create order interface, not the order_id passed in by yourselves when creating the order. The tracking_no string usually starts with SPX or CID (VN/TH/SG/PH).

## 2. tracking_no_list

The following example puts 3 tracking_no in a string, the system will recognize a string as a tracking_no, resulting in an order number error and the relevant interface call returns a failure.

Wrong Example:

```
 "tracking_no_list": ["tracking_no_1,tracking_no_2,tracking_no_3"]
```

Correct Example:

```
"tracking_no_list": ["tracking_no_1","tracking_no_2","tracking_no_3"]
```

## 3.Payment role

If you input the wrong payment role value, it will cause the failure of order creation and checking shipping fee

## 4.COD amount

When cod_collection=1, this param is mandatory and value must be greater than 0. Otherwise, it will cause the failure of order creation and checking shipping fee.

**Please reach out to SPX team if you want to activate the COD service.

## 5. Address

Some areas do not support courier pickup or delivery services, which will result in the failure of your order-related operations.
