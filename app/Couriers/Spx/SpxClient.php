<?php

namespace App\Couriers\Spx;

use App\Couriers\AccountSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Low-level SPX Open API client: HMAC-SHA256 request signing + HTTP wrapper.
 *
 * Auth (docs/spx/integration-brief.md §2, verified against the doc's own
 * test vector — see tests/Unit/SpxSignerTest.php):
 *
 *   originalValue = "{app_id}_{timestamp}_{random_num}_{payload}"
 *   check-sign    = hex(HMAC_SHA256(key: app_secret, message: originalValue))
 *
 * `payload` MUST be the literal JSON bytes sent over the wire — re-encoding
 * independently (e.g. letting the HTTP client re-serialize the array) risks
 * a byte-level mismatch (whitespace/escaping) that SPX rejects as
 * `1007 CheckSignVerificationFailedCode`. post() therefore json_encodes the
 * body exactly once and reuses that same string for both signing and
 * Http::withBody() — never $http->post($url, $body), which would let Guzzle
 * re-encode.
 *
 * Two independent credential pairs (integration-brief.md §2):
 *   - app-id / app-secret   — header-level, issued once per integration.
 *   - user_id / user_secret — body-level, per SPX account (3.1 Create
 *     Account), required by every order-related endpoint.
 * Both secrets are the account's own, stored encrypted (AccountSettings::writeSecret()), mirroring QuadXClient's
 * pattern — see SpxFulfillment::settingsSchema().
 *
 * The environment is the account's: production talks to spx.ph, sandbox to SPX's UAT system.
 */
class SpxClient
{
    private const BASE_URLS = [
        'sandbox'    => 'https://test-stable.spx.ph/',
        'production' => 'https://spx.ph/',
    ];

    public function __construct(private readonly AccountSettings $settings) {}

    /** App-level credentials present — the minimum needed to sign a request. */
    public function isConfigured(): bool
    {
        return $this->appId() !== null && $this->appSecret() !== null;
    }

    /**
     * Which of isConfigured()'s two app-level fields are missing, labeled
     * exactly as SpxFulfillment::settingsSchema() labels them — backs
     * SpxFulfillment::configurationIssues() (Courier's
     * sanctioned evolution #6). Deliberately scoped to the SAME pair
     * isConfigured() checks (never the user_id/user_secret account pair —
     * hasAccountCredentials() is a separate, later step), so this can never
     * disagree with isEnabled()'s own credential check.
     *
     * @return string[]
     */
    public function missingCredentialLabels(): array
    {
        $missing = [];
        if ($this->appId() === null) {
            $missing[] = 'App ID';
        }
        if ($this->appSecret() === null) {
            $missing[] = 'App Secret';
        }

        return $missing;
    }

    /** Body-level account credentials present — required by every order/account endpoint. */
    public function hasAccountCredentials(): bool
    {
        return $this->userId() !== null && $this->userSecret() !== null;
    }

    /** 'production' for a production account, otherwise 'sandbox' (SPX's UAT system). */
    public function mode(): string
    {
        return $this->settings->account()->mode === 'production' ? 'production' : 'sandbox';
    }

    /** The settings key of one credential (app_id, secret_key, user_id, user_secret) for the account's environment. */
    public function credentialKey(string $name): string
    {
        return 'spx_' . $this->mode() . '_' . $name;
    }

    public function baseUrl(): string
    {
        return self::BASE_URLS[$this->mode()];
    }

    // --- Signing ------------------------------------------------------

    /**
     * Pure signer, isolated from Setting/Http so it can be unit-tested
     * directly against integration-brief.md's verified test vector.
     */
    public static function sign(int $appId, int $timestamp, int $randomNum, string $payload, string $appSecret): string
    {
        $original = sprintf('%d_%d_%d_%s', $appId, $timestamp, $randomNum, $payload);

        return hash_hmac('sha256', $original, $appSecret);
    }

    // --- Endpoints ------------------------------------------------------

