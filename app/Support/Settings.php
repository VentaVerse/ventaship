<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Key/value settings backed by the settings table, cached for the request.
 * Every value is json-encoded on write and decoded on read, so types round-trip.
 */
class Settings
{
    /** @var array<string, string|null>|null */
    private ?array $cache = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->all()[$key] ?? null;
        if ($raw === null) {
            return $default;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    public function set(string $key, mixed $value): void
    {
        $stored = json_encode($value, JSON_THROW_ON_ERROR);

        Setting::updateOrCreate(['key' => $key], ['value' => $stored]);

        $this->all();
        $this->cache[$key] = $stored;
    }

    /** The public https address webhooks reach this install on, or null until it is set. */
    public function publicBaseUrl(): ?string
    {
        $value = $this->get('webhooks.public_base_url');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The default sender in the API's `pickup` input shape, or null until its required parts are all set. */
    public function pickup(): ?array
    {
        $pickup = [];
        foreach (['contact_name', 'contact_phone', 'address_1', 'address_2', 'barangay', 'district', 'city', 'state', 'postcode'] as $field) {
            $value = $this->get("pickup.{$field}");
            $pickup[$field] = is_string($value) && $value !== '' ? $value : null;
        }
        if (in_array(null, [$pickup['contact_name'], $pickup['contact_phone'], $pickup['address_1'], $pickup['city'], $pickup['state']], true)) {
            return null;
        }

        $codes = [];
        foreach (['state', 'city', 'barangay'] as $level) {
            $value = $this->get("pickup.codes.{$level}");
            if (is_string($value) && $value !== '') {
                $codes[$level] = $value;
            }
        }

        return $pickup + ['codes' => $codes, 'country' => 'PH'];
    }

    /** @return array<string, string|null> */
    private function all(): array
    {
        return $this->cache ??= Setting::query()->pluck('value', 'key')->all();
    }
}
