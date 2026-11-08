# Ventaship Part 2c: Courier accounts screen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Manage courier accounts in the browser:
- connect an account
- edit its settings
- enter keys in masked fields with View and Edit
- test the connection
- show the webhook address to give the courier
- manage LBC number blocks

After this, the owner can enter QuadX staging keys without the command line.

**Architecture:**
- The console commands' logic (`account-create`, `account-set`, `account-mode`, `account-test`, `lbc-series-add`) moves into one service, `App\Couriers\CourierAccounts`. The commands and the UI both call it, so they can't drift.
- The screens are Blade and Alpine on the existing Blotter components.
- Secrets are revealed only through a throttled, audited JSON endpoint and are never rendered into the page.

**Tech Stack:** Laravel 12, PHP 8.5, Blade, Alpine 3, Tailwind v4, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-11-08-ventaship-design.md` (D9, D11, §9). The draft was approved on 2026-11-08 and lives at `.superpowers/brainstorm/3920586-1794127767/content/courier-accounts.html`.

## Global Constraints

- **Writes only inside `/home/ubuntu/ventaship`.**
- **No cron, scheduler or workers.** "Test connection" calls the courier during the request.
- **Credentials (owner rule):**
  - Keys and secrets are a **masked field with separate View and Edit buttons**, never a hidden or write-only field.
  - The masked view shows dots plus the last 4 characters.
  - **View** fetches the real value from the server and shows it for 30 s, then masks it again.
  - Viewing is throttled (10 per minute per admin) and audited as `courier_account.secret_viewed`, with the key name only.
  - **Edit** clears the field for a new value, and **Cancel** restores the masked view.
  - A secret is **never** rendered into HTML, JSON in the page, logs or audit rows. Only its length and last 4 characters are.
- **Mode toggle (owner rule):** only the selected mode's keys are shown.
  - **QuadX:** `staging` ↔ the `mode_scoped=staging` keys; `production` ("Live") ↔ the `mode_scoped=production` keys.
  - **LBC:** `staging` ↔ the `mode_scoped=test` keys; `production` ↔ the `mode_scoped=live` keys.
    - The toggle also keeps the `lbc_mode` setting in sync (`test` / `live`).
    - The `lbc_mode` select is not shown separately.
  - **SPX:** its keys aren't mode-scoped, so they are always shown. Its modes come from `AccountMode::MODES`: Staging, UAT, Live.
  - The other mode's stored keys are kept, not wiped.
- **D9 live locks are unchanged.** For LBC and SPX the account page shows the per-account **Allow live booking** switch, which is the latch. It does not replace the env flag or the safety setting.
- **Look:** the approved draft plus the impeccable audit fixes:
  - Body-level text ≥ 4.5:1. That includes "Not set" and placeholders.
  - Sidebar group labels ≥ 4.5:1. This is an app-wide token fix.
  - Every repeated button has an accessible name, e.g. `aria-label="View staging API key"`.
  - Reveal and re-mask are announced through an `aria-live="polite"` region ("Shown for 30 seconds" / "Hidden"), not per second.
  - Touch targets ≥ 44 px below 640 px.
  - The SPX tile text colour reaches ≥ 4.5:1.
  - The link next to the public address reads **"Set address"**.
- **The usual rules:**
  - No `<style>` or `style=""` in Blade.
  - No Blade directives inside `<x-ui.*>` tags.
  - No native dialogs.
  - Icons only via `<x-ui.icon>`.
  - No explainer copy.
- **Admin actions are audited** (`actor_type='admin'`, `actor_id`, `ip`).
- **Tests:**
  - `Http::preventStrayRequests()`.
  - Courier calls only through `CourierRegistry::fake`.
  - The full suite stays green: 741 at the start.

## Review Focus

1. **A secret never leaks into a response other than the reveal endpoint.** That covers the page HTML, old input after a validation error, the flash, logs and audit rows. A test greps the rendered page for a known secret.
2. **Saving the form without touching a masked secret leaves it unchanged.** The value must not be overwritten with dots, emptied, or have its last 4 characters stored as the value.
3. **Switching mode never deletes the other mode's keys, and never enables live booking on its own.** For LBC and SPX the latch stays as it was.
4. **"Test connection" against a courier that times out or refuses** shows a short failure and records `last_check_ok=false`. It never 500s.
5. **The webhook address when no public address is set** shows the "Not set" badge with "Set address", and doesn't print a localhost URL as if it were usable.

---

### Task 1: `CourierAccounts` service and the public address setting

**Files:**
- Create: `app/Couriers/CourierAccounts.php`
- Modify: `app/Console/Commands/{AccountCreate,AccountSet,AccountMode,AccountTest,LbcSeriesAdd}.php` to call the service. Their output and messages stay unchanged.
- Modify: `app/Http/Controllers/Ui/SettingsController.php` and `resources/views/settings/edit.blade.php` for a "Public address" field on Couriers & safety.
- Test: `tests/Feature/Couriers/CourierAccountsServiceTest.php`. The existing console tests must stay green, unchanged.

**Interfaces (Produces):**
- **`CourierAccounts::create(string $courier, string $label, string $mode, bool $default, ?array $actor = null): CourierAccount`**
  - Same rules as `account-create`: courier known, mode in `AccountMode::MODES[$courier]`, unique default slot, webhook token generated, `allow_live_booking=false`.
  - Audited `courier_account.created`.
- **`CourierAccounts::setSetting(CourierAccount $a, string $key, string $value, ?array $actor = null): void`**
  - `account-set`'s validation: schema key, max length, option membership, secret vs plain.
  - Writes via `AccountSettings`. Keeps the `spx_app_id` → `external_ref` sync.
  - Audited `courier_account.setting_changed` with the key only.
- **`CourierAccounts::setMode(CourierAccount $a, string $mode, ?array $actor = null): void`**
  - `account-mode`'s rules. For LBC it also syncs `lbc_mode`: `production` → `live`, else `test`.
  - Audited.
- **`CourierAccounts::test(CourierAccount $a): array{ok: bool, message: string}`**
  - Calls `testConnection()`, catching every throwable. A timeout gives `ok=false` with the message `No answer from {Courier}.`
  - Stores `last_check_at` and `last_check_ok`.
- **`CourierAccounts::addLbcSeries(CourierAccount $a, string $first, string $last, ?array $actor = null): void`**
  - Same rules as `lbc-series-add`.
- **`CourierAccounts::revealSecret(CourierAccount $a, string $key, array $actor): string`**
  - The key must be an encrypted schema key.
  - Audited `courier_account.secret_viewed` with the key only.
  - Throttling is done by the route.
- **`CourierAccounts::masked(CourierAccount $a, string $key): ?array{length:int,last4:string}`**
  - Returns `null` when the key isn't set.
- **`$actor`** is `['type' => 'admin', 'id' => ..., 'ip' => ...]`. `null` means `system`, as the commands do today.
- **Setting `webhooks.public_base_url`:**
  - Optional. Must be an absolute `https://` URL with no path, query or trailing slash. Error: `Use https://your-domain`.
  - Shown as "Public address" on Couriers & safety. Audited like the other settings.
  - Helper: `Settings::publicBaseUrl(): ?string`.

