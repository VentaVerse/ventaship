<?php

namespace App\Couriers\Lbc;

/**
 * What the Pickup Request endpoint answered, reduced to one of three
 * outcomes that decide what happens to the tracking number:
 *
 *   accepted  IsSuccess true and code 100: LBC saved it, the number is spent.
 *   rejected  IsSuccess false and only field validation codes (101 to 152):
 *             LBC saved nothing, the number is released.
 *   unknown   anything else (timeout, HTTP error, unreadable body, the 1000
 *             range): LBC may have saved it, so the number is spent.
 */
final class LbcPickupResult
{
    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const UNKNOWN = 'unknown';

    /**
     * @param array<int, array{code:string, message:string, tracking_no:string}> $messages
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?bool $isSuccess,
        public readonly array $messages,
        public readonly int $httpStatus,
        public readonly string $rawBody,
        public readonly ?string $error = null,
    ) {
    }

    public static function transportFailure(string $error, int $httpStatus = 0, string $rawBody = ''): self
    {
        return new self(self::UNKNOWN, null, [], $httpStatus, $rawBody, $error);
    }

    /**
     * Parse a response body. LBC answers XML (<ReturnEntity>) to an XML
     * request; the portal's sample shows the same fields as JSON, so both
     * are read.
     */
    public static function fromResponse(int $httpStatus, string $body): self
    {
        $parsed = self::parseJson($body) ?? self::parseXml($body);

        if ($parsed === null) {
            return self::transportFailure('LBC answered with a body that could not be read.', $httpStatus, $body);
        }

        [$isSuccess, $messages] = $parsed;
        $codes = array_column($messages, 'code');

        if ($httpStatus >= 200 && $httpStatus < 300 && $isSuccess === true && in_array(LbcPickupErrors::SUCCESS, $codes, true)) {
            $outcome = self::ACCEPTED;
        } elseif ($isSuccess === false && $codes !== [] && array_filter($codes, fn ($c) => !LbcPickupErrors::isValidationCode($c)) === []) {
            $outcome = self::REJECTED;
        } else {
            $outcome = self::UNKNOWN;
        }

        return new self($outcome, $isSuccess, $messages, $httpStatus, $body);
    }

    public function code(): ?string
    {
        return $this->messages[0]['code'] ?? null;
    }

    /** The tracking number LBC echoed back, if any. */
    public function trackingNo(): ?string
    {
        foreach ($this->messages as $message) {
            if ($message['tracking_no'] !== '') {
                return $message['tracking_no'];
            }
        }

        return null;
    }

    /** One readable line per LBC message, or the transport error. */
    public function summary(): string
    {
        if ($this->messages === []) {
            return $this->error ?? 'LBC gave no message.';
        }

        return implode(' ', array_map(
            fn (array $m) => LbcPickupErrors::describe($m['code'], $m['message']),
            $this->messages
        ));
    }

    /** @return array{0:?bool,1:array}|null */
    private static function parseJson(string $body): ?array
    {
        $trimmed = ltrim($body);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return null;
        }

        $data = json_decode($trimmed, true);
        if (!is_array($data)) {
            return null;
        }

        $messages = [];
        foreach ((array) ($data['MessageList'] ?? []) as $m) {
            if (is_array($m)) {
                $messages[] = self::message($m['Code'] ?? '', $m['Message'] ?? '', $m['TrackingNo'] ?? '');
            }
        }

        return [self::bool($data['IsSuccess'] ?? null), $messages];
    }

    /** @return array{0:?bool,1:array}|null */
    private static function parseXml(string $body): ?array
    {
        if (trim($body) === '' || !str_contains($body, '<')) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: never fetch anything a response refers to. External
        // entities are not loaded (no LIBXML_NOENT).
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return null;
        }

        // Read by local name so a default namespace on the response does not hide the fields.
        $isSuccess = $xml->xpath('//*[local-name()="IsSuccess"]');
        $entries = $xml->xpath('//*[local-name()="ResponseMessageEntity"]') ?: [];

        if ($isSuccess === [] && $entries === []) {
            return null;
        }

        $messages = [];
        foreach ($entries as $entry) {
            $field = fn (string $name) => (string) (($entry->xpath('./*[local-name()="' . $name . '"]') ?: [''])[0]);
            $messages[] = self::message($field('Code'), $field('Message'), $field('TrackingNo'));
        }

        return [self::bool($isSuccess ? (string) $isSuccess[0] : null), $messages];
    }

    private static function message(mixed $code, mixed $message, mixed $trackingNo): array
    {
        return [
            'code' => trim((string) $code),
            'message' => trim((string) $message),
            'tracking_no' => trim((string) $trackingNo),
        ];
    }

    private static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower(trim((string) $value))) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }
}
