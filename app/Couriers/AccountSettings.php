<?php

namespace App\Couriers;

use App\Models\AuditEntry;
use App\Models\CourierAccount;
use App\Models\CourierAccountSetting;
use App\Support\Vault\CredentialVault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * One courier account's settings (courier_account_settings), loaded once. The keys are the ones each courier's
 * settingsSchema() declares (`quadx_staging_api_key`, `quadx_default_parcel`, ...). Secret values are stored
 * encrypted with CredentialVault and only ever leave this class decrypted through secret().
 */
final class AccountSettings
{
    private const SECRET_MASK_CHANGED = '•••• changed';

    private const SECRET_MASK_REMOVED = '•••• removed';

    /** @param  array<string, string|null>  $values */
    private function __construct(private readonly CourierAccount $account, private array $values) {}

    public static function for(CourierAccount $account): self
    {
        $values = $account->exists
            ? CourierAccountSetting::query()->where('courier_account_id', $account->getKey())->pluck('value', 'key')->all()
            : [];

        return new self($account, $values);
    }

    /** Settings for an account that has none (a schema-only courier, see CourierRegistry::describe()). */
    public static function empty(CourierAccount $account): self
    {
        return new self($account, []);
    }

    public function account(): CourierAccount
    {
        return $this->account;
    }

    /** The stored value as it is (a secret comes back still encrypted). Null and '' read as the default. */
    public function read(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /** The decrypted secret, or null when unset or unreadable (wrong vault key, tampered row). */
    public function secret(string $key): ?string
    {
        $cipher = $this->read($key);
        if ($cipher === null) {
            return null;
        }

        try {
            return app(CredentialVault::class)->decrypt($cipher);
        } catch (Throwable) {
            Log::warning('Courier account secret could not be decrypted', [
                'account' => $this->account->public_id,
                'key' => $key,
            ]);

            return null;
        }
    }

    public function write(string $key, ?string $value): void
    {
        $from = $this->read($key);
        $this->persist($key, $value, ['key' => $key, 'from' => $from, 'to' => $value]);
    }

    public function writeSecret(string $key, ?string $value): void
    {
        $stored = $value === null || $value === '' ? null : app(CredentialVault::class)->encrypt($value);
        $this->persist($key, $stored, [
            'key' => $key,
            'value' => $stored === null ? self::SECRET_MASK_REMOVED : self::SECRET_MASK_CHANGED,
        ]);
    }

    /** @param  array<string, mixed>  $changes */
    private function persist(string $key, ?string $value, array $changes): void
    {
        if (! $this->account->exists) {
            throw new LogicException('Settings can only be written for a saved courier account.');
        }

        DB::transaction(function () use ($key, $value, $changes) {
            $match = ['courier_account_id' => $this->account->getKey(), 'key' => $key];

            if ($value === null || $value === '') {
                CourierAccountSetting::query()->where($match)->delete();
            } else {
                CourierAccountSetting::query()->updateOrCreate($match, ['value' => $value]);
            }

            AuditEntry::query()->create([
                'actor_type' => 'system',
                'action' => 'courier_account.setting_changed',
                'subject_type' => 'courier_account',
                'subject_id' => $this->account->public_id,
                'changes' => $changes,
                'created_at' => now(),
            ]);
        });

        $this->values[$key] = $value === '' ? null : $value;
    }
}
