<?php

namespace App\Couriers\Lbc;

use App\Couriers\CourierOrder;
use Carbon\CarbonInterface;
use DOMDocument;
use App\Models\PickupAddress;
use App\Couriers\Support\PhPhone;

/**
 * Builds LBC's <PickupRequestEntity> from an order, a pickup address and a
 * parcel, following Table 1.0 of the Pickup Request API and the field order
 * of its sample payload.
 *
 * Fixed values, all from that table: ShipmentMode 1 (AIR), ProductLine 2
 * (CARGO), ServiceMode 8 (DOOR TO DOOR), Commodity 1, ForCrating 0. The
 * consignee SMS flag is 1, because LBC refuses 0 there (code 127) although
 * the table calls it optional; the shipper flag is 0. Attachment slot one
 * carries the order number; slot two carries the pickup mode (REGULAR or
 * DROPOFF); the unused slots are "0", as the sample does.
 *
 * Dates are Asia/Manila in LBC's MM/DD/YYYY HH:MM:SS form.
 */
class LbcPickupRequestBuilder
{
    public const TIMEZONE = 'Asia/Manila';

    public const DATE_FORMAT = 'm/d/Y H:i:s';

    /** A rider collects the parcel at the pickup address. */
    public const MODE_REGULAR = 'REGULAR';

    /** The store brings the parcel to an LBC branch; no rider is sent. */
    // The gateway accepts only DROPOFF, without a space, although the
    // published field table spells it with one (code 146 otherwise).
    public const MODE_DROP_OFF = 'DROPOFF';

    public const MODES = [self::MODE_REGULAR, self::MODE_DROP_OFF];

    /** Fields whose values are never printed: the account number and phone numbers. */
    public const MASKED_FIELDS = [
        'ShipperAccountNo',
        'ShipperContactNumber',
        'ShipperMobileNumber',
        'ConsigneeContactNumber',
        'ConsigneeMobileNumber',
    ];

    /**
     * The request fields in LBC's order.
     *
     * @return array<string, string>
     * @throws LbcException when a mandatory value is missing
     */
    public function fields(
        CourierOrder $order,
        PickupAddress $from,
        LbcParcel $parcel,
        LbcArea $origin,
        LbcArea $destination,
        LbcAccount $account,
        int $trackingNo,
        CarbonInterface $transactionAt,
        CarbonInterface $preferredAt,
        string $mode = self::MODE_REGULAR,
    ): array {
        if (!in_array($mode, self::MODES, true)) {
            throw new LbcException('The pickup mode must be REGULAR or DROPOFF.');
        }
        if ($origin->areaCode === null || $destination->areaCode === null) {
            throw new LbcException('The LBC area code of the pickup or delivery address is not known.');
        }

        $shipperPhone = self::phone($from->contact_phone)
            ?? throw new LbcException('The pickup address has no valid mobile number.');
        $consigneePhone = self::phone($order->shipping_phone ?: $order->phone)
            ?? throw new LbcException('The order has no valid mobile number for the receiver.');

        $shipper = self::text($from->contact_name, 150);
        $consignee = self::text(trim($order->shipping_first_name . ' ' . $order->shipping_last_name), 150);
        $shipperStreet = self::text($from->address_1, 250);
        $consigneeStreet = self::text($order->shipping_address_1, 250);
        $description = self::text($this->description($order), 250);

        foreach (['shipper name' => $shipper, 'receiver name' => $consignee, 'pickup street' => $shipperStreet,
            'delivery street' => $consigneeStreet, 'description' => $description] as $what => $value) {
            if ($value === '') {
                throw new LbcException("The {$what} is empty.");
            }
        }

        return [
            'ShipmentMode' => '1',
            'Origin' => $origin->areaCode,
            'TrackingNo' => (string) $trackingNo,
            'TransactionDate' => self::date($transactionAt),
            'ODZ' => $destination->serviceable === true ? 'false' : 'true',
            'ShipperAccountNo' => $account->shipperAccountNo,
            'Shipper' => $shipper,
            'ShipperStBldg' => $shipperStreet,
            'ShipperBrgy' => self::text($origin->barangayName(), 250),
            'ShipperCityMuncipality' => self::text($origin->cityName(), 250),
            'ShipperProvince' => self::text($origin->provinceName(), 250),
            'ShipperContactNumber' => $shipperPhone,
            'ShipperSendSMS' => '0',
            'ShipperMobileNumber' => $shipperPhone,
            'ProductLine' => '2',
            'ServiceMode' => '8',
            'CODAmount' => self::number($parcel->codAmount, 2),
            'PreferredDate' => self::date($preferredAt),
            'Consignee' => $consignee,
            'ConsigneeStBldg' => $consigneeStreet,
            'ConsigneeBrgy' => self::text($destination->barangayName(), 250),
            'ConsigneeCityMuncipality' => self::text($destination->cityName(), 250),
            'ConsigneeProvince' => self::text($destination->provinceName(), 250),
            'ConsigneeContactNumber' => $consigneePhone,
            'ConsigneeSendSMS' => '1',
            'ConsigneeMobileNumber' => $consigneePhone,
            'Quantity' => (string) $parcel->quantity,
            'PKG' => (string) $parcel->pkg,
            'ACTWTkgs' => self::number($parcel->weightKg, 2),
            'LengthCM' => self::number($parcel->lengthCm, 2),
            'WidthCM' => self::number($parcel->widthCm, 2),
            'HeightCM' => self::number($parcel->heightCm, 2),
            'VolWTcbm' => self::number($parcel->volumetricWeight(), 4),
            'CBM' => '0',
            'ChargeableWT' => self::number($parcel->chargeableWeight(), 2),
            'DeclaredValue' => self::number($parcel->declaredValue, 2),
            'Description' => $description,
            'Commodity' => '1',
            'ForCrating' => '0',
            'AttachmentNameOne' => 'ORDERNO',
            'ReferenceNoOne' => (string) $order->id,
            'AttachmentNameTwo' => 'MODE',
            'ReferenceNoTwo' => $mode,
            'AttachmentNameThree' => '0',
            'ReferenceNoThree' => '0',
            'AttachmentNameFour' => '0',
            'ReferenceNoFour' => '0',
            'DestinationCode' => $destination->areaCode,
            'Client' => $account->clientCode,
        ];
    }

