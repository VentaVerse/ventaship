# Ventaship Part 2f: Connected platforms screen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Connect a platform such as VentaCart Pro from the browser:
- add the platform
- issue its API key and signing secret
- set its webhook URL and secret
- test the connection
- see recent deliveries and API calls

Keys and secrets show as masked fields with View, and they can be viewed again at any time (owner, 2026-11-09).

**Why the signed API stays (owner question, 2026-11-09):**
- It's server-to-server, and Ventaship signs its webhooks back to the platform with the same kind of secret.
- Signing also blocks tampering and replays.
- `docs/api.md` documents it, and the VentaCart Pro session is building to it.

Sanctum isn't added.

**Architecture:**
- A `PlatformApps` service is extracted from the `app-create`, `key-issue`, `key-revoke`, `app-ping` and `events-resend` commands. The CLI and the UI share it, and CLI output stays unchanged.
- API keys gain a vault-encrypted copy, so they can be revealed again. The SHA-256 hash stays as the lookup.
- The reveal works like the courier keys: POST, throttled, audited, `no-store`.

**Spec:** `docs/superpowers/specs/2026-11-08-ventaship-design.md`:
- D2: connected platforms = client apps, each with keys, webhook URL and secret, deliveries and call logs.
- D11 credential handling.
- The owner's masked-field rule.

## Global Constraints

- **Scope:**
  - Writes only inside `/home/ubuntu/ventaship`.
  - No cron or workers.
  - Never write to the dev database outside migrations.
- **The API contract is unchanged.**
  - Signing, headers and errors stay as `docs/api.md` describes them, and ApiExamplesTest stays green.
  - The CLI commands keep their output.
- **Credentials:**
  - Masked field (dots plus the last 4 characters) with **View** and **Copy**.
  - Viewing is throttled 10 per minute per admin (reuse the `secret-reveals` limiter), audited with names only, and served with `Cache-Control: no-store`.
  - Plaintext never appears in page HTML, session, flash, logs or audit rows.
  - **Edit** doesn't apply to generated keys. Use **Revoke** and **Issue new key**, and **Regenerate** for the webhook secret, both behind `<x-ui.confirm>`.
- **Look:**
  - The existing Blotter components, form width 880 px, list width 1240 px.
  - No `<style>` or `style=""` in Blade.
  - No Blade directives inside `<x-ui.*>` tags.
  - No native dialogs.
  - No explainer copy.
  - Text at 4.5:1 or better. Targets at least 44 px under 640 px.
- **Tests:**
  - `Http::preventStrayRequests()`. The Ping and webhook delivery tests use `Http::fake`.
  - The full suite stays green.

## Review Focus

1. **Revealing a key** returns exactly the key the API accepts: sign a request with the revealed key and secret, and the API authenticates it. Revoked keys can't be revealed or used.
2. **Keys issued before this plan** have no encrypted copy. They show "Issued before keys were viewable", offer Revoke only, and never 500.
3. **Regenerating the webhook secret** takes effect on the next delivery. Deliveries already signed are untouched.
4. **Platform list and page** never leak the full key, the secret or the webhook secret (grep tests).
5. **Ping and resend** call the platform URL synchronously in the request. A failure shows a short message ("No answer from the platform." or the HTTP status). It never 500s.

---

### Task 1: PlatformApps service and viewable keys

**Files:**
- Create:
  - `app/Platforms/PlatformApps.php`
  - a migration adding `api_keys.key_encrypted` (text, nullable, vault-encrypted)
- Modify:
  - `app/Console/Commands/{AppCreate,KeyIssue,KeyRevoke,AppPing,EventsResend}.php`, to call the service
  - `app/Models/ApiKey.php`
- Test: `tests/Feature/Platforms/PlatformAppsServiceTest.php`. The existing console tests stay unchanged.

**Interfaces:**
- **`create(string $name, ?string $webhookUrl, ?array $actor): ClientApp`**
  - Generates the webhook secret, vault-encrypted.
  - Audited `client_app.created`.
- **`update(ClientApp $app, array $data, ?array $actor)`**
  - Covers name, webhook URL (http or https only, as today), status (`active` / `disabled`) and the webhook event filter if one exists.
  - Audited.
- **`issueKey(ClientApp $app, string $name, ?array $actor): array{key: ApiKey, plaintext_key: string, secret: string}`**
  - Stores `key_hash`, `key_prefix`, `key_encrypted` and `signing_secret`.
  - Audited `api_key.issued` with the prefix only.
