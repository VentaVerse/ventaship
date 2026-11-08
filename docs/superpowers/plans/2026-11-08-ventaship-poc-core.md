# Ventaship POC Core (Plan A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A headless Ventaship install where a signed client app books a QuadX parcel through `/api/v1`, fetches the waybill, and receives every status change as a signed outgoing webhook through to delivered. It is documented in `docs/api.md` for the VentaCart Pro session.

**Architecture:**
- Single-tenant Laravel 12 app with no workspaces.
- **Couriers:** the courier port (`app/Couriers`) is copied from the Seller Center worktree and moved from global settings to per-account settings.
- **Shipments:** created asynchronously and booked by a single-try queued job.
- **Inbound:** courier webhooks land as receipts and are processed on a queue.
- **Outbound:** a transactional outbox delivers signed events to each client app's webhook URL.
- **No UI in this plan.** Everything is operated by artisan commands; Part 2 adds screens.

**Tech Stack:** PHP 8.5, Laravel 12, PHPUnit 11, MySQL in dev (`ventaship`), SQLite in-memory in tests, database queue and cache, `pdfunite` (poppler).

**Spec:**
- `docs/superpowers/specs/2026-11-08-ventaship-design.md` (approved)
- the base plan it builds on: `~/ventacart-pro/extensions/docs/plans/2026-11-08-ventaship-plan.md`, sections 2, 3, 4 and 7

Executors read both. Where they disagree, the design doc wins: **no tenancy**.

**Port source** (read and copy only, **never write**): `~/ventacart-pro/wt/seller-center-couriers/app/Couriers/**` and `config/couriers.php`. Core tests to port: `~/ventacart-pro/ventacart/tests/Unit/{Spx*,QuadXStatusPhaseTest,CourierWebhookIngestorTest,CourierPickupAddressPayloadTest}.php` and `~/ventacart-pro/ventacart/extensions/courier-fulfillment/tests/Feature/{Lbc*,TrackingNumberSeries*}.php`.

## Global Constraints

**Scope and safety:**
- Write only inside `/home/ubuntu/ventaship`. Reading and copying from `~/ventacart-pro` and `~/ventasync` is allowed. Writing there is not.
- No tenancy: no `workspaces` table, no `workspace_id` columns, no global tenant scopes.
- `tests/TestCase.php` calls `Http::preventStrayRequests()` in `setUp()`. No test ever reaches a real courier. Every courier call in tests goes through `Http::fake` or `FakeCourier`.

**Live-booking locks:**
- **LBC:** a real request needs all of these:
  - `config('couriers.lbc_live') === true` (env `COURIERS_LBC_LIVE`, default false)
  - setting `safety.allow_live_lbc === true`
  - `courier_accounts.allow_live_booking === true`
  - the account in production mode
  - outside tests, `app()->isProduction()`
- **SPX production-mode accounts:** the same rule with `COURIERS_SPX_LIVE` and `safety.allow_live_spx`.
- **SPX UAT and QuadX:** no extra lock.

**Data rules:**
- Public ids are ULIDs with prefixes `app_`, `acc_`, `shp_`, `evt_`, over bigint primary keys.
- Money is `decimal(12,2)`.
- Timezone is `Asia/Manila`. Timestamps are stored in UTC and rendered ISO-8601 with an offset in the API.
- Courier credentials and party data are encrypted with `CredentialVault`, keyed by env `VENTASHIP_VAULT_KEY` (base64, 32 bytes) and never by `APP_KEY`.
- Schema for Part 2 lands now (A7 rule):
  - `status_mappings`, `payment_state`, `remitted_at`, `items_summary`, `items_count`, `order_label`
  - `source` (`api`, `manual`, `csv`), `csv_imports`, `admins`, `settings`, `audit_entries`

**Client API:**
- Platform-neutral. Nothing VentaCart-specific in names or payloads.
- Error body: `{"error": string, "code": string, "retryable": bool, "errors": object}`.
- The outgoing webhook envelope replaces the base plan's `workspace` field with `"app": "app_…"`.

**App:**
- Port 8040: `APP_URL=http://localhost:8040`, run with `php artisan serve --port=8040`.
- Commit after every task, on `main`, with the attribution trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Re-submitting a `client_reference` after a failed or cancelled shipment must succeed.** `active_reference` is null once terminal, and only a *live* duplicate gets 409. Test in Task 9.
2. **Out-of-order courier events.** A webhook or poll event older than `last_event_at` is stored in the timeline but never moves `phase` or `payment_state` backwards. Test in Task 7.
3. **Malformed money and phones.**
   - `cod_amount` sent as `"1,290.00"`, negative, or missing while `cod: true` answers 422 with field errors, never a silent cast.
   - PH phones given as `+63 917…`, `63917…` or `0917…` are normalised by the port's `PhPhone` before reaching the courier.

   Tests in Task 9.
4. **A client webhook URL that is down for hours.**
   - Each event retries on its own schedule until 72 hours, then `failed`.
   - One app's dead URL never delays another app's deliveries.
   - Delivery order per shipment follows `sequence`.

   Tests in Task 13.
5. **Non-ASCII names** (`Ñ`, `é`) and 255-char addresses survive into the courier payload and the stored party data unchanged. Test in Task 6 (QuadX payload) and Task 9.

---

## File structure (decided here)

```
app/Support/Vault/CredentialVault.php        encrypt/decrypt with VENTASHIP_VAULT_KEY
app/Support/Settings.php                     key/value settings (table `settings`), typed getters
app/Support/HasPublicId.php                  trait: prefixed ULID public_id + route key
app/Models/{ClientApp,ApiKey,CourierAccount,CourierAccountSetting,Shipment,ShipmentEvent,ShipmentLabel,
            WebhookReceipt,OutgoingEvent,OutgoingAttempt,IdempotencyKey,RequestSignature,ApiRequest,
            CourierCall,AuditEntry,StatusMapping,CourierStatusLabelSet,CsvImport,Admin,Setting}.php
app/Api/{ApiError,Signature,ClientContext}.php
app/Http/Middleware/{AuthenticateClient,EnforceIdempotency,LogApiRequest}.php
app/Http/Controllers/Api/V1/{Ping,Couriers,CourierAccounts,Coverage,Quotes,PickupSlots,Shipments,Labels}Controller.php
app/Http/Requests/Api/V1/CreateShipmentRequest.php
app/Http/Resources/{ShipmentResource,ShipmentEventResource,CourierAccountResource}.php
app/Couriers/**                              the port (copied), plus AccountSettings, PickupPoint, FakeCourier, LiveGuard
app/Shipments/{ShipmentCreator,PhaseResolver,PaymentState,StatusRecorder,Canceller,LabelStore,LabelMerger,Poller}.php
app/Shipments/Jobs/{BookShipment,CancelAtCourier}.php
app/Webhooks/Inbound/{AccountRouter,ReceiptIngestor}.php, Jobs/{ProcessReceipt,RelayReceipt}.php
app/Webhooks/Outbound/{Outbox,Signer,RetrySchedule,EventPayload}.php, Jobs/DeliverEvent.php
app/Http/Controllers/Webhooks/CourierWebhookController.php
app/Console/Commands/*.php                   see each task
routes/api.php, routes/web.php (webhooks only), routes/console.php (schedule)
tests/Concerns/{SignsApiRequests,BuildsShipments}.php
docs/api.md, docs/runbook-staging.md
```

