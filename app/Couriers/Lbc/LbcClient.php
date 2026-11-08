<?php

namespace App\Couriers\Lbc;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for LBC's four APIs, all on one gateway.
 *
 * Every LBC request is live: there is no test environment, whatever a key is
 * called. So nothing here retries. A retried pickup request could book a
 * second parcel, and LBC has no cancel endpoint.
 *
 * The auth header differs per API (see LbcFulfillment::AUTH_HEADERS). Keys
 * are never logged, and neither is a request body, because it carries the
 * shipper account number and phone numbers.
 */
class LbcClient
{
    public const PICKUP_PATH = 'lbcpickuprequest/v1/api/DirectInjection/InsertPickupRequest';

    public const WAYBILL_PATH = 'lbcwaybill/v1/api/POD/GeneratePOD';

    public const TRACK_PATH = 'lbctrackingapi2/v2/';

    public const CITY_SEARCH_PATH = 'camsbackend/cities/search';

    public const BARANGAY_SEARCH_PATH = 'camsbackend/barangays/search';

    public const VALIDATE_AREA_PATH = 'camsbackend/validate/validateServicableArea';

    private const TIMEOUT_SECONDS = 30;

    private int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(private readonly LbcFulfillment $lbc = new LbcFulfillment())
    {
    }

    /**
     * The same client with a shorter budget per request, for questions asked
     * while somebody waits (the booking modal's coverage check). Never used
     * for the pickup request itself, whose answer decides a tracking number.
     */
    public function withTimeout(int $seconds): self
    {
        $copy = clone $this;
        $copy->timeout = max(1, min($seconds, self::TIMEOUT_SECONDS));

        return $copy;
    }

    // --- Common API: address search and serviceability (read-only) ---------

    /** @return array<int, array>|null LBC's city rows, or null when LBC did not answer */
    public function searchCities(string $term, ?int $provinceId = null, int $limit = 20): ?array
    {
        $response = $this->get('common', self::CITY_SEARCH_PATH, array_filter([
            'searchTerm' => $term,
            'provinceId' => $provinceId,
            'limit' => $limit,
        ], fn ($v) => $v !== null));

        return $this->jsonList($response);
    }

    /** @return array<int, array>|null LBC's barangay rows, or null when LBC did not answer */
    public function searchBarangays(string $term, ?int $cityId = null, int $limit = 20): ?array
    {
        $response = $this->get('common', self::BARANGAY_SEARCH_PATH, array_filter([
            'searchTerm' => $term,
            'cityId' => $cityId,
            'limit' => $limit,
        ], fn ($v) => $v !== null));

        return $this->jsonList($response);
    }

    /**
     * Whether LBC serves a barangay, by its PSGC code (never by names: a name
     * mismatch answers "Not Serviceable", the same as real non-coverage).
     *
     * @return array|null the decoded answer, or null when LBC could not answer
     */
    public function validateServiceableArea(string $psgcCode): ?array
    {
        $response = $this->send('common', fn ($http) => $http->asJson()->post(
            $this->url(self::VALIDATE_AREA_PATH),
            ['psgc_code' => (int) $psgcCode]
        ));

        $data = $response?->successful() ? $response->json() : null;

        return is_array($data) && isset($data['status']) ? $data : null;
    }

    // --- Pickup Request (creates a booking) --------------------------------

    /**
     * Send one pickup request. Called once per reserved tracking number;
     * never retried here or by the caller.
     */
    public function insertPickupRequest(string $xml): LbcPickupResult
    {
        $key = $this->lbc->subscriptionKey('pickup');
        if ($key === null) {
            throw new LbcException('The LBC Pickup Request key is not set.');
        }

        try {
            $response = Http::withHeaders([$this->lbc->authHeader('pickup') => $key])
                ->accept('application/xml')
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withBody($xml, 'text/xml')
                ->post($this->url(self::PICKUP_PATH));
        } catch (\Throwable $e) {
            Log::warning('LBC pickup request got no answer', ['error' => $e->getMessage()]);

            return LbcPickupResult::transportFailure('LBC did not answer: ' . $e->getMessage());
        }

        return LbcPickupResult::fromResponse($response->status(), $response->body());
    }

