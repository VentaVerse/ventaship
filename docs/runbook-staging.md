# Staging runbook: QuadX end to end

How to run Ventaship on the Pi against **QuadX staging** and walk one parcel through the whole loop: a signed create, the waybill, courier webhooks to delivered, and signed outgoing webhooks to the client app. The automated version of this run is `tests/Feature/EndToEnd/QuadXLifecycleTest.php`; the client contract is `docs/api.md`.

Run every command from `/home/ubuntu/ventaship`.

---

## 1. Before you start

- `.env` has `APP_URL=http://localhost:8040`, the MySQL `ventaship` database, and `VENTASHIP_VAULT_KEY` (base64, 32 bytes). Courier credentials are encrypted with that key: **lose it and every stored courier key is unreadable**.
- `php artisan migrate` has run.
- The cache store must support atomic locks (booking, cancels, labels and webhook processing take locks). `CACHE_STORE=database` is fine; `file` and `array` are not suitable for workers.
- Live-booking locks stay **off** (the default). They only concern LBC and production-mode SPX accounts: a real LBC or production SPX booking needs **all** of env `COURIERS_LBC_LIVE=true` / `COURIERS_SPX_LIVE=true`, the setting `safety.allow_live_lbc` / `safety.allow_live_spx`, the account's `allow_live_booking`, the account in `production` mode, and `APP_ENV=production`. QuadX has no such lock: a QuadX account in `production` mode books real parcels. Keep the QuadX account in `staging` mode for this run.

## 2. Start the processes

