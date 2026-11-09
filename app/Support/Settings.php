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

    /** @return array<string, string|null> */
    private function all(): array
    {
        return $this->cache ??= Setting::query()->pluck('value', 'key')->all();
    }
}