    /** The XML document LBC expects. Values are escaped by DOM, never concatenated. */
    public function toXml(array $fields): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElement('PickupRequestEntity');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $doc->appendChild($root);

        foreach ($fields as $name => $value) {
            $element = $doc->createElement($name);
            $element->appendChild($doc->createTextNode((string) $value));
            $root->appendChild($element);
        }

        return $doc->saveXML();
    }

    /** The same fields with the account number and phone numbers hidden, for printing. */
    public static function mask(array $fields): array
    {
        foreach (self::MASKED_FIELDS as $name) {
            if (!isset($fields[$name])) {
                continue;
            }
            $fields[$name] = $name === 'ShipperAccountNo'
                ? str_repeat('*', 8)
                : self::maskPhone($fields[$name]);
        }

        return $fields;
    }

    public static function maskPhone(string $phone): string
    {
        $length = strlen($phone);

        return $length <= 6 ? str_repeat('*', $length) : substr($phone, 0, 4) . str_repeat('*', $length - 6) . substr($phone, -2);
    }

    /** A PH mobile in the 11-digit 09 form LBC's samples use. */
    public static function phone(?string $raw): ?string
    {
        $normalized = PhPhone::normalize($raw);
        if ($normalized === null) {
            return null;
        }

        return str_starts_with($normalized, '63') ? '0' . substr($normalized, 2) : $normalized;
    }

    public static function date(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone(self::TIMEZONE)->format(self::DATE_FORMAT);
    }

    /** A decimal without trailing zeros: 1.00 is "1", 0.857142 at 4 places is "0.8571". */
    public static function number(float $value, int $places): string
    {
        $text = number_format(round($value, $places), $places, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** The goods, as the order lists them: "2 x Name, 1 x Other". */
    private function description(CourierOrder $order): string
    {
        $order->loadMissing('products');

        return $order->products
            ->map(fn ($line) => ((int) $line->quantity) . ' x ' . trim((string) $line->name))
            ->implode(', ');
    }

    private static function text(?string $value, int $max): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return mb_substr($clean, 0, $max);
    }
}