- **`revokeKey(ApiKey $key, ?array $actor)`**, audited.
- **`revealKey(ApiKey $key, string $part, array $actor): string`**
  - `$part` is `key` or `secret`.
  - Refuses revoked keys, and keys with a null `key_encrypted` when the part is `key`.
  - Audited `api_key.viewed` with the part and prefix.
- **`revealWebhookSecret(ClientApp $app, array $actor): string`** and **`regenerateWebhookSecret(ClientApp $app, ?array $actor): void`**, audited.
- **`ping(ClientApp $app): array{ok: bool, status: ?int, message: string}`**
  - Never throws.
  - Uses the existing ping logic from `AppPing`.
- **`resend(OutgoingEvent $event, ?array $actor): array{ok: bool, message: string}`**, using the existing resend logic.
- **`masked(string $plaintext): array{length:int,last4:string}`**, showing the last 4 only when the value is 8 characters or longer.

- [ ] **Step 1:** Write the failing tests, including one that signs a request with the revealed values and gets through the API.
- [ ] **Step 2:** Implement.
- [ ] **Step 3:** Run the full suite.
- [ ] **Step 4:** Commit `refactor(platforms): PlatformApps service; viewable API keys`.

### Task 2: Platforms list, add and platform page

**Files:**
- Create:
  - `app/Http/Controllers/Ui/PlatformsController.php`
  - `resources/views/platforms/{index,create,show}.blade.php`
  - `resources/css/pages/platforms.css`
- Modify: `routes/web.php`, so that `apps.index` makes the "Connected platforms" sidebar item appear.

**Routes:** `apps.index` (`/platforms`), `apps.create`, `apps.store`, `apps.show` (`/platforms/{app:public_id}`), `apps.update`.

**List**, one row per platform:
- name
- status badge (Active / Disabled)
- key count
- "Last call" time, from the latest `api_requests` row
- webhook health badge, from the latest delivery: Delivering / Failing / No webhook
- **Open**

The header has **Add platform**. With no platforms, `<x-ui.empty>` shows "No platforms".

**Add form:** name, webhook URL (optional). On save it redirects to the platform page with the flash `Added`.

**Platform page, General section:** name, webhook URL, Active switch. Discard · Save.

### Task 3: Keys, webhook secret, Ping

**Files:** modify `PlatformsController`, `routes/web.php` and `resources/views/platforms/show.blade.php`, and reuse the credential component and JS. Make a small variant with View and Copy plus actions where needed.

**Keys section:**
- A table of keys with name, prefix, created, last used, and status (Active / Revoked).
- Each active key has two masked rows, **API key** and **Signing secret**, each with **View** and **Copy**.
- **Revoke** sits behind a confirm: "Revoke key {prefix}?" / "Revoke key".
- **Issue key** asks for a name (default "Key {n}"). On create, the new key's rows are already shown revealed once on the redirect, through a one-time server flag held in the session that stores **no plaintext**: the page triggers a reveal fetch on load, which is audited.
- Keys issued before this plan show the text "Issued before keys were viewable" with Revoke only.

**Webhook section:**
- **URL**, editable in General.
- **Secret**, masked, with **View**, **Copy** and **Regenerate** (behind a confirm).
- **Send test**, which pings and flashes `Delivered · HTTP {status}` or `No answer from the platform.` / `Failed · HTTP {status}`.

**Routes:**
- `apps.keys.store`
- `apps.keys.revoke`
- `apps.reveal` (POST `/platforms/{app}/reveal/{what}`, where `what` is one of `key:{prefix}`, `secret:{prefix}` or `webhook`, throttled with `secret-reveals`)
- `apps.webhook.regenerate`
- `apps.ping`

### Task 4: Activity (deliveries and API calls)

**Files:** modify `PlatformsController` and `routes/web.php`, and the platform page, which gets two tabs or sections.

**Recent deliveries:**
- The latest 50 `outgoing_events` for the app: time, type, shipment reference (linked to the shipment page), status badge (Delivered / Retrying / Failed / Pending), attempts, and last error (short).
- A **Resend** button on Failed and Delivered rows calls `resend`.

**Recent API calls:**
- The latest 50 `api_requests`: time, method, path, status (badge by class: 2xx good, 4xx attn, 5xx bad), duration, key prefix.
- No bodies.

**Tests:** both lists render, are scoped to the app, and show no payload bodies or secrets. Resend success and failure. Guest redirect.
