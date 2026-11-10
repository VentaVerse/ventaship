# Update Order Flowchart

- Source: https://spx.ph/integration/en/guide/7012e6a61b864a41af18e2ba15bcfb17
- Create Time: 28/11/2023
- Last Updated: 17/10/2024
- Harvested: 2026-11-10

---

Flowchart of API [1.9 Update Order](https://spx.co.th/en/integration/en/api/8adb4fa1e51c4d41b1897208147f13b6)

Condition:

1. The following information cannot be edited:

1.1 Payment role

1.2 Insurance selection (parameter: insurance_collection) and Express Insured Value (parameter: express_insured_value)

1.3 Collect type (order collection type: pickup vs dropoff)

2. The maximum for editing order is 10 (combined with before and after pickup)

2.1 Edit time: Before "Pickup_assigned and "DOP_Received"