---

### Task 1: Repository, environment and test baseline

**Files:**
- Create: `.gitignore` additions, `config/ventaship.php`, `config/couriers.php` (copied from the port), `tests/Concerns/.gitkeep`
- Modify: `.env`, `.env.example`, `config/app.php` (timezone), `tests/TestCase.php`, `phpunit.xml`, `config/queue.php`
- Test: `tests/Feature/BaselineTest.php`

**Interfaces:**
- Produces:
  - `config('ventaship.vault_key')`
  - `config('ventaship.signature_window', 300)`
  - `config('couriers.lbc_live')`, `config('couriers.spx_live')`
  - queue connection `couriers` (database, `retry_after` 900)

- [ ] **Step 1:** `git init -b main`. Append `.superpowers/` and `/storage/app/labels` to `.gitignore`. Delete the stock `ExampleTest`s.
- [ ] **Step 2:** Write `BaselineTest`:
  - `test_stray_http_is_blocked`: `Http::get('https://example.com')` throws `Illuminate\Http\Client\StrayRequestException`.
  - `test_timezone_is_manila`: `config('app.timezone') === 'Asia/Manila'`.
  - `test_live_flags_default_off`: `config('couriers.lbc_live') === false && config('couriers.spx_live') === false`.
- [ ] **Step 3:** Run `php artisan test --filter=BaselineTest`. Expected: FAIL.
- [ ] **Step 4: Implement.**
  - Add `Http::preventStrayRequests()` to `TestCase::setUp()`.
  - Set the timezone.
  - `config/ventaship.php`: `vault_key` ← `VENTASHIP_VAULT_KEY`, `signature_window` 300, `outbox_give_up_hours` 72.
  - Copy `config/couriers.php` and add `lbc_live` ← `COURIERS_LBC_LIVE` and `spx_live` ← `COURIERS_SPX_LIVE`, both default false.
  - Add a `couriers` queue connection.
  - `.env` and `.env.example`: `APP_URL=http://localhost:8040`, `DB_CONNECTION=mysql`, `DB_DATABASE=ventaship`, `DB_USERNAME=ventaship`, `VENTASHIP_VAULT_KEY=`, `COURIERS_LBC_LIVE=false`, `COURIERS_SPX_LIVE=false`.
  - `phpunit.xml` sets `VENTASHIP_VAULT_KEY` to a fixed test key.
- [ ] **Step 5:** Run the test again. Expected: PASS.
- [ ] **Step 6: Owner step.** Ask the owner to run the SQL below (Claude has no sudo):

  ```sql
  CREATE DATABASE ventaship CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'ventaship'@'localhost' IDENTIFIED BY 'ventaship';
  GRANT ALL ON ventaship.* TO 'ventaship'@'localhost';
  ```

  Then generate `VENTASHIP_VAULT_KEY` with `php -r 'echo base64_encode(random_bytes(32));'` into `.env`, and run `php artisan migrate`. Expected: migrations run.
- [ ] **Step 7:** Commit: `chore: initialise Ventaship repo, env and test baseline`.

### Task 2: Schema and models

**Files:**
- Create: `database/migrations/2026_11_08_0001xx_*.php`, one per table group; `app/Models/*` (see the file structure); `app/Support/HasPublicId.php`
- Modify: drop the stock `users` and `password_reset_tokens` migration contents. Keep `sessions`, `cache` and `jobs`.
- Test: `tests/Feature/SchemaTest.php`

**Interfaces:**
- Produces: the Eloquent models listed above. `HasPublicId` requires `const PUBLIC_PREFIX` on the model, fills `public_id` with `PREFIX . strtolower(Str::ulid())` on create, and makes `getRouteKeyName()` return `public_id`.
- **Table columns:** exactly as in base plan §1 and A2–A5, with these changes:
  - no `workspace_id` anywhere
  - `workspaces`, `users` and `workspace_user` are gone
  - `admin_users` → `admins` (id, name, email unique, password, last_login_at, timestamps)
  - `settings` (key unique, value text, timestamps)
  - `courier_accounts.default_slot` unique nullable = courier key while `is_default`
  - `status_mappings` unique (courier, courier_status)
  - `shipments`: add `source` enum (api, manual, csv), `payment_method` (cod, prepaid), `payment_state`, `remitted_at`, `order_label`, `items_count`, `items_summary`. `client_app_id` nullable. Unique (client_app_id, active_reference).
  - `tracking_series`: `courier_account_id`, no workspace
  - `csv_imports` as in design §4.7
  - `outgoing_events.client_app_id` not null
  - `shipment_events` unique (shipment_id, courier_status, occurred_at)
- Encrypted casts: `shipments.pickup`, `recipient` and `courier_note`, `client_apps.webhook_secret`, `api_keys.signing_secret`, `courier_accounts.webhook_token` and `relay_secret`. All use a custom cast `App\Support\Vault\VaultEncrypted` (json-aware variant `VaultEncryptedJson`), which calls `CredentialVault`. Task 3 builds the vault, so write the casts here against its interface.

- [ ] **Step 1:** Write `SchemaTest`:
  - `test_public_ids_are_prefixed`: create `ClientApp`, `CourierAccount` and `Shipment` through factories. `public_id` starts with `app_`, `acc_` and `shp_` respectively, and is 30 chars.
  - `test_one_live_reference_per_app`: inserting two shipments with the same `client_app_id` and `active_reference='4211'` throws `UniqueConstraintViolationException`. Two with `active_reference=null` are fine.
  - `test_one_default_account_per_courier`: two QuadX accounts with `default_slot='quadx'` violate uniqueness.
  - `test_recipient_is_encrypted_at_rest`: `DB::table('shipments')->value('recipient')` does not contain the plaintext name `"Ñora"`, while `$shipment->recipient['first_name'] === 'Ñora'`.
