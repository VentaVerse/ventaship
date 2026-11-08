<?php

namespace App\Couriers\Lbc;

/**
 * Pickup Request response codes, readable. Codes 101 to 152 name the field
 * LBC refused (Table 4.0 "Pickup Request Nodes & Response Codes"); 100 and
 * 1000 to 1007 are the API response codes of Table 3.0.
 */
final class LbcPickupErrors
{
    public const SUCCESS = '100';

    /** Code => the request field it names. 116 and 129 are not assigned. */
    public const FIELDS = [
        '101' => 'Shipment mode',
        '102' => 'Tracking number',
        '103' => 'Origin',
        '104' => 'Destination',
        '105' => 'Transaction date',
        '106' => 'ODZ (out of delivery zone)',
        '107' => 'Shipper account number',
        '108' => 'Shipper',
        '109' => 'Shipper street or building',
        '110' => 'Shipper barangay',
        '111' => 'Shipper city or municipality',
        '112' => 'Shipper province',
        '113' => 'Shipper contact number',
        '114' => 'Shipper send SMS',
        '115' => 'Shipper mobile number',
        '117' => 'Product line',
        '118' => 'Service mode',
        '119' => 'COD amount',
        '120' => 'Consignee account number',
        '121' => 'Consignee',
        '122' => 'Consignee street or building',
        '123' => 'Consignee barangay',
        '124' => 'Consignee city or municipality',
        '125' => 'Consignee province',
        '126' => 'Consignee contact number',
        '127' => 'Consignee send SMS',
        '128' => 'Consignee mobile number',
        '130' => 'Quantity',
        '131' => 'PKG (packaging)',
        '132' => 'Actual weight (kg)',
        '133' => 'Length (cm)',
        '134' => 'Width (cm)',
        '135' => 'Height (cm)',
        '136' => 'Volumetric weight (L x W x H / 3500)',
        '137' => 'CBM (L x W x H / 1,000,000)',
        '138' => 'Chargeable weight',
        '139' => 'Declared value',
        '140' => 'Description',
        '141' => 'Commodity',
        '142' => 'For crating',
        '143' => 'Attachment name 1',
        '144' => 'Reference number 1',
        '145' => 'Attachment name 2',
        '146' => 'Reference number 2',
        '147' => 'Attachment name 3',
        '148' => 'Reference number 3',
        '149' => 'Attachment name 4',
        '150' => 'Reference number 4',
        '151' => 'Destination code',
        '152' => 'Client',
    ];

    /** Table 3.0 codes other than success. */
    public const API = [
        '1000' => 'Invalid key',
        '1001' => 'Passed data is the NULL value',
        '1002' => 'Data passing error',
        '1003' => 'Data passing error',
        '1004' => 'Data value exception',
        '1005' => 'Data insert exception',
        '1006' => 'Data insert exception',
        '1007' => 'Duplicate booking request',
    ];

    /** True for the field validation codes, which mean LBC saved nothing. */
    public static function isValidationCode(string $code): bool
    {
        return array_key_exists($code, self::FIELDS);
    }

    public static function describe(string $code, ?string $lbcMessage = null): string
    {
        $message = trim((string) $lbcMessage);

        if ($code === self::SUCCESS) {
            $text = 'Saved by LBC';
        } elseif (isset(self::FIELDS[$code])) {
            $text = self::FIELDS[$code] . ' is missing or not valid';
        } elseif (isset(self::API[$code])) {
            $text = self::API[$code];
        } else {
            $text = 'Unknown LBC response code';
        }

        return "{$text} (code {$code})" . ($message !== '' ? ": {$message}" : '.');
    }
}