- [ ] **Step 1: Write the failing tests**
  - `test_create_matches_command_rules_and_audits`
  - `test_set_setting_rejects_unknown_key_and_bad_option`
  - `test_set_secret_stores_encrypted_and_audit_has_key_only`
  - `test_set_mode_lbc_syncs_key_set_and_keeps_other_keys`
  - `test_test_connection_timeout_is_failure_not_exception`
  - `test_reveal_returns_plaintext_and_audits`
  - `test_masked_shows_length_and_last4_only`
  - `test_public_address_validation`
- [ ] **Step 2: Run** the filter. Expected: FAIL.
- [ ] **Step 3: Implement.** Refactor the commands onto the service.
- [ ] **Step 4:** Run the full suite. Expected: PASS. The console command tests stay unchanged.
- [ ] **Step 5:** Commit `refactor(couriers): CourierAccounts service; public address setting`.

### Task 2: Accounts list and Connect courier

**Files:**
- Create: `app/Http/Controllers/Ui/CourierAccountsController.php` (`index`, `create`, `store`), `resources/views/accounts/index.blade.php`, `resources/views/accounts/create.blade.php`, `resources/css/pages/accounts.css`
- Modify: `routes/web.php`, `app/Support/Navigation.php` (only if routes need it; the Setup group already lists `accounts.index`), and the blotter tokens for the sidebar group label contrast.
- Test: `tests/Feature/Ui/CourierAccountsListTest.php`

