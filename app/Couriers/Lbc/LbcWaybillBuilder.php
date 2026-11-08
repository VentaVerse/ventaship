<?php

namespace App\Couriers\Lbc;

/**
 * The Air Waybill label data (GeneratePOD, Table 1.0 of the Air Waybill
 * API), built from the same fields the pickup request sent. The endpoint
 * renders whatever it receives without checking it, so the label must be
 * made from the booked facts, never typed again.
 *
 * Where the two APIs differ, the waybill's own vocabulary is used: text
 * modes ("Air", "Door to Door") and NCR rather than MMA for Metro Manila.
 */
class LbcWaybillBuilder
{
    /** Pickup area code => waybill area code. Only Metro Manila is named differently. */
    public const AREA_CODES = ['MMA' => 'NCR'];

    /**
     * @param array<string,string> $pickup fields from LbcPickupRequestBuilder::fields()
     * @return array<string,string|int>
     */
    public function label(array $pickup, string $issuedBy, string $specialInstructions = ''): array
    {
        $pkg = (int) $pickup['PKG'];
        $cod = (float) $pickup['CODAmount'];

        return [
            'Barcode1D' => $pickup['TrackingNo'],
            'TrackingNo' => $pickup['TrackingNo'],
            'ConsigneeName' => $pickup['Consignee'],
            'ConsigneeAddress' => $this->address($pickup, 'Consignee'),
            'ShipperName' => $pickup['Shipper'],
            'ShipperAddress' => $this->address($pickup, 'Shipper'),
            'NoOfItems' => $pickup['Quantity'],
            'TransactionDate' => $pickup['TransactionDate'],
            'DeliveryRequirement' => $cod > 0 ? 'COD' : 'None',
            'SpecialInstructions' => mb_substr(trim($specialInstructions), 0, 250),
            'DRNo' => 'ORDER NO',
            'DRNo2' => $pickup['ReferenceNoOne'],
            'Origin' => self::AREA_CODES[$pickup['Origin']] ?? $pickup['Origin'],
            'DeclaredValue' => $pickup['DeclaredValue'],
            'Destination' => self::AREA_CODES[$pickup['DestinationCode']] ?? $pickup['DestinationCode'],
            'VolumeWt' => $pickup['VolWTcbm'],
            'LWH' => $pickup['LengthCM'] . 'x' . $pickup['WidthCM'] . 'x' . $pickup['HeightCM'],
            'ActualWt' => $pickup['ACTWTkgs'],
            'ChargeableWT' => $pickup['ChargeableWT'],
            'PayMode' => 'Collect Shipper',
            'ServiceMode' => 'Door to Door',
            'ShipmentMode' => 'Air',
            'Product' => LbcPackaging::productLine($pkg),
            'ProductName' => LbcPackaging::productName($pkg),
            'TotalFreightCharge' => '0',
            'Amount' => $pickup['CODAmount'],
            'Barcode2D' => $pickup['TrackingNo'],
            'CreatedBy' => mb_substr($issuedBy, 0, 150),
            'PrintedBy' => mb_substr($issuedBy, 0, 150),
        ];
    }

    private function address(array $pickup, string $who): string
    {
        return implode(', ', array_filter([
            $pickup[$who . 'StBldg'] ?? '',
            $pickup[$who . 'Brgy'] ?? '',
            $pickup[$who . 'CityMuncipality'] ?? '',
            $pickup[$who . 'Province'] ?? '',
        ], fn ($part) => trim((string) $part) !== ''));
    }
}
