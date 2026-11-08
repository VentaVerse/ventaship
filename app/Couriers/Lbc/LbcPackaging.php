<?php

namespace App\Couriers\Lbc;

/**
 * LBC's packaging products by PKG code, from Table 2.0 "PKG or PRODUCT ID
 * LIST" of the Pickup Request API. Every row there is ProductLine 2; the
 * CARGO / DOCUMENTS split is what the Air Waybill prints as the product, so
 * it is kept beside the product name LBC prints.
 *
 * LBC publishes no standard dimensions for its own packaging, so none are
 * kept here: the booking form leaves dimensions for the packer to measure,
 * and requires them for BOX (see LbcParcel).
 */
final class LbcPackaging
{
    /** The PKG code for a parcel in the store's own box. */
    public const OWN_BOX = 16;

    /**
     * PKG => [product line, LBC's product name, the name the packer reads],
     * in the order the booking form lists them: the store's own box first,
     * then LBC's boxes small to large, then pouches and packs, then the rest.
     *
     * @var array<int, array{0:string,1:string,2:string}>
     */
    public const PRODUCTS = [
        16 => ['CARGO', 'BOX', 'Own box'],
        165 => ['CARGO', 'KILOBOX MINI', 'Kilobox Mini'],
        6 => ['CARGO', 'KILOBOX-S', 'Kilobox Small'],
        164 => ['CARGO', 'KILOBOX SLIM', 'Kilobox Slim'],
        63 => ['CARGO', 'KILOBOX-M', 'Kilobox Medium'],
        7 => ['CARGO', 'KILOBOX-L', 'Kilobox Large'],
        64 => ['CARGO', 'KILOBOX-XL', 'Kilobox XL'],
        45 => ['DOCUMENTS', 'N-PACK SMALL', 'N-Pack Small'],
        8 => ['DOCUMENTS', 'N-PACK LARGE', 'N-Pack Large'],
        62 => ['DOCUMENTS', 'N-POUCH SS (NPSS)', 'N-Pouch SS'],
        3 => ['DOCUMENTS', 'N-POUCH REGULAR (NPR)', 'N-Pouch Regular'],
        61 => ['DOCUMENTS', 'N-POUCH XL (NPXL)', 'N-Pouch XL'],
        9 => ['DOCUMENTS', 'X POUCH', 'X-Pouch'],
        2 => ['CARGO', 'PLASTIC', 'Plastic'],
        4 => ['CARGO', 'CRATE', 'Crate'],
        79 => ['CARGO', 'PIECE', 'Piece'],
        80 => ['CARGO', 'SACK', 'Sack'],
    ];

    public static function exists(int $pkg): bool
    {
        return array_key_exists($pkg, self::PRODUCTS);
    }

    public static function productLine(int $pkg): string
    {
        return self::PRODUCTS[$pkg][0] ?? 'CARGO';
    }

    public static function productName(int $pkg): string
    {
        return self::PRODUCTS[$pkg][1] ?? (string) $pkg;
    }

    public static function label(int $pkg): string
    {
        return self::PRODUCTS[$pkg][2] ?? (string) $pkg;
    }

    /**
     * The booking form's list. The value is the PKG code that goes into the
     * request verbatim.
     *
     * @return array<int, array{value:string, label:string}>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::PRODUCTS as $pkg => [, , $label]) {
            $options[] = ['value' => (string) $pkg, 'label' => $label];
        }

        return $options;
    }
}