    /**
     * Task T4: 3.1 Create Account (account/create) — issues the user_id/
     * user_secret pair every order-related SPX call needs, so a fresh
     * install doesn't have to go dig those out of an email. Deliberately
     * takes no user_id/user_secret (that pair is what THIS call produces,
     * not what it authenticates with) — only app-id/app-secret via post()'s
     * usual signing. $email is optional per api.md §3.1.
     */
    public function createAccount(string $phone, ?string $email = null): array
    {
        $body = ['phone' => $phone];
        if ($email !== null && $email !== '') {
            $body['email'] = $email;
        }

        return $this->post('/open/api/v1/account/create', $body);
    }

    /**
     * 3.2 Check Account Credentials — the cheapest authenticated call in the
     * API surface (no order side-effects), used as SPX's "test connection".
     */
    public function verifyAccount(): array
    {
        return $this->post('/open/api/v1/account/verify', [
            'user_id'     => (int) $this->userId(),
            'user_secret' => (string) $this->userSecret(),
        ]);
    }

    /**
     * 1.4 Create Order V2 (batch_create_order) — submits up to 100 orders,
     * returns only `batch_no` (data.batch_no). Genuinely async: the caller
     * must poll getOrderCreateResult() for the real tracking number(s).
     *
     * @param array<int,array> $orders each shaped per api.md §1.4's orders[] table
     */
    public function createOrders(array $orders): array
    {
        return $this->post('/open/api/v2/order/batch_create_order', [
            'user_id'     => (int) $this->userId(),
            'user_secret' => (string) $this->userSecret(),
            'orders'      => $orders,
        ]);
    }

    /** 1.5 Get Order Create Result V2 (get_order_create_result) — poll with the batch_no from createOrders(). */
    public function getOrderCreateResult(int $batchNo): array
    {
        return $this->post('/open/api/v2/order/get_order_create_result', [
            'user_id'     => (int) $this->userId(),
            'user_secret' => (string) $this->userSecret(),
            'batch_no'    => $batchNo,
        ]);
    }

    /**
     * 1.3 Cancel Order (batch_cancel_order) — tracking_no_list MUST be a real JSON array, never a comma-joined string.
     */
    public function cancelOrders(array $trackingNumbers): array
    {
        return $this->post('/open/api/v1/order/batch_cancel_order', [
            'user_id'          => (int) $this->userId(),
            'user_secret'      => (string) $this->userSecret(),
            'tracking_no_list' => array_values($trackingNumbers),
        ]);
    }

    /** 1.2 Track Order (batch_search_order). */
    public function trackOrders(array $trackingNumbers): array
    {
        return $this->post('/open/api/v1/order/batch_search_order', [
            'user_id'          => (int) $this->userId(),
            'user_secret'      => (string) $this->userSecret(),
            'tracking_no_list' => array_values($trackingNumbers),
        ]);
    }

    /**
     * 2.1 Check Shipping Fee (batch_check_order): estimated fee BEFORE
     * order creation, and therefore also SPX's only pre-booking answer on
     * whether it serves an address at all (api.md §2.1's fail_list carries
     * the 1002x/1006x serviceability codes). It creates nothing.
     *
     * $timeout is explicit for the caller a person is waiting on (the
     * booking modal's serviceability check, see
     * ServiceabilityService::TIMEOUT_SECONDS); the estimate button keeps the
     * class default.
     */
    public function checkOrderFee(array $orders, ?int $timeout = null): array
    {
        return $this->post('/open/api/v1/order/batch_check_order', [
            'user_id'     => (int) $this->userId(),
            'user_secret' => (string) $this->userSecret(),
            'orders'      => $orders,
        ], $timeout);
    }

    /** 2.2 Get Order Fee (batch_get_asf) — ACTUAL fee AFTER order creation. */
    public function getActualFee(array $trackingNumbers): array
    {
        return $this->post('/open/api/v1/order/batch_get_asf', [
            'user_id'          => (int) $this->userId(),
            'user_secret'      => (string) $this->userSecret(),
            'tracking_no_list' => array_values($trackingNumbers),
        ]);
    }

    /** 1.1 Get Pickup Timeslot (get_pickup_time). $serviceType: 1 = standard service (the only documented value). */
    public function getPickupTime(int $serviceType = 1): array
    {
        return $this->post('/open/api/v1/order/get_pickup_time', [
            'user_id'      => (int) $this->userId(),
            'user_secret'  => (string) $this->userSecret(),
            'service_type' => $serviceType,
        ]);
    }