    // --- Air Waybill (renders a label, books nothing) ----------------------

    /**
     * Render the waybill PDF for the label data given. LBC renders whatever
     * it receives without checking it, so the caller must send the same
     * facts as the pickup request.
     *
     * @return string|null the PDF bytes, or null when no PDF came back
     */
    public function generateWaybill(array $label): ?string
    {
        $response = $this->send('airwaybill', fn ($http) => $http->asJson()->post($this->url(self::WAYBILL_PATH), $label));

        $encoded = $response?->successful() ? $response->json('Rendered_Byte') : null;
        if (!is_string($encoded) || $encoded === '') {
            return null;
        }

        $pdf = base64_decode($encoded, true);

        return is_string($pdf) && str_starts_with($pdf, '%PDF') ? $pdf : null;
    }

    // --- Track and Trace (read-only, SOAP) ----------------------------------

    /**
     * @return array{ok:bool, status_code:?string, status_message:?string, history:array, raw:string}
     */
    public function track(string $trackingNo): array
    {
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body><LBCTrackAndTrace xmlns="http://tempuri.org/"><TrackingNo>'
            . htmlspecialchars($trackingNo, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . '</TrackingNo></LBCTrackAndTrace></soap:Body></soap:Envelope>';

        $response = $this->send('track', fn ($http) => $http->withBody($envelope, 'text/xml; charset=utf-8')->post($this->url(self::TRACK_PATH)));

        $raw = $response?->body() ?? '';
        $result = ['ok' => false, 'status_code' => null, 'status_message' => null, 'history' => [], 'raw' => $raw];

        if ($raw === '') {
            return $result;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            return $result;
        }

        $first = fn (string $name, $node = null) => trim((string) ((($node ?? $xml)->xpath('.//*[local-name()="' . $name . '"]') ?: [''])[0]));

        $result['status_code'] = $first('StatusCode') ?: null;
        $result['status_message'] = $first('StatusMessage') ?: null;
        $result['ok'] = (bool) $response?->successful();

        foreach ($xml->xpath('//*[local-name()="TrackingHistory"][*[local-name()="StatusId"]]') ?: [] as $event) {
            $result['history'][] = [
                'status_id' => $first('StatusId', $event),
                'date' => trim($first('DatePosted', $event) . ' ' . $first('DatePostedTime', $event)),
                'status' => $first('StatusandLocation', $event),
            ];
        }

        return $result;
    }

    // --- HTTP core ---------------------------------------------------------

    private function get(string $api, string $path, array $query): ?Response
    {
        return $this->send($api, fn ($http) => $http->acceptJson()->get($this->url($path), $query));
    }

    /** One request with the API's own auth header; null when it never got an answer. */
    private function send(string $api, callable $call): ?Response
    {
        $key = $this->lbc->subscriptionKey($api);
        if ($key === null) {
            throw new LbcException("The LBC {$api} key is not set.");
        }

        try {
            $response = $call(Http::withHeaders([$this->lbc->authHeader($api) => $key])
                ->connectTimeout($this->timeout)
                ->timeout($this->timeout));
        } catch (\Throwable $e) {
            Log::warning('LBC request got no answer', ['api' => $api, 'error' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful()) {
            Log::warning('LBC request failed', ['api' => $api, 'status' => $response->status()]);
        }

        return $response;
    }

    /** The rows of a search answer; null when there was no readable answer at all. */
    private function jsonList(?Response $response): ?array
    {
        $data = $response?->successful() ? $response->json() : null;

        return is_array($data) && array_is_list($data) ? $data : null;
    }

    private function url(string $path): string
    {
        return $this->lbc->gatewayUrl() . ltrim($path, '/');
    }
}