- [ ] **Step 2:** Run it. Expected: FAIL (tables missing).
- [ ] **Step 3:** Write the migrations, models, factories, casts and the trait.
- [ ] **Step 4:** Run `php artisan test --filter=SchemaTest`. Expected: PASS. Run `php artisan migrate:fresh` on MySQL. Expected: no errors.
- [ ] **Step 5:** Commit: `feat: schema and models for the single-install data model`.

### Task 3: Credential vault and settings

**Files:**
- Create: `app/Support/Vault/CredentialVault.php`, `app/Support/Settings.php`
- Test: `tests/Unit/CredentialVaultTest.php`, `tests/Feature/SettingsTest.php`

**Interfaces:**
- **Produces `CredentialVault`** (a singleton):
  - `encrypt(string $plain): string` and `decrypt(string $cipher): string`
  - built with `new Encrypter(base64_decode(config('ventaship.vault_key')), 'aes-256-gcm')`
  - throws `RuntimeException('VENTASHIP_VAULT_KEY missing')` when it's empty
- **Produces `Settings`** (a singleton, cached for the request):
  - `get(string $key, mixed $default = null): mixed`
  - `bool(string $key, bool $default = false): bool`
  - `set(string $key, mixed $value): void`, which json-encodes non-strings
- Keys this plan reads: `safety.allow_live_lbc`, `safety.allow_live_spx`, `shipments.courier_ref_prefix` (default `"VS"`) and `privacy.retention_days` (default 180).

- [ ] **Step 1: Write the tests.**
  - `test_round_trip`: `decrypt(encrypt('s3cr3t')) === 's3cr3t'`.
  - `test_independent_of_app_key`: re-key `app.key`, then decrypting still works.
  - `test_missing_key_throws`.
  - `SettingsTest::test_bool_defaults_false_and_persists`.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement both and bind them as singletons in `AppServiceProvider`.
- [ ] **Step 4:** Run them. Expected: PASS.
- [ ] **Step 5:** Commit: `feat: credential vault and settings store`.

### Task 4: Client apps, API keys and the signed API front door

**Files:**
- Create: `app/Api/{ApiError,Signature,ClientContext}.php`, `app/Http/Middleware/{AuthenticateClient,LogApiRequest}.php`, `app/Http/Controllers/Api/V1/PingController.php`, `app/Console/Commands/{AppCreate,KeyIssue,KeyRevoke}.php`, `tests/Concerns/SignsApiRequests.php`
- Modify: `bootstrap/app.php` (api routes under prefix `api/v1`, ApiError rendering, middleware aliases), `routes/api.php`
- Test: `tests/Feature/Api/AuthenticationTest.php`, `tests/Feature/Console/ClientCommandsTest.php`

**Interfaces:**
- **Produces signing:** `Signature::sign(string $secret, int $ts, string $method, string $target, string $body): string` returns `"sha256=" . hash_hmac('sha256', "{$ts}.{$method} {$target}\n{$body}", $secret)`.
  - `$target` is the path plus the raw query string, exactly as sent.
- **Produces errors:** `ApiError::__construct(int $status, string $code, string $message, bool $retryable = false, array $errors = [])`. It renders the error body from Global Constraints. Laravel `ValidationException` on `/api/*` renders as 422 `invalid_request` with `errors`.
- **Produces context:** `ClientContext::app(): ClientApp` and `ClientContext::key(): ApiKey`.
- **Commands:**
  - `ventaship:app-create {name} {--webhook-url=}` prints `app_…` and the webhook secret, once.
  - `ventaship:key-issue {app} {--name=}` prints `vsk_<40>` and the signing secret, once. It stores sha256(key), the first 8 chars as the prefix, and the secret encrypted.
  - `ventaship:key-revoke {prefix}`.
- **Produces the test helper:** `SignsApiRequests::makeClient(?string $webhookUrl = null): array{app: ClientApp, key: string, secret: string}`, and `signedJson(string $method, string $uri, array $data = [], array $headers = [], ?array $client = null)`.

- [ ] **Step 1: Write `AuthenticationTest`**, all against `GET /api/v1/ping`:
  - `test_valid_signature_returns_app`: 200, `{"app": {"id": "app_…", "name": "VentaCart Pro"}}`.
  - `test_missing_bearer_is_401_unauthorized`: body `code === 'unauthorized'` and `error === 'Unauthorized'`.
  - `test_wrong_signature_is_401`, and `api_requests.error` holds `"bad_signature"`.
  - `test_stale_timestamp_is_401`: timestamp now−301.
  - `test_replayed_signature_is_401`: the same headers sent twice. The second answers 401 with error `"replay"`.
  - `test_revoked_key_is_401`.
  - `test_disabled_app_is_403_app_disabled`.
  - `test_query_string_is_part_of_target`: sign `/api/v1/ping?x=1`, then send `?x=2`. Expected: 401.
  - `test_every_request_is_logged`: one `api_requests` row with method, path, status and duration_ms.
- [ ] **Step 2:** Run it. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - `AuthenticateClient` looks the key up by sha256, checks it isn't revoked or expired and that the app is active, checks the timestamp window and the HMAC with `hash_equals`, then inserts `request_signatures` (unique, so a duplicate means replay) and sets `last_used_at`.
  - `LogApiRequest` is terminable middleware.
  - Ping returns the app.
  - Write the commands and `ClientCommandsTest`: `test_key_issue_prints_key_once_and_stores_hash_only`.
  - Schedule `request_signatures` pruning hourly in Task 14.
- [ ] **Step 4:** Run the tests. Expected: PASS.
- [ ] **Step 5:** Commit: `feat: client apps, API keys and HMAC-signed API access`.

### Task 5: Idempotency

**Files:**
- Create: `app/Http/Middleware/EnforceIdempotency.php`. Port it from `~/ventacart-pro/extensions` (`EnforceIdempotency`), scoped per `client_app_id`.
- Test: `tests/Feature/Api/IdempotencyTest.php`. It uses a test-only route `POST /api/v1/_test/echo`, registered in the test's `setUp`.

**Interfaces:**
- Produces: the middleware alias `idempotent`. It requires an `Idempotency-Key` header of 100 characters or fewer, and answers 422 `invalid_request` when it's missing.

- [ ] **Step 1: Write the tests.**
  - `test_same_key_same_body_replays_with_header`: the second response is identical, with `Idempotent-Replayed: true`, and the controller ran once.
  - `test_same_key_different_body_is_422`.
  - `test_in_flight_duplicate_is_409_idempotency_in_progress`: pre-insert a row with status `processing`.
  - `test_5xx_is_not_stored`.
  - `test_keys_are_scoped_per_app`: two apps may use the same key.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement. `request_hash` is sha256 of method, target and body.
