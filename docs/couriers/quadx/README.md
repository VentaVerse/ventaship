# QuadX API reference (local copy)

A local copy of QuadX's public developer documentation, so we don't have to reach the website to check how the API behaves.

- **Source:** https://docs.quadx.xyz/ (Postman documenter, collection `12850780/TVKJyvMn`, version `latest`)
- **Fetched:** 2026-11-08

| File | What it is |
|---|---|
| `api.md` | The full reference as Markdown. It covers the introduction (keys, JWT authorization, responses, pagination, webhooks), then every endpoint: Locations, Orders, AWB Printing. |
| `statuses.md` | QuadX's official status list with QuadX's own labels. It marks the 31 statuses picked for the status map. |
| `postman-collection.json` | The raw Postman collection the docs are built from. Import it into Postman to try the requests. |

## Facts that shape the integration

- **Keys:**
  - QuadX issues an API key and a secret key.
  - Every request carries a JWT signed HS256 with the secret, with the API key as `sub`.
- **Webhooks:**
  - QuadX POSTs JSON to a URL that **QuadX sets on its side**. There is no settings page; you email or Slack them to change it.
  - HTTP and HTTPS are both accepted, with HTTPS advised. A token in the URL is their suggested protection.
  - Anything other than a 200 response is retried later.
  - One payload can hold several events for the same tracking number. Order them by `event_id` or `status_updated_at`.
  - End-state events (delivered, cancelled, returned) must be accepted even when their date looks wrong.
- **Statuses:**
  - `GET /orders/statuses` is the live authority.
  - The docs' example lists 58 statuses.
  - QuadX spells cancelled as `canceled`.

Refresh this copy by re-fetching `https://documenter.gw.postman.com/api/collections/12850780/TVKJyvMn?segregateAuth=true&versionTag=latest` and regenerating `api.md`.
