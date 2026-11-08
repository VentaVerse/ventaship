<?php

namespace App\Couriers\Lbc;

/**
 * One address as LBC sees it: its PSGC code and LBC's names for it, whether
 * LBC serves it, and its area code with where that code came from.
 */
final class LbcArea
{
    /** The area code came from LBC's serviceability answer. */
    public const FROM_SERVICEABILITY = 'serviceability';

    /** The area code came from the region fallback. */
    public const FROM_REGION = 'region';

    public function __construct(
        public readonly string $inputBarangay = '',
        public readonly string $inputCity = '',
        public readonly string $inputProvince = '',
        public readonly ?string $psgcCode = null,
        public readonly ?string $barangay = null,
        public readonly ?string $city = null,
        public readonly ?string $province = null,
        public readonly ?string $fullLocationName = null,
        /** null until LBC has answered. */
        public readonly ?bool $serviceable = null,
        public readonly ?string $status = null,
        public readonly ?string $category = null,
        public readonly ?float $rate = null,
        public readonly ?string $areaCode = null,
        public readonly ?string $areaSource = null,
        /** @var string[] */
        public readonly array $problems = [],
    ) {
    }

    public function withProblem(string $problem): self
    {
        return $this->copy(['problems' => [...$this->problems, $problem]]);
    }

    public function withLocation(string $psgcCode, string $barangay, string $city, string $province, string $fullLocationName): self
    {
        return $this->copy(compact('psgcCode', 'barangay', 'city', 'province', 'fullLocationName'));
    }

    public function withServiceability(bool $serviceable, string $status, ?string $category, ?float $rate): self
    {
        return $this->copy(compact('serviceable', 'status', 'category', 'rate'));
    }

    public function withAreaCode(string $areaCode, string $areaSource): self
    {
        return $this->copy(compact('areaCode', 'areaSource'));
    }

    /** LBC's barangay name when it resolved one, ours otherwise. */
    public function barangayName(): string
    {
        return $this->barangay ?? $this->inputBarangay;
    }

    public function cityName(): string
    {
        return $this->city ?? $this->inputCity;
    }

    public function provinceName(): string
    {
        return $this->province ?: $this->inputProvince;
    }

    private function copy(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