Four processes, each in its own terminal (or under systemd, see [9](#9-live-parcel-checklist-later)):

```bash
# Several PHP workers: a create with ?wait holds its worker for up to 20 seconds, and the single-worker default
# would make every other request (webhooks included) wait behind it. In production use php-fpm behind the proxy.
PHP_CLI_SERVER_WORKERS=4 php artisan serve --port=8040

# Courier calls: booking and cancelling. One try only, five-minute timeout: a booking is never retried.
php artisan queue:work couriers --queue=couriers --timeout=300 --tries=1

# Inbound courier webhooks, outgoing client webhooks, everything else.
php artisan queue:work --queue=inbound,outgoing,default

# The scheduler: outgoing delivery and the booking sweep every minute, courier polling every 10 minutes,
# receipt reprocessing, log pruning, personal-data purge.
php artisan schedule:work
```

The couriers worker must name the queue **connection** `couriers`: booking and cancel jobs are dispatched on it, and its 900-second `retry_after` outlasts the 300-second job timeout. Running that worker on the `database` connection instead would read the same `jobs` rows with a 90-second `retry_after`, so a slow booking would be handed to a second worker while the first is still talking to the courier. The second worker uses the default connection (`QUEUE_CONNECTION=database`).

Check: `curl -s http://localhost:8040/up` answers 200.

## 3. Create a client app, a key and a QuadX staging account

```bash
# The client app and where its webhooks go. The webhook secret is shown once.
php artisan ventaship:app-create "VentaCart Pro" --webhook-url=http://127.0.0.1:8000/ventaship/webhooks
#   App:            app_…
#   Webhook secret: whsec_…

# An API key and signing secret for it. Shown once.
php artisan ventaship:key-issue app_… --name=staging
#   API key:        vsk_…
#   Signing secret: …

# The QuadX staging account, default for "courier": "quadx". Prints the inbound webhook URL (it carries a secret token).
php artisan ventaship:account-create quadx "QuadX staging" --mode=staging --default
#   Account:     acc_…
#   Webhook URL: http://localhost:8040/webhooks/quadx?token=…

# The staging keys. Leave the value off so it is prompted for and kept out of shell history.
php artisan ventaship:account-set acc_… quadx_staging_api_key --secret
php artisan ventaship:account-set acc_… quadx_staging_secret_key --secret

# Check them against QuadX staging, and look at what is stored (secrets masked).
php artisan ventaship:account-test acc_…
php artisan ventaship:account-show acc_…
```

Webhook URLs of client apps are set by the owner (here, `--webhook-url`). Private and loopback addresses are allowed on purpose: VentaCart Pro runs on the same Pi. To test the outgoing side without VentaCart Pro, point the app at any listener you control and send `php artisan ventaship:app-ping app_…`.

There is no command yet to change an app's webhook URL or event filter after creation (that is the Part 2 admin screen). Until then, use tinker:

```bash
php artisan tinker --execute='App\Models\ClientApp::where("public_id","app_…")->update(["webhook_url" => "http://127.0.0.1:8000/ventaship/webhooks"]);'
```

## 4. A signed create

The request body used here is `tests/Fixtures/api/create-shipment.request.json` (COD ₱1,290.00, Makati). Edit the addresses to ones QuadX staging serves.

### curl (bash)

```bash
KEY='vsk_…'
SECRET='…'
BASE='http://localhost:8040'

signed() {  # signed METHOD TARGET [BODY_FILE|-] [extra curl args...]
  local method=$1 target=$2 file=${3:--} ts sig
  shift $(( $# < 3 ? $# : 3 ))
  ts=$(date +%s)
  # The signed string is "{ts}.{METHOD} {target}\n" followed by the body file's exact bytes.
  sig=$( { printf '%s.%s %s\n' "$ts" "$method" "$target"; [ "$file" != - ] && cat "$file"; } \
        | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1 )
  if [ "$file" != - ]; then set -- --data-binary "@$file" "$@"; fi
  curl -sS -X "$method" "$BASE$target" \
    -H "Authorization: Bearer $KEY" -H "X-Timestamp: $ts" -H "X-Signature: sha256=$sig" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' "$@"
  echo
}

signed GET /api/v1/ping
signed POST '/api/v1/shipments?wait=15' tests/Fixtures/api/create-shipment.request.json -H 'Idempotency-Key: staging-run-1'
signed GET /api/v1/shipments/shp_…
signed GET /api/v1/shipments/shp_…/label - -o waybill.pdf
```

If `GET /label` answers `502 courier_unavailable` and `storage/logs/laravel.log` has `Label link refused: host not on the allow-list` (with the `host`), QuadX served the waybill from a host Ventaship does not trust. Nothing was downloaded. Check that the host really is QuadX's, then add it to the courier's list in `app/Couriers/Support/LabelHosts.php` (exact host, `https` only) and deploy; the next `GET /label` fetches it.

`--data-binary @file` sends the file's bytes unchanged, and the signature is computed over the same bytes (trailing newline included). Each call signs with the current second: do not send two identical requests within the same second (the second is refused as a replay).

### PHP

Use the `VentashipClient` class from `docs/api.md` §2:

```php
$vs = new VentashipClient('http://localhost:8040', 'vsk_…', '…');
$payload = json_decode(file_get_contents('tests/Fixtures/api/create-shipment.request.json'), true);
$r = $vs->request('POST', '/api/v1/shipments?wait=15', $payload, ['Idempotency-Key' => 'staging-run-1']);
echo $r['status'], "\n", $r['body'], "\n";
```

Expect `202` with `data.state` `booked` and a QuadX tracking number (or `queued`/`booking` if the worker is slow: `GET` it again). If it says `failed`, `data.error.message` is QuadX's reason. If it says `unknown`, QuadX timed out or answered 5xx: **check the QuadX staging portal** before anything else, then resolve it:

```bash
php artisan ventaship:shipments-resolve shp_… --tracking=QX…     # QuadX has it
php artisan ventaship:shipments-resolve shp_… --not-booked       # QuadX does not
```

The client app should by now have received `shipment.status_changed` (`for_pickup`) and `shipment.booked`, about a minute apart (one event per shipment per delivery run).

## 5. QuadX webhooks

QuadX must be able to reach the inbound URL printed by `account-create`:

```
{public base}/webhooks/quadx?token={account token}
```

`localhost:8040` is not reachable from QuadX. Until the public https host is settled (owner decision **O1**), QuadX staging cannot call us, and the last steps are simulated (§6). Once O1 exists:

1. Set `APP_URL` to the public base and run `ventaship:account-show acc_…`; the token is masked there, so keep the URL `account-create` printed (or create a new account).
2. In the QuadX staging dashboard, set the webhook URL to `https://{O1 host}/webhooks/quadx?token=…`.
3. Behind a reverse proxy (Caddy, nginx, Cloudflare Tunnel), configure Laravel's trusted proxies (`$middleware->trustProxies(at: …)` in `bootstrap/app.php`) so `$request->ip()` is the courier's real address. Courier webhooks are throttled at 300 a minute **per IP**; without trusted proxies every courier shares the proxy's IP and one bucket.

The route answers `200` for a known token (even for a parcel it does not know), `403` for a missing token or another courier's token, `404` for an unknown token. Every delivery is stored in `webhook_receipts` and processed on the `inbound` queue.

## 6. Simulating the last steps

When QuadX staging never sends picked up / in transit / delivered (it often does not move parcels), post the saved QuadX webhook payloads to the local route yourself. They are QuadX's own shape:

| File | Status |
|---|---|
| `tests/Fixtures/quadx/webhook-status.json` | `picked_up` |
| `tests/Fixtures/quadx/webhook-in-transit.json` | `in_transit` |
| `tests/Fixtures/quadx/webhook-delivered.json` | `delivered` |

They name the tracking number `QX-TEST-0001` and carry fixed `status_updated_at` times. The loop below puts in your shipment's real tracking number (from `GET /shipments/{id}`) and rewrites each `status_updated_at` to now plus 1, 2 and 3 minutes. **The times must be later than the booking**: the booking recorded `for_pickup` at the moment it was booked, and an event dated earlier than the latest one is kept in the timeline but does not move the shipment's phase, so with the fixtures' original times it would never reach `delivered`. Run it after the shipment is `booked`.

```bash
TOKEN='…'   # the token from the account's webhook URL
TN='QX…'    # the shipment's tracking number

n=0
for f in webhook-status webhook-in-transit webhook-delivered; do
  n=$((n + 1))
  at=$(TZ=Asia/Manila date -d "+$n minutes" +%Y-%m-%dT%H:%M:%S+08:00)
  sed -e "s/QX-TEST-0001/$TN/" \
      -e "s/\"status_updated_at\": \"[^\"]*\"/\"status_updated_at\": \"$at\"/" \
      tests/Fixtures/quadx/$f.json > /tmp/$f.json
  grep -h '"status"\|status_updated_at' /tmp/$f.json
  curl -sS -X POST "http://localhost:8040/webhooks/quadx?token=$TOKEN" \
    -H 'Content-Type: application/json' --data-binary @/tmp/$f.json
  echo
done
```

Each answers `{"ok":true}`. Then, within a few minutes (the scheduler runs `ventaship:events-deliver` every minute and sends one event per shipment per run; run it by hand to go faster), the client app receives, in `sequence` order:

1. `shipment.status_changed` `picked_up`
2. `shipment.status_changed` `in_transit`
3. `shipment.status_changed` `delivered`
4. `shipment.payment_state_changed` (`cod_collected`)

and `GET /api/v1/shipments/shp_…` shows `phase` `delivered`, `payment_state` `cod_collected`.

Useful checks:

```bash
php artisan tinker --execute='App\Models\WebhookReceipt::latest("id")->take(5)->get(["id","status","error"])->each(fn($r)=>dump($r->toArray()));'
php artisan tinker --execute='App\Models\OutgoingEvent::latest("id")->take(10)->get(["public_id","type","sequence","status","attempts","last_error"])->each(fn($e)=>dump($e->toArray()));'
php artisan ventaship:events-resend evt_…    # send one again
```

## 7. Confirm before going live: COD amount vs item prices

Ventaship sends QuadX `total` = the shipment's `cod_amount` (the amount the rider collects), not the sum of item prices. Before the first live parcel, book on **QuadX staging** a COD order whose item prices do not add up to the COD amount (for example items ₱1,200.00, COD ₱1,290.00 to cover shipping) and confirm QuadX accepts it and its dashboard shows ₱1,290.00 to collect. If QuadX refuses or shows the item sum, stop and report it: the mapping in `QuadXFulfillment::buildPayload()` must change before going live.

## 8. Outcome of the staging run

_To be filled in by the owner after running §3–§7 with the real QuadX staging keys: date, shipment id, tracking number, which steps QuadX sent and which were simulated, the COD check of §7, anything that surprised._

## 9. Live-parcel checklist (later)

- [ ] **O1**: a public https host for Ventaship; `APP_URL` set to it; TLS working.
- [ ] Trusted proxies configured if behind a proxy (§5).
- [ ] QuadX production webhook URL set to `https://{O1 host}/webhooks/quadx?token=…` (a production-mode account has its own token).
- [ ] The QuadX account switched to `production` mode only for the live parcel, with production keys set via `ventaship:account-set … --secret`:
  ```bash
  php artisan ventaship:account-set acc_… quadx_production_api_key --secret
  php artisan ventaship:account-set acc_… quadx_production_secret_key --secret
  php artisan ventaship:account-mode acc_… production    # prints "acc_… (quadx) mode: staging → production"
  php artisan ventaship:account-mode acc_… staging       # back after the live parcel
  ```
- [ ] §7 confirmed on staging.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] The two queue workers, the scheduler and the web server under **systemd**, restarting on failure, started on boot; `php artisan queue:restart` after every deploy.
- [ ] **Backups**: the MySQL `ventaship` database (nightly, kept off the Pi), and `storage/app/labels` (stored waybills; they can be refetched from the courier only while the courier still serves them).
- [ ] **`VENTASHIP_VAULT_KEY` stored separately** from the database backups (a password manager). A backup without it cannot decrypt courier credentials; the key with a backup is everything an attacker needs.
- [ ] Live-booking locks still off unless an LBC or production SPX parcel is intended (§1). The settings take exactly `true` or `false`; every change is written to `audit_entries`:
  ```bash
  php artisan ventaship:setting-set safety.allow_live_spx true     # only for an intended production SPX parcel
  php artisan ventaship:setting-set safety.allow_live_spx false    # straight after
  ```