**Interfaces:**
- **Routes:** `accounts.index` (GET `/accounts`), `accounts.create` (GET `/accounts/new?courier=quadx`), `accounts.store` (POST `/accounts`).
- **List (as drafted):**
  - One row per account: courier tile, "{Courier} · {label}", the "Default for {Courier}" subline when it applies, a mode badge (Staging / UAT / Live), a connection badge (Connected / Check failed / Not checked) and **Open**.
  - For each courier with no account: a muted row with **Connect**.
  - The header has **Connect courier**.
- **Connect form:** courier (select, or preselected via `?courier=`), name, mode, "Default for this courier" switch. On save it redirects to `accounts.show` and flashes `Connected`.
- **The Shipments page's muted courier cards** now show the "Connect {Courier}" button. It already renders when `Route::has('accounts.create')`.

- [ ] **Step 1: Write the failing tests**
  - `test_list_shows_accounts_and_connect_rows`
  - `test_create_form_and_store_redirects_to_show`
  - `test_store_validation_and_unique_default`
  - `test_sidebar_shows_courier_accounts`
  - `test_cards_show_connect_button`
- [ ] **Step 2: Run** the filter. Expected: FAIL.
- [ ] **Step 3: Implement.** `accounts.show` can be a stub until Task 3, guarded by `Route::has`.
- [ ] **Step 4:** Run `npm run build` and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(ui): courier accounts list and connect`.

### Task 3: Account page, general section and plain settings

**Files:**
- Modify: `CourierAccountsController` (`show`, `update`, `test`), `routes/web.php`
- Create: `resources/views/accounts/show.blade.php`
- Test: `tests/Feature/Ui/CourierAccountShowTest.php`

**Interfaces:**
- **Routes:**
  - `accounts.show` (GET `/accounts/{account:public_id}`)
  - `accounts.update` (PUT)
  - `accounts.test` (POST `/accounts/{account}/test`)
- **Header:**
  - The tile and "{Courier} · {label}", plus the mode and connection badges.
  - **Test connection** posts to `accounts.test` and flashes `Connected` or `Check failed: {message}`.
- **Account section:**
  - name
  - **Mode**: a segmented radiogroup with only that courier's modes
  - **Default for {Courier}**: a switch
  - **Active**: a switch (`status` `active` / `inactive`)
  - For LBC and SPX: **Allow live booking**, a switch that is the latch
- **Booking defaults section:** the non-secret, non-mode-scoped schema fields:
  - selects and text fields
  - for SPX, `spx_app_id`, `spx_user_id` and the pouch presets
  - for LBC, gateway, client code and shipper account number
  - `lbc_mode` is hidden
- **Save** goes through `CourierAccounts::setMode` and `setSetting`, and writes only the keys that changed.
- **Footer:** Discard · Save. Save flashes `Saved`.

- [ ] **Step 1: Write the failing tests**
  - `test_show_renders_sections_per_courier` (QuadX, SPX, LBC)
  - `test_update_changes_name_mode_default_active`
  - `test_lbc_mode_switch_syncs_key_set`
  - `test_allow_live_only_for_lbc_spx`
  - `test_test_connection_success_and_timeout_flash`
  - `test_unknown_account_404`
- [ ] **Step 2: Run** the filter. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Run `npm run build` and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(ui): courier account page`.

### Task 4: Keys, masked fields with View and Edit

**Files:**
- Create: `resources/views/components/ui/credential.blade.php` (masked field plus View/Edit), and a `credentialField` Alpine component in `resources/js/app.js`
- Modify: `CourierAccountsController` (`reveal`), `routes/web.php`, `resources/views/accounts/show.blade.php` (Keys section)
- Test: `tests/Feature/Ui/CourierAccountKeysTest.php`