- [ ] **Step 4:** Run them. Expected: PASS.
- [ ] **Step 5:** Commit: `feat: idempotency keys per client app`.

### Task 6: Courier port, per-account settings and QuadX

**Files:**
- **Copy into `app/Couriers/`:** the whole port tree.
- **Delete after the refactor:** `app/Couriers/CourierSetting.php` and `Support/CourierSecrets.php`.
- **Create:** `app/Couriers/{AccountSettings,PickupPoint,FakeCourier,LiveGuard}.php`, `app/Providers/CourierServiceProvider.php`, `app/Console/Commands/{AccountCreate,AccountSet,AccountShow,AccountTest}.php`, `app/Couriers/Logging/RecordCourierCalls.php`
- **Modify:** `app/Couriers/{CourierRegistry,CourierOrder,PickupOrigin,CourierStatusLabel,ServiceabilityService}.php`, `QuadX/*`
- **Test:** `tests/Unit/Couriers/QuadXStatusPhaseTest.php` (ported), `tests/Feature/Couriers/QuadXFulfillmentTest.php`, `tests/Feature/Couriers/AccountSettingsTest.php`, `tests/Feature/Console/AccountCommandsTest.php`, `tests/Feature/Couriers/CourierCallLoggingTest.php`

**Interfaces:**
- **`AccountSettings`:**
  - `AccountSettings::for(CourierAccount $a): self`, which loads every `courier_account_settings` row once
  - `read(string $key, ?string $default = null): ?string`
  - `secret(string $key): ?string`, which decrypts through the vault
  - `write(string $key, ?string $value): void` and `writeSecret(string $key, ?string $value): void`, each writing an `audit_entries` row. Secret values show as `"•••• changed"`.
- **`CourierRegistry`:**
  - `register(string $key, class-string<Courier> $cls): void` (the key is given explicitly, so registering no longer instantiates the class)
  - `for(CourierAccount $a): Courier`
  - `describe(string $key): Courier`, a schema-only instance with empty settings
  - `keys(): array`
  - `fake(string $key, Courier $impl): void` and `clearFakes(): void`
  - `for()` returns the fake when one is set
- **Courier constructors** take `(CourierAccount $account, AccountSettings $settings)`, and clients take `AccountSettings`. Every `CourierSetting::...` read becomes `$this->settings->read(...)`, and every decrypt becomes `secret(...)`. The `{key}_enabled` and `{key}_webhook_token` keys are dropped.
- **`CourierOrder::fromApi(Shipment $s): CourierOrder`:**
  - Adds a property `reference` (= `$s->courier_ref`).
  - QuadX reference sites (`QuadXFulfillment` lines that used `(string) $order->id`) use `$order->reference`.
- **`PickupPoint`:** `PickupPoint::fromArray(array $pickup): self`, a readonly object with `contact_name, contact_phone, address_1, barangay, barangay_code, district, city, city_code, state, state_code, postcode, country`.
  - All `App\Models\PickupAddress` type hints in the port become `PickupPoint`.
  - `PickupOrigin::from(array $opts): ?PickupPoint`.
- **`FakeCourier implements Courier`:** a scripted test courier.
  - `FakeCourier::make(string $key = 'quadx'): self`
  - `->booksAs(string $tn, string $status = 'pending')`, `->refusesWith(string $msg)`, `->throws(\Throwable $e)`
  - `->tracks(array $events)`, `->cancels(bool $ok = true)`, `->label(string $pdfBytes)`
  - It records its calls in `->calls`.
- **`LiveGuard::assertAllowed(CourierAccount $a, string $operation): void`** throws `App\Couriers\LiveBookingBlocked` per the Global Constraints. Only `pickup` and `airwaybill` (LBC) and `create` and `cancel` (SPX production) are guarded.
- **Commands:**
  - `ventaship:account-create {courier} {label} {--mode=staging} {--default}` prints `acc_…` and the webhook URL.
  - `ventaship:account-set {account} {key} {value?} {--secret}` prompts hidden when `--secret` is used without a value.
  - `ventaship:account-show {account}` masks secrets to their last 4 characters.
  - `ventaship:account-test {account}` runs `testConnection()`.
- **`RecordCourierCalls`** listens for `ResponseReceived` and `ConnectionFailed` and writes `courier_calls`, using `Context::get('account_id', 'shipment_id', 'operation')`. It stores the host and path only, never auth headers. It keeps an excerpt (4 KB at most, with phones and names masked) only for status 400 and above.

- [ ] **Step 1:** Copy the port. Run `php -l` over every file. Expected: QuadX and SPX parse. Note LBC's signature mismatches for Task 8.
- [ ] **Step 2: Write the tests.**
  - Port `QuadXStatusPhaseTest` unchanged except the namespace.
  - `AccountSettingsTest::test_secret_round_trip_and_audit`.
  - `test_settings_are_per_account`: two QuadX accounts with different `quadx_staging_api_key` values each read their own.
  - `QuadXFulfillmentTest::test_create_shipment_posts_reference_and_recipient`:
    - `Http::fake(['api.staging.quadx.xyz/*' => Http::response(<fixture: order created, tracking "QX-TEST-0001">)])`
    - `createShipment(CourierOrder::fromApi($shipment), [...])` returns `success === true` and `tracking_number === 'QX-TEST-0001'`
    - the recorded request body contains `"VS000001"` as the reference and the recipient `"Ñora Dela Cruz"` unchanged
    - the `Authorization` header is a Bearer JWT
  - `test_cancel_refused_when_moving`.
  - `test_print_label_returns_provider_url`.
  - `CourierCallLoggingTest::test_call_logged_without_auth_header`.
  - `AccountCommandsTest::test_set_secret_is_encrypted_and_masked_on_show`.
- [ ] **Step 3:** Run them. Expected: FAIL.
- [ ] **Step 4: Implement** the refactor listed in Interfaces, for the shared files and QuadX.
  - `CourierServiceProvider` registers `quadx`, `spx` and `lbc`.
  - QuadX fixtures go in `tests/Fixtures/quadx/*.json`. Take the shapes from `QuadXClient` and `QuadXFulfillment` parsing code.
- [ ] **Step 5:** Run `php artisan test --filter='QuadX|AccountSettings|AccountCommands|CourierCallLogging'`. Expected: PASS.
- [ ] **Step 6:** Commit: `feat: courier port with per-account settings; QuadX adapted`.

### Task 7: Phases, payment state and the status recorder