    /** 5.1 Get Address file download link (get_address_download_url) — no request body beyond the standard headers. */
    public function getAddressDownloadUrl(): array
    {
        return $this->post('/open/api/address/get_address_download_url', []);
    }

    /**
     * 1.6 Get AWB V2 (batch_get_shipping_label) — same submit-then-poll shape
     * as 1.4/1.5 create-order, except it's a SINGLE endpoint acting as both:
     * call it with the batch_no from 1.4 Create Order V2, inspect
     * data.task_status (1 New / 2 Waiting / 3 Running / 4 Done / 5 Failed),
     * and call it again on the same batch_no until it settles — data.awb_link
     * only appears once task_status is 4. See SpxFulfillment::printLabel()
     * for the bounded poll loop built on top of this.
     */
    public function getShippingLabel(int $batchNo): array
    {
        return $this->post('/open/api/v2/order/batch_get_shipping_label', [
            'user_id'     => (int) $this->userId(),
            'user_secret' => (string) $this->userSecret(),
            'batch_no'    => $batchNo,
        ]);
    }

    /**
     * UNDOCUMENTED — absent from api.md entirely, discovered by live-probing
     * the real SPX UAT API (fulfillment-workflow-v2 + SPX plan, Task T3
     * round 2 — see docs/spx/index.md's quirks list). The documented v2
     * endpoint above (getShippingLabel()) reliably sits at `task_status: 3`
     * ("Running", `progress: "0.00%"`) indefinitely on UAT — live-probed
     * 8+ minutes with no change, never settling. This v1 sibling — same
     * `batch_no`, PLUS `tracking_no_list` — returns
     * `{awb_link, fail_list}` SYNCHRONOUSLY on UAT instead. Kept as a
     * fallback SpxFulfillment::pollLabelResult() only reaches for after
     * giving the documented v2 endpoint a short, fair chance to settle on
     * its own — being undocumented, its shape/behavior on PRODUCTION is
     * unverified; worth re-testing there before leaning on it long-term.
     */
    public function getShippingLabelV1(int $batchNo, array $trackingNumbers): array
    {
        return $this->post('/open/api/v1/order/batch_get_shipping_label', [
            'user_id'          => (int) $this->userId(),
            'user_secret'      => (string) $this->userSecret(),
            'batch_no'         => $batchNo,
            'tracking_no_list' => array_values($trackingNumbers),
        ]);
    }

    // --- Webhook signature verification -------------------------------

    /**
     * Task T3: verifies an INBOUND SPX webhook's `check-sign` header.
     * api.md §4.1/§4.2 + integration-brief.md §2: "Every request (including
     * webhook verification) is signed" using the exact same algorithm as
     * outbound calls (sign()) — SPX signs the callback body with OUR
     * app-secret, so we recompute the same HMAC over the four header values
     * + the raw request bytes and compare with hash_equals().
     *
     * $rawBody MUST be the literal bytes SPX sent (Request::getContent()) —
     * never a re-encoded array — for exactly the same byte-fidelity reason
     * post() never lets Guzzle re-serialize an outbound body (see this
     * class's docblock).
     *
     * Also checks the app-id header matches OUR configured app-id — a valid
     * signature computed with the right secret already proves that, but this
     * gives an early explicit rejection instead of a signature mismatch on a
     * spoofed app-id that happens to guess right (defense in depth, appears
     * first so a malformed/missing header never reaches sign()'s int casts).
     * A missing app-id header falls back to our own app id. Freshness of the
     * `timestamp` header is checked by the caller (AccountRouter), so a
     * stale push can be told apart from a bad signature.
     */
    public function verifyWebhookSignature(
        string $rawBody,
        ?string $appIdHeader,
        ?string $checkSignHeader,
        ?string $timestampHeader,
        ?string $randomNumHeader
    ): bool {
        if (!$this->isConfigured()) {
            return false;
        }
        // The 4.1 webhook doc never says SPX sends `app-id` on a push: without it, the signature is checked with
        // this account's own app id (the one SPX signs with if it signs as documented).
        if ($appIdHeader === null || $appIdHeader === '') {
            $appIdHeader = (string) $this->appId();
        }
        if ($checkSignHeader === null || $timestampHeader === null || $randomNumHeader === null) {
            return false;
        }
        if (!ctype_digit($appIdHeader) || !ctype_digit($timestampHeader) || !ctype_digit(ltrim($randomNumHeader, '-'))) {
            return false;
        }
        if ((int) $appIdHeader !== (int) $this->appId()) {
            return false;
        }

        $expected = self::sign((int) $appIdHeader, (int) $timestampHeader, (int) $randomNumHeader, $rawBody, (string) $this->appSecret());

        // Hex is case-insensitive: an upper-case check-sign is the same signature.
        return hash_equals($expected, strtolower(trim($checkSignHeader)));
    }