**Interfaces:**
- **Route `accounts.reveal`:**
  - POST `/accounts/{account}/secrets/{key}/reveal`
  - Throttled 10/min per admin, under `auth` with CSRF
  - Returns JSON `{"value": "..."}`, with headers `Cache-Control: no-store` and `Pragma: no-cache`
  - Unknown or non-encrypted keys get 404
  - Over the limit, 429 with the message `Too many views. Try again in :seconds seconds.`
- **`<x-ui.credential>` props:** `name`, `label`, `account`, `key`, `masked` (`?array{length, last4}`), `revealUrl`.
- **States:**
  - **Masked:** a read-only input showing `••••…{last4}` (up to 16 dots), with **View** and **Edit**.
  - **Viewing:** the real value with **Hide** and **Edit**. It masks itself again after 30 s, announced via `aria-live`.
  - **Editing:** an empty input (`autocomplete="off"`) with **Cancel**.
  - **Not set:** shown as a `Not set` badge (≥ 4.5:1) with **Edit**.
- **On submit:**
  - Only an *edited* credential sends a value, as `secrets[{key}]`.
  - An untouched masked field sends nothing, so the server skips it.
  - An empty edited field is ignored. Removing a secret is out of scope.
- **Keys section:**
  - A badge next to the heading shows the current mode.
  - It shows only the selected mode's keys, per the Global Constraints. It switches **instantly** when the Mode radiogroup changes, via Alpine, before saving. Both sets are in the DOM, masked, and hidden with the `hidden` attribute.
- **Accessible names:** every View, Edit, Hide and Cancel button has `aria-label="{Action} {mode-label} {field label}"`.

- [ ] **Step 1: Write the failing tests**
  - `test_page_never_contains_secret_plaintext` (store a known secret, GET the page, `assertDontSee` the value and assert its last 4 characters are present)
  - `test_reveal_returns_value_no_store_and_audits_key_only`
  - `test_reveal_throttled`
  - `test_reveal_rejects_plain_or_unknown_key`
  - `test_untouched_masked_secret_unchanged_on_save`
  - `test_edited_secret_saved_encrypted`
  - `test_validation_error_does_not_echo_secret` (an edited secret plus an invalid name; the redirected page does not contain the secret)
  - `test_only_selected_mode_keys_visible_and_toggle_markup_present`
- [ ] **Step 2: Run** the filter. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Run `npm run build` and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(ui): courier keys with masked view and edit`.

### Task 5: Webhook address and LBC number blocks

**Files:**
- Modify: `resources/views/accounts/show.blade.php`, `CourierAccountsController` (`series`), `routes/web.php`
- Test: `tests/Feature/Ui/CourierAccountWebhookSeriesTest.php`

**Interfaces:**
- **Webhook section, QuadX and SPX only:**
  - **Address for {Courier}:**
    - QuadX: `{public_base}` + the path of `route('webhooks.quadx', absolute: false)` + `?token={token}`
    - SPX: `{public_base}` + the path of `route('webhooks.spx.token', $token, absolute: false)`
  - It shows masked: the token part is replaced by dots. **View** and **Copy** read the full URL from the same reveal mechanism, using the pseudo key `webhook_url`. That view is audited too, as `courier_account.webhook_viewed`.
  - **Public address:** the configured URL, or an attn `Not set` badge plus a **Set address** link to `settings.edit?tab=couriers`. When it isn't set, the Address row shows `Not set` instead of a URL.
- **LBC number blocks section, LBC only:**
  - A table of the account's `tracking_series` rows: first, last, next, remaining.
  - An **Add block** form with first and last, through `CourierAccounts::addLbcSeries`.
  - Route `accounts.series` (POST `/accounts/{account}/series`). It flashes `Added`.

- [ ] **Step 1: Write the failing tests**
  - `test_webhook_address_built_from_public_base`
  - `test_webhook_not_set_shows_badge_and_set_address_link`
  - `test_webhook_view_returns_full_url_and_audits`
  - `test_page_never_contains_full_token`
  - `test_lbc_blocks_listed_and_added`
  - `test_series_validation_messages`
  - `test_webhook_section_absent_for_lbc`
- [ ] **Step 2: Run** the filter. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Run `npm run build` and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(ui): webhook address and LBC number blocks`.