**Files:**
- Create: `app/Shipments/{PhaseResolver,PaymentState,StatusRecorder}.php`, `app/Webhooks/Outbound/Outbox.php` (only `record()`; delivery comes in Task 13)
- Modify: `app/Couriers/CourierStatusLabel.php`, which reads `CourierStatusLabelSet` (the `courier_status_labels` table)
- Test: `tests/Unit/Shipments/PhaseResolverTest.php`, `tests/Feature/Shipments/StatusRecorderTest.php`

**Interfaces:**
- **`PhaseResolver`:**
  - `phase(string $courier, ?string $status): string` follows the base plan A5 resolution: `status_mappings` override, then `CourierRegistry::describe($courier)->statusPhase($status)['phase']`, then `in_transit` for an unknown non-empty status.
  - `label(string $courier, string $status): string`
  - The spine constant is `PhaseResolver::SPINE = ['draft','booking','attention','to_handover','in_transit','delivered','failed','lost_damaged','returning','cancelled']`.
- **`PaymentState::after(Shipment $s, string $phase): string`** follows the A4 table and never moves backwards. The order is `cod_unpaid` < `cod_collected` < `cod_remitted`, and `cod_void` only comes from `cod_unpaid`.
- **`StatusRecorder::record(Shipment $s, string $courierStatus, CarbonInterface $occurredAt, string $source, ?string $remarks = null, array $raw = [], ?int $receiptId = null): ?ShipmentEvent`**
  - It runs in one transaction with the shipment row locked.
  - It inserts the event and returns null on a duplicate (the unique key).
  - Only when `$occurredAt >= last_event_at` (or `last_event_at` is null) does it update `courier_status`, `phase`, `payment_state`, `last_event_at` and `terminal_at` (on terminal statuses).
  - It always writes the outbox `shipment.status_changed` for a new event.
  - When `payment_state` changed, it also writes `shipment.payment_state_changed`.
- **`Outbox::record(ClientApp $app, string $type, array $data, ?Shipment $s = null): OutgoingEvent`**
  - It must run inside the caller's transaction.
  - It increments `shipments.event_sequence` and copies it to `sequence`.
  - It is a no-op when `$s?->client_app_id` is null (manual or CSV shipments have no app).

- [ ] **Step 1: Write the tests.**
  - `PhaseResolverTest::test_override_wins_then_courier_then_in_transit`.
  - `test_override_outside_spine_rejected`: saving a `StatusMapping` with phase `"foo"` throws a validation exception from the model's `saving` hook.
  - `StatusRecorderTest::test_new_event_updates_phase_and_writes_outbox`: the QuadX `"delivered"` status gives phase `delivered`, `payment_state` going from `cod_unpaid` to `cod_collected`, and two outbox rows with sequences 1 and 2.
  - `test_duplicate_event_is_ignored`.
  - `test_older_event_never_moves_phase_back` (Review Focus 2):
    1. record `delivered` at 10:00
    2. record `in_transit` at 09:00
    3. expect phase still `delivered`, both events stored, `payment_state` `cod_collected`
  - `test_prepaid_stays_prepaid`.
  - `test_cancelled_cod_becomes_void`.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run them. Expected: PASS.
- [ ] **Step 5:** Commit: `feat: phase resolution, payment state and status recorder`.

### Task 8: SPX and LBC adapters against fakes, with live guards

**Files:**
- Modify: `app/Couriers/Spx/*`, `app/Couriers/Lbc/*`, `app/Couriers/TrackingNumbers/*`
- Create: `resources/data/ph-provinces.php` (exported once from `~/ventacart-pro/ventacart/app/Services/Address/DeepAddressData.php`, name → PSGC), `app/Console/Commands/LbcSeriesAdd.php`
- Test: port `tests/Unit/Couriers/Spx{Client,Fulfillment,Signer}Test.php` and `tests/Feature/Couriers/Lbc*Test.php` and `TrackingNumberSeriesTest.php`. Create `tests/Feature/Couriers/LiveGuardTest.php`.

**Interfaces:**
- SPX and LBC take `(CourierAccount, AccountSettings)`.
- SPX: label storage paths become `labels/{courier}/{tracking}.pdf`, and `createShipperAccount(string $phone, string $email)` takes both explicitly.
- **LBC:**
  - `checkServiceability(CourierOrder $o, PickupPoint $from)` and `resolvePendingCreation(CourierOrder $o): ?array`, returning null.
  - `OrderHistory` writes become a returned `notice` and `attention_reason`, and `isCodOrder` becomes `$order->cod`.
  - Series come from `tracking_series` where `courier_account_id` is the account. `tracking_numbers.shipment_id` replaces `order_id`. The row lock stays.
  - `LbcAreaResolver` uses `codes.state` first, then `ph-provinces.php`.
- `LiveGuard` is called inside `LbcClient::send()` before every request, and in `SpxClient` for create and cancel when `mode === 'production'`.
- `ventaship:lbc-series-add {account} {first} {last}` refuses to save a range that overlaps any existing LBC range.

- [ ] **Step 1:** Port the listed tests. Swap `CourierSetting` and global seeding for `AccountSettings` on a factory account, and keep their assertions.
- [ ] **Step 2: Write `LiveGuardTest`.**
  - `test_lbc_blocked_when_env_off`, `test_lbc_blocked_when_setting_off`, `test_lbc_blocked_when_account_latch_off`: each asserts that `LiveBookingBlocked` is thrown, with `Http::assertNothingSent()`.
  - `test_lbc_allowed_only_with_all_locks_and_fake`.
  - `test_spx_uat_needs_no_lock`.
  - `test_spx_production_create_blocked_without_locks`.
  - `test_quadx_never_guarded`.
- [ ] **Step 3:** Run them. Expected: FAIL. LBC fatals until its signatures are fixed.
- [ ] **Step 4:** Implement the adaptations.
- [ ] **Step 5:** Run `php artisan test --filter='Spx|Lbc|TrackingNumber|LiveGuard'`. Expected: PASS.
- [ ] **Step 6:** Commit: `feat: SPX and LBC adapted to accounts, with live-booking guards`.

### Task 9: Read endpoints and shipment creation

**Files:**
- Create: `app/Http/Controllers/Api/V1/{Couriers,CourierAccounts,Coverage,Quotes,PickupSlots,Shipments}Controller.php`, `app/Http/Requests/Api/V1/CreateShipmentRequest.php`, `app/Http/Resources/*`, `app/Shipments/ShipmentCreator.php`, `tests/Concerns/BuildsShipments.php`
- Test: `tests/Feature/Api/ReadEndpointsTest.php`, `tests/Feature/Api/CreateShipmentTest.php`

