<?php

use App\Addresses\PickupAddresses;
use App\Support\Vault\CredentialVault;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The sender default moves out of the settings (pickup.* keys) into a saved pickup address labelled "Main". It is
 * made the default unless a default already exists. Nothing is created when neither a contact name nor an address
 * was set. The pickup.* keys are deleted either way.
 */
return new class extends Migration
{
    private const FIELDS = ['contact_name', 'contact_phone', 'address_1', 'address_2', 'barangay', 'district', 'city', 'state', 'postcode'];

    public function up(): void
    {
        $stored = DB::table('settings')->where('key', 'like', 'pickup.%')->pluck('value', 'key')
            ->map(fn (?string $raw) => $raw === null ? null : json_decode($raw, true));
        $value = fn (string $key) => is_scalar($v = $stored["pickup.{$key}"] ?? null) ? trim((string) $v) : '';

        DB::transaction(function () use ($value) {
            if ($value('contact_name') !== '' || $value('address_1') !== '') {
                $data = [];
                foreach (self::FIELDS as $field) {
                    $data[$field] = $value($field);
                }
                foreach (['state', 'city', 'barangay'] as $level) {
                    $data['codes'][$level] = $value("codes.{$level}");
                }
                $address = PickupAddresses::address($data);

                DB::table('pickup_addresses')->insert([
                    'public_id' => 'pad_'.strtolower((string) Str::ulid()),
                    'label' => 'Main',
                    'is_default' => ! DB::table('pickup_addresses')->where('is_default', true)->exists(),
                    'address' => app(CredentialVault::class)->encrypt(json_encode($address, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('settings')->where('key', 'like', 'pickup.%')->delete();
        });
    }

    public function down(): void
    {
        // Not restored: the "Main" address stays and the settings sender is no longer read.
    }
};