    // --- HTTP core --------------------------------------------------------

    /**
     * Signs and sends a POST request, unwraps the `{ret_code,message,data}`
     * envelope, and returns `data` on success.
     *
     * @return array the unwrapped `data` payload
     * @throws SpxApiException  ret_code !== 0 (a reachable, well-formed SPX response)
     * @throws \RuntimeException  not configured, transport failure, or a response
     *                            that isn't a well-formed SPX envelope at all
     */
    public function post(string $path, array $body, ?int $timeout = null): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('SPX is not configured (missing app-id/secret-key).');
        }

        $url = rtrim($this->baseUrl(), '/') . '/' . ltrim($path, '/');

        // Encoded ONCE — this exact string is what gets signed AND sent.
        // Order bodies carry raw customer-entered addresses (name, address
        // lines, delivery instructions) — invalid UTF-8 in any of those makes
        // json_encode() return false silently rather than throwing, which
        // would otherwise sign/send the literal string "false" as the body.
        $payload = json_encode($body);
        if ($payload === false) {
            Log::error('SPX request body failed to encode as JSON', [
                'path' => $path, 'json_error' => json_last_error_msg(),
            ]);
            throw new \RuntimeException('SPX request body could not be encoded as JSON: ' . json_last_error_msg());
        }

        $timestamp = time();
        $randomNum = random_int(1, PHP_INT_MAX);
        $appId = (int) $this->appId();
        $checkSign = self::sign($appId, $timestamp, $randomNum, $payload, (string) $this->appSecret());

        try {
            $response = Http::withHeaders([
                    'app-id'     => $appId,
                    'check-sign' => $checkSign,
                    'timestamp'  => $timestamp,
                    'random-num' => $randomNum,
                ])
                ->withBody($payload, 'application/json')
                // Both halves are bounded by the caller's budget when it
                // sets one: SPX's UAT host stopped resolving in DNS, and an
                // unresolvable host has to fail inside the same window a
                // slow response does.
                ->connectTimeout($timeout ?? 40)
                ->timeout($timeout ?? 40)
                ->post($url);
        } catch (\Throwable $e) {
            Log::error('SPX request failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new \RuntimeException('SPX request failed: ' . $e->getMessage(), 0, $e);
        }

        $json = $response->json();
        if (!is_array($json) || !array_key_exists('ret_code', $json)) {
            // The body can echo the recipient back; courier_calls keeps a masked excerpt.
            Log::warning('SPX API returned a non-envelope response', [
                'path' => $path, 'status' => $response->status(),
            ]);
            throw new \RuntimeException("SPX returned an unexpected response (HTTP {$response->status()}).");
        }

        $retCode = (int) $json['ret_code'];
        $message = (string) ($json['message'] ?? '');

        if ($retCode !== 0) {
            Log::warning('SPX API error', ['path' => $path, 'ret_code' => $retCode, 'message' => $message]);
            throw new SpxApiException($message !== '' ? $message : "SPX error {$retCode}", $retCode, $response->status());
        }

        $data = $json['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    // --- Credentials (decrypted) ------------------------------

    private function appId(): ?string
    {
        return $this->settings->read($this->credentialKey('app_id'));
    }

    private function appSecret(): ?string
    {
        return $this->settings->secret($this->credentialKey('secret_key'));
    }

    private function userId(): ?string
    {
        return $this->settings->read($this->credentialKey('user_id'));
    }

    private function userSecret(): ?string
    {
        return $this->settings->secret($this->credentialKey('user_secret'));
    }
}