**Interfaces:**
- **Endpoints and bodies** are exactly as in base plan §2 "Endpoints" and "Create a shipment", plus:
  - `submit` (bool, default true): when false, it stores a `draft`
  - `order_label` (64 chars or fewer)
  - `payment.method` (`cod` or `prepaid`). Required. `payment.cod_amount` is required, numeric and > 0 when the method is cod.
- `POST /shipments` and `POST /shipments/{id}/submit` use `idempotent`.
- **Rate limits** as in base plan §2: limiters named `api`, `api-coverage`, `api-create` and `api-labels`, keyed by `api_keys.id`.
- **`ShipmentCreator::create(?ClientApp $app, array $data, string $source = 'api'): Shipment`:**
  - It resolves the account: `account_id`, or the default for `courier`. The account must be active, and `configurationIssues()` must be empty, otherwise 422 `account_misconfigured`.
  - It builds `courier_ref` = prefix plus zero-padded id (`VS000123`), `items_count` and `items_summary` (255 chars at most, `"Blue Tee ×2, Cap ×1"`).
  - It normalises the recipient and pickup phones through the port's `PhPhone`.
  - It sets `payment_state` (`prepaid` or `cod_unpaid`) and `active_reference`.
  - For `state = queued`, it dispatches `BookShipment` after commit (Task 10).
  - A duplicate live reference answers 409 `active_shipment_exists`, with the existing shipment's `id` in `errors.shipment`.
- **`ShipmentResource`** carries the fields from base plan §2, plus `payment_method`, `payment_state`, `order_label`, `source` and `items_summary`. Parties appear only with `?include=parties`.
- **`GET /shipments/{id}`** resolves only shipments where `client_app_id` is the caller's app. Others answer 404.

- [ ] **Step 1: Write the tests.**
  - `ReadEndpointsTest::test_couriers_lists_capabilities`: `quadx.cancel === true`, `lbc.cancel === false` and `lbc.poll_only === true`.
  - `test_courier_accounts_never_expose_secrets`: no key in the JSON contains `secret`, `key` or `token` values.
  - `test_coverage_uses_cache`: two identical calls make one faked courier HTTP call.
  - `CreateShipmentTest::test_create_returns_202_queued_and_dispatches_booking`: `Queue::fake()`, then assert pushed `BookShipment` on connection `couriers`.
  - `test_submit_false_creates_draft_without_job`.
  - `test_duplicate_live_reference_is_409` and `test_reference_reusable_after_failed_or_cancelled` (Review Focus 1).
  - `test_money_validation` (Review Focus 3): `cod_amount: "1,290.00"` gives 422 with `errors['payment.cod_amount']`. So does `-5`, and so does a missing amount with `cod`.
  - `test_phone_normalised`: `"+63 917 123 4567"` is stored as `"09171234567"`.
  - `test_unicode_and_long_address_preserved` (Review Focus 5).
  - `test_other_apps_shipment_is_404`.
  - `test_misconfigured_account_is_422`.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run them. Expected: PASS.
- [ ] **Step 5:** Commit: `feat: courier read endpoints and shipment creation`.

### Task 10: Booking job, sweep, wait, list, events and refresh

**Files:**
- Create: `app/Shipments/Jobs/BookShipment.php`, `app/Console/Commands/{ShipmentsSweep,ShipmentsResolve}.php`
- Modify: `ShipmentsController` (`index`, `show`, `events`, `refresh`, `submit`; `?wait=` on store)
- Test: `tests/Feature/Shipments/BookShipmentTest.php`, `tests/Feature/Api/ShipmentQueriesTest.php`, `tests/Feature/Console/SweepTest.php`

**Interfaces:**
- **`BookShipment(int $shipmentId)`** runs on connection `couriers`, queue `couriers`, with `tries = 1`, `maxExceptions = 1`, `timeout = 300` and `failOnTimeout = true`. Its flow follows base plan §2 "Booking safety":
  - It takes the lock `shipment:{id}` (600 seconds) and re-checks the state.
  - It sets `Context` for logging and holds no transaction during the courier call.
  - **Success:** `booked`, plus outbox `shipment.booked` and `StatusRecorder` with the initial status (source `system`).
  - **Refusal:** `failed`, `active_reference = null`, and outbox `shipment.booking_failed`.
  - **Throwable:** `unknown`, `needs_attention = true`, outbox `shipment.unknown`, and **no rethrow**. `LiveBookingBlocked` counts as a refusal with code `live_booking_blocked`, not as unknown.
  - **Cancel requested while booking:** after success, it dispatches `CancelAtCourier` (Task 11).
- **`ventaship:shipments-sweep`** (every minute): queued for more than 30 minutes becomes `failed` (`error_code = not_started`). Booking for more than 10 minutes, and not SPX `creating`, becomes `unknown`.
- **`ventaship:shipments-resolve {shipment} {--not-booked} {--tracking=}`** is the operator's way to resolve `unknown`. It writes outbox `shipment.resolved`.
- **`?wait=N`** (N ≤ 20) polls the row every 500 ms until the state is neither queued nor booking.
- **`GET /shipments`** filters as in base plan §2: `ids` (50 at most), `client_reference`, `state`, `needs_attention`, `updated_since` and `cursor` (cursor pagination, 50 per page).
- **`POST /shipments/{id}/refresh`** is limited to once per minute per shipment (429 otherwise). It calls `getTracking`, then `StatusRecorder` for each event (source `refresh`).

- [ ] **Step 1: Write the tests.** Each uses `CourierRegistry::fake('quadx', FakeCourier::make()->...)`.
  - `test_success_books_and_emits_booked`: state `booked`, the tracking number set, an outbox `shipment.booked`.
  - `test_refusal_fails_and_frees_reference`.
  - `test_exception_marks_unknown_and_never_retries`: the fake throws `ConnectionException`. The state is `unknown`, the job is not released, and `jobs` is empty afterwards.
  - `test_live_block_is_refusal_not_unknown`.
  - `test_job_skips_when_state_changed`: the state is already `cancelled`, so there are no fake calls.
  - `SweepTest::test_stale_queued_fails` and `test_stale_booking_becomes_unknown`.
  - `ShipmentQueriesTest::test_list_by_ids_and_cursor`, `test_events_timeline`, `test_refresh_records_events_and_throttles`.
  - `test_wait_returns_booked_state`: run the job synchronously with `Queue::fake` off and the `sync` connection.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run them. Expected: PASS.
- [ ] **Step 5:** Commit: `feat: safe booking job, sweep, queries and refresh`.

### Task 11: Cancel

**Files:**
- Create: `app/Shipments/Canceller.php`, `app/Shipments/Jobs/CancelAtCourier.php`
- Modify: `ShipmentsController@cancel` (`idempotent`)
- Test: `tests/Feature/Shipments/CancelTest.php`

**Interfaces:**
- **`Canceller::request(Shipment $s): Shipment`** applies base plan §2 "Cancel rules" exactly.
  - A `queued` or `draft` shipment goes straight to `cancelled`.
  - The outbox gets `shipment.cancelled` or `shipment.cancel_failed`.
  - `active_reference` becomes null on `cancelled`.
- **`CancelAtCourier(int $shipmentId)`** runs on the `couriers` queue with `tries = 1`.

- [ ] **Step 1: Write the tests**, one per row of the cancel-rules table:
  - `test_queued_cancels_without_courier_call`
  - `test_booking_marks_requested_then_cancels_after_book`
  - `test_booked_cancelable_calls_courier`
  - `test_lbc_is_unsupported_and_needs_attention`
  - `test_not_cancelable_is_refused`
  - `test_cancel_is_idempotent`
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run them. Expected: PASS.
- [ ] **Step 5:** Commit: `feat: shipment cancellation rules`.

### Task 12: Labels

**Files:**
- Create: `app/Shipments/{LabelStore,LabelMerger}.php`, `app/Http/Controllers/Api/V1/LabelsController.php`
- Test: `tests/Feature/Api/LabelsTest.php`

**Interfaces:**
- **`LabelStore::get(Shipment $s): ShipmentLabel`:**
  - It fetches on first use: a QuadX URL is downloaded server-side through `Http`, and SPX and LBC use the port.
  - It stores to the local private disk at `labels/{courier}/{tracking}.pdf`, recording `bytes`, `sha256` and `fetched_at`.
  - It throws `ApiError(409, 'label_not_ready')` before booking, and `ApiError(502, 'courier_unavailable', retryable: true)` when the fetch fails.
- **`LabelMerger::merge(array $paths): array{type: 'pdf'|'zip', path: string}`:**
  - It runs `pdfunite` through `Process::timeout(60)` from `config('couriers.pdfunite_path', '/usr/bin/pdfunite')`.
  - It falls back to a ZIP when the binary is missing or the merge fails.
- **`GET /shipments/{id}/label`** answers `application/pdf`.
- **`POST /labels {shipment_ids ≤ 50}`** answers PDF or ZIP, with `Ventaship-Skipped: shp_…,shp_…`, and 409 when nothing is printable.

- [ ] **Step 1: Write the tests.**
  - `test_single_label_fetched_once_then_served_from_disk`: one faked HTTP call across two GETs.
  - `test_label_not_ready_409`.
  - `test_merge_two_pdfs`: use two tiny fixture PDFs in `tests/Fixtures/pdf/`. The response content type is `application/pdf`, and `Ventaship-Skipped` lists the unbooked one.
  - `test_zip_fallback_when_pdfunite_missing`: set the config path to `/nonexistent`.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run them. Expected: PASS.
- [ ] **Step 5:** Commit: `feat: waybill labels, single and merged`.

### Task 13: Outgoing webhooks

**Files:**
- Create: `app/Webhooks/Outbound/{Signer,RetrySchedule,EventPayload}.php`, `Jobs/DeliverEvent.php`, `app/Console/Commands/{EventsDeliver,EventsResend,AppPing}.php`
- Test: `tests/Feature/Webhooks/OutboundDeliveryTest.php`, `tests/Unit/Webhooks/RetryScheduleTest.php`

**Interfaces:**
- **`Signer::headers(string $secret, string $body, OutgoingEvent $e): array`** returns `X-Event-Id`, `X-Event-Type`, `X-Timestamp` and `X-Signature: sha256=HMAC(secret, "{ts}.{body}")`.
- **`EventPayload::for(OutgoingEvent $e): string`** builds the JSON envelope from base plan §2, with `"app"` in place of `"workspace"`. `data.shipment` is a `ShipmentResource` snapshot taken at record time and stored in `payload`.
- **`RetrySchedule::delayAfter(int $attempts, CarbonInterface $windowStart): ?int`** returns seconds: `[60, 300, 900, 1800, 3600, 7200, 14400]`, then 21600 each time. It returns null once `now − windowStart ≥ 72h`.
- **`DeliverEvent(int $eventId)`:**
  - It leases with `lockForUpdate` and a 10-minute lease, then POSTs with a 10-second timeout. Only 2xx counts as success.
  - Each attempt writes an `outgoing_attempts` row.
  - **Ordering:** it does not deliver event N for a shipment while an earlier sequence for that shipment is still `pending`. It reschedules itself 30 seconds later.
- **`ventaship:events-deliver`** (every minute) dispatches due events, ordered by `(shipment_id, sequence)`.
- **`ventaship:events-resend {event}`**.
- **`ventaship:app-ping {app}`** records and delivers a `ping` event.

- [ ] **Step 1: Write the tests.**
  - `RetryScheduleTest::test_schedule_and_give_up_at_72h`.
  - `OutboundDeliveryTest::test_signed_delivery_marks_delivered`: `Http::fake(['client.test/*' => 200])`, then assert the signature verifies with the app secret.
  - `test_non_2xx_schedules_retry`.
  - `test_gives_up_after_72h` (Review Focus 4).
  - `test_dead_app_does_not_block_other_app` (Review Focus 4): app A answers 500, app B answers 200, and B's events are delivered in the same run.
  - `test_per_shipment_order_respected`: sequence 2 waits while sequence 1 is pending.
  - `test_ping_command`.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run them. Expected: PASS.
- [ ] **Step 5:** Commit: `feat: signed outgoing webhooks with retries`.

### Task 14: Courier webhooks, receipts, relay and polling

**Files:**
- Create: `app/Http/Controllers/Webhooks/CourierWebhookController.php`, `app/Webhooks/Inbound/{AccountRouter,ReceiptIngestor}.php`, `Jobs/{ProcessReceipt,RelayReceipt}.php`, `app/Shipments/Poller.php`, `app/Console/Commands/{CouriersPoll,ReceiptsReprocess,LogsPrune,PiiPurge}.php`
- Modify: `routes/web.php` (webhook routes, CSRF-exempt, stateless, `throttle:courier-webhooks`), `routes/console.php` (the full schedule)
- Test: `tests/Feature/Webhooks/InboundQuadXTest.php`, `InboundSpxTest.php`, `tests/Feature/Shipments/PollerTest.php`, `tests/Feature/Console/ScheduleTest.php`

**Interfaces:**
- **Routes:**
  - `POST /webhooks/quadx?token=…`
  - `POST /webhooks/spx/{token}`, plus the fallback `POST /webhooks/spx` matched by the `app-id` header
- **`AccountRouter::quadx(string $token): ?CourierAccount`** matches by sha256 against `webhook_token_hash`. **`spx(?string $token, Request $r): ?CourierAccount`**.
- Auth, receipt statuses, matching order, the fast ack, duplicate handling, unmatched relay with verbatim headers, and unrouted retention all follow base plan §3 exactly.
- **`ProcessReceipt(int $receiptId)`** runs on queue `inbound`. It parses through the courier's `parseWebhook`, matches the shipment, then calls `StatusRecorder::record(..., source: 'webhook', receiptId: …)`.
- **`Poller::run(): int`** groups by account under the lock `poll:{account}`, honours `next_poll_at` and `ThrottlesStatusPolling`, resolves SPX pending creations first, and records with source `poll`.
- **Schedule** (in `routes/console.php`). Every command is prefixed `ventaship:`.
  - `ventaship:couriers-poll` every 10 minutes with `withoutOverlapping`
  - `ventaship:shipments-sweep`, `ventaship:events-deliver` and `ventaship:receipts-reprocess` every minute
  - `request_signatures` prune hourly
  - `ventaship:logs-prune` daily (api_requests 30 days, courier_calls and receipts 90 days)
  - `ventaship:pii-purge` daily (`privacy.retention_days` after `terminal_at`)

- [ ] **Step 1: Write the tests.**
  - `InboundQuadXTest::test_valid_token_stores_receipt_acks_and_records_event`: the fixture QuadX payload for tracking `QX-TEST-0001` answers 200, and after the queue runs there's a `shipment_events` row with source `webhook` plus an outbox row.
  - `test_bad_token_403_and_not_stored_in_full`.
  - `test_unmatched_is_200_and_relayed_verbatim_when_relay_url_set`: `Http::fake` the relay and assert an identical body and token.
  - `test_duplicate_webhook_records_once`.
  - `InboundSpxTest::test_path_token_and_signature`, `test_app_id_fallback`, `test_bad_signature_401`. Use the ported `SpxSignerTest` fixtures.
  - `PollerTest::test_polls_non_terminal_only_and_throttles_lbc`.
  - `ScheduleTest::test_expected_commands_scheduled`: assert `Schedule::events()` contains each command with its frequency.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run them. Expected: PASS.
- [ ] **Step 5:** Commit: `feat: courier webhook router, receipts, relay and polling`.

### Task 15: End-to-end proof, API contract and staging runbook

**Files:**
- Create: `tests/Feature/EndToEnd/QuadXLifecycleTest.php`, `docs/api.md`, `docs/runbook-staging.md`, `tests/Fixtures/api/*.json` (example request and response bodies, shared with the docs)
- Test: `QuadXLifecycleTest`, plus `tests/Feature/Docs/ApiExamplesTest.php`

**Interfaces:**
- Consumes everything above.
- `docs/api.md` is what the VentaCart Pro session builds against. It is the only thing Plan B needs from this repo.

- [ ] **Step 1: Write `QuadXLifecycleTest::test_book_label_webhooks_to_delivered`.** It uses the real `QuadXFulfillment` with `Http::fake` for the QuadX staging host and the client webhook host. Queues run synchronously.
  1. `makeClient('https://client.test/hooks')`
  2. create a QuadX account and set staging keys
  3. signed `POST /api/v1/shipments` with COD ₱1,290.00 answers 202
  4. after the job: state `booked`, tracking `QX-TEST-0001`
  5. `GET /shipments/{id}/label` answers 200 PDF
  6. post QuadX webhook fixtures `picked_up`, `in_transit`, `delivered` to `/webhooks/quadx?token=…`
  7. run `ventaship:events-deliver`
  8. assert `client.test/hooks` received, in sequence order, `shipment.booked`, three `shipment.status_changed` and one `shipment.payment_state_changed` (to `cod_collected`), each with a valid `X-Signature`
  9. final `GET` shows phase `delivered` and `payment_state` `cod_collected`
- [ ] **Step 2:** Run it. Expected: PASS if Tasks 1–14 are right. A failure here is a bug in an earlier task; fix it there.
- [ ] **Step 3: Write `docs/api.md`.** It covers:
  - base URL and versioning
  - authentication and signing, with PHP and Node snippets that reproduce `Signature::sign`
  - idempotency, the error model and codes, and rate limits
  - every endpoint, with request and response examples taken from `tests/Fixtures/api/*.json`
  - the Shipment resource fields
  - shipment states, phases (the spine) and payment states
  - the cancel rules
  - outgoing webhooks: types, envelope, headers, verification snippet, retries, dedupe on `id`, ordering by `sequence`
  - a quickstart: create an app, issue a key, ping, create a shipment, receive webhooks
- [ ] **Step 4: Write `ApiExamplesTest`.** It validates that every response fixture in `tests/Fixtures/api/` matches the actual `ShipmentResource` keys, so the docs can't drift from the code.
- [ ] **Step 5: Write `docs/runbook-staging.md`.** It covers:
  - starting `php artisan serve --port=8040`, the two queue workers (`--queue=couriers --timeout=300 --tries=1` on connection `couriers`; and `--queue=inbound,outgoing,default`) and `schedule:work`
  - the commands to create an app, a key and a QuadX staging account
  - a curl and PHP example of a signed create
  - how to point the QuadX staging webhook at `/webhooks/quadx?token=…`, which needs a reachable URL (owner decision O1)
  - **simulating** the last steps when staging never sends them: `curl` the saved fixture payloads to the local webhook route with the account token
  - the live-parcel checklist for later: O1 host, workers under systemd, backups of the DB, `storage/app/labels`, and `VENTASHIP_VAULT_KEY` kept separately
- [ ] **Step 6:** Run the full suite with `php artisan test`. Expected: all PASS, and no `StrayRequestException`.
- [ ] **Step 7:** Commit: `docs: API contract and staging runbook; end-to-end QuadX test`.
- [ ] **Step 8: Owner step.** Run the staging runbook with the owner's QuadX staging keys (entered by the owner with `ventaship:account-set --secret`). Record the outcome in `docs/runbook-staging.md`, then tell the owner `docs/api.md` is ready to hand to the VentaCart Pro session.

---

## Not in this plan

- **Plan B, the VentaCart Pro side:** owned by the VentaCart Pro session.
- **Part 2:** admin login and every screen, including the courier accounts page, manual entry, CSV import, status mapping, connected platforms, System and settings. Each one needs a browser draft first.
- **The live QuadX parcel:** waits on O1.
