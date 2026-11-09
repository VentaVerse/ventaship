<?php

namespace App\Couriers;

use App\Couriers\TrackingNumbers\TrackingNumberSeries;
use App\Couriers\TrackingNumbers\TrackingNumberSeriesException;
use App\Couriers\TrackingNumbers\TrackingNumberSeriesService;
use App\Fulfillment\Couriers;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Courier-account operations shared by the console commands and the admin screen. Each refusal is a
 * CourierAccountException with a short message. Audit rows name keys, never secret values.
 */
class CourierAccounts
{
    /**
     * The environments every courier account can be in (stored in courier_accounts.mode). Each adapter maps sandbox
     * to its courier's test system (QuadX staging, SPX UAT); credentials are stored per environment.
     */
    public const ENVIRONMENTS = ['sandbox', 'production'];

    /** The longest setting value accepted. Providers issue keys and tokens of very different shapes. */
    public const MAX_VALUE_LENGTH = 2048;

    /** @var array<string, array<int, array<string, mixed>>> settings schema per courier */
    private array $schemas = [];

    public function __construct(private readonly TrackingNumberSeriesService $series) {}

    /** @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor */
    public function create(string $courier, string $label, string $mode, bool $default, ?array $actor = null): CourierAccount
    {
        $label = trim($label);
        if (! CourierRegistry::has($courier)) {
            throw new CourierAccountException('Unknown courier. Available: '.implode(', ', CourierRegistry::keys()).'.');
        }
        if ($label === '' || mb_strlen($label) > 255) {
            throw new CourierAccountException('The label must be 1 to 255 characters.');
        }
        if (! in_array($mode, self::ENVIRONMENTS, true)) {
            throw new CourierAccountException('The environment must be sandbox or production.');
        }

        $token = Str::random(40);

        return DB::transaction(function () use ($courier, $label, $mode, $token, $default, $actor) {
            if ($default) {
                CourierAccount::withTrashed()
                    ->where('default_slot', $courier)
                    ->update(['default_slot' => null, 'is_default' => false]);
            }

            $account = CourierAccount::query()->create([
                'courier' => $courier,
                'label' => $label,
                'mode' => $mode,
                'status' => 'active',
                'is_default' => $default,
                'default_slot' => $default ? $courier : null,
                'webhook_token' => $token,
                'webhook_token_hash' => hash('sha256', $token),
            ]);

            $this->audit($actor, 'courier_account.created', $account, ['courier' => $courier, 'mode' => $mode]);

            return $account;
        });
    }

    /** The account the UI shows for a courier: the default, else the first active, else the first. Null when none. */
    public function forCourier(string $courier): ?CourierAccount
    {
        $all = CourierAccount::query()->where('courier', $courier)->orderBy('id')->get();

        return $all->firstWhere('is_default', true)
            ?? $all->first(fn (CourierAccount $a) => $a->isActive())
            ?? $all->first();
    }

    /**
     * The one account for a courier, named after it and default. Refuses when the courier already has one. $then
     * runs inside the same lock and transaction with the new account (the admin screen's first save).
     *
     * @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor
     */
    public function setUp(string $courier, string $mode, ?array $actor = null, ?callable $then = null): CourierAccount
    {
        if (! CourierRegistry::has($courier)) {
            throw new CourierAccountException('Unknown courier. Available: '.implode(', ', CourierRegistry::keys()).'.');
        }
        if (! in_array($mode, self::ENVIRONMENTS, true)) {
            throw new CourierAccountException('The environment must be sandbox or production.');
        }
        $lock = Cache::lock('courier-setup:'.$courier, 10);
        try {
            $lock->block(3);
        } catch (LockTimeoutException) {
            throw new CourierAccountException('Set up is already running. Try again.');
        }

        try {
            return DB::transaction(function () use ($courier, $mode, $actor, $then) {
                // Trashed accounts do not count, as in forCourier().
                if (CourierAccount::query()->where('courier', $courier)->lockForUpdate()->exists()) {
                    throw new CourierAccountException('This courier already has an account.');
                }

                $account = $this->create($courier, Couriers::name($courier), $mode, true, $actor);
                // The first save's keys and settings: anything thrown here rolls the new account back too.
                if ($then !== null) {
                    $then($account);
                }

                return $account;
            });
        } finally {
            $lock->release();
        }
    }

    /** Whether the key is a secret for this account's courier. */
    public function isSecret(CourierAccount $account, string $key): bool
    {
        return self::isSecretField($this->field($account, $key));
    }

    /** The one secret predicate: encrypted, or a secret/password field. @param  array<string, mixed>  $field */
    public static function isSecretField(array $field): bool
    {
        return ! empty($field['encrypted']) || in_array($field['type'] ?? null, ['secret', 'password'], true);
    }

    /**
     * The plain fields not tied to an environment: booking defaults, and fields marked `section: account` (LBC's
     * gateway and account details).
     *
     * @return array<int, array<string, mixed>>
     */
    public function plainFields(CourierAccount|string $account): array
    {
        return array_values(array_filter(
            $this->schema($account),
            fn (array $f) => ! self::isSecretField($f) && empty($f['mode_scoped']),
        ));
    }

    /**
     * One environment's credentials, secret and plain (SPX's App ID and User ID are plain).
     *
     * @return array<int, array<string, mixed>>
     */
    public function environmentFields(CourierAccount|string $account, string $environment): array
    {
        return array_values(array_filter($this->schema($account), fn (array $f) => ($f['mode_scoped'] ?? null) === $environment));
    }

    /** Clears a plain setting. @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor */
    public function clearSetting(CourierAccount $account, string $key, ?array $actor = null): void
    {
        if (self::isSecretField($this->field($account, $key))) {
            throw new CourierAccountException('Not a plain setting.');
        }

        DB::transaction(function () use ($account, $key, $actor) {
            AccountSettings::for($account)->write($key, null);
            if ($this->isCurrentSpxAppId($account, $key)) {
                $account->forceFill(['external_ref' => null])->save();
            }
            $this->attribute($actor, AuditEntry::query()
                ->where('action', 'courier_account.setting_changed')
                ->where('subject_id', $account->public_id)
                ->where('changes->key', $key)
                ->latest('id')->first());
        });
    }

    /** @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor */
    public function setSetting(CourierAccount $account, string $key, string $value, ?array $actor = null): void
    {
        $field = $this->field($account, $key);
        $value = trim($value);

        if ($value === '') {
            throw new CourierAccountException('A value is required.');
        }
        // JSON is stored compact, so its length is checked after it is reformatted.
        if (($field['type'] ?? null) === 'json') {
            $value = self::canonicalJson($value) ?? throw new CourierAccountException('Enter valid JSON.');
        }
        if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new CourierAccountException('The value is too long.');
        }
        $options = array_column($field['options'] ?? [], 'value');
        if ($options !== [] && ! in_array($value, $options, true)) {
            throw new CourierAccountException('The value must be one of: '.implode(', ', $options).'.');
        }

        DB::transaction(function () use ($account, $key, $value, $field, $actor) {
            $settings = AccountSettings::for($account);
            ! empty($field['encrypted']) ? $settings->writeSecret($key, $value) : $settings->write($key, $value);

            if ($this->isCurrentSpxAppId($account, $key)) {
                // SPX webhooks sent without a path token are routed by their app-id header to this account.
                $account->forceFill(['external_ref' => $value])->save();
            }

            // AccountSettings already wrote the audit row (key and a masked or from/to change, never a secret);
            // attribute it to the actor instead of adding a second row.
            $this->attribute($actor, AuditEntry::query()
                ->where('action', 'courier_account.setting_changed')
                ->where('subject_id', $account->public_id)
                ->where('changes->key', $key)
                ->latest('id')->first());
        });
    }

    /**
     * @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor
     * @return ?string the environment it was in, or null when it was already in $mode
     */
    public function setMode(CourierAccount $account, string $mode, ?array $actor = null): ?string
    {
        if (! CourierRegistry::has((string) $account->courier)) {
            throw new CourierAccountException('No such account.');
        }
        if (! in_array($mode, self::ENVIRONMENTS, true)) {
            throw new CourierAccountException('The environment must be sandbox or production.');
        }

        return DB::transaction(function () use ($account, $mode, $actor) {
            $row = CourierAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $from = (string) $row->mode;
            if ($from === $mode) {
                return null;
            }

            $changes = ['mode' => $mode];
            if ($row->courier === 'spx') {
                // Webhooks without a path token are routed by the app id of the environment the account is in.
                $changes['external_ref'] = AccountSettings::for($row)->read("spx_{$mode}_app_id");
            }
            $row->forceFill($changes)->save();
            $account->setRawAttributes($row->getAttributes(), true);

            $this->audit($actor, 'courier_account.mode_changed', $row, ['mode' => ['from' => $from, 'to' => $mode]]);

            return $from;
        });
    }

    /** @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor */
    public function rename(CourierAccount $account, string $label, ?array $actor = null): bool
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 255) {
            throw new CourierAccountException('The label must be 1 to 255 characters.');
        }
        if ($label === $account->label) {
            return false;
        }
        $from = $account->label;
        $account->forceFill(['label' => $label])->save();
        $this->audit($actor, 'courier_account.renamed', $account, ['label' => ['from' => $from, 'to' => $label]]);

        return true;
    }

    /** Makes the account its courier's default (the others lose it), or clears it. @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor */
    public function setDefault(CourierAccount $account, bool $default, ?array $actor = null): bool
    {
        return DB::transaction(function () use ($account, $default, $actor) {
            $row = CourierAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            if ((bool) $row->is_default === $default) {
                return false;
            }
            if ($default) {
                CourierAccount::withTrashed()
                    ->where('default_slot', $row->courier)
                    ->update(['default_slot' => null, 'is_default' => false]);
            }
            $row->forceFill(['is_default' => $default, 'default_slot' => $default ? $row->courier : null])->save();
            $account->setRawAttributes($row->getAttributes(), true);
            $this->audit($actor, 'courier_account.default_changed', $row, ['is_default' => $default]);

            return true;
        });
    }

    /** @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor */
    public function setActive(CourierAccount $account, bool $active, ?array $actor = null): bool
    {
        $status = $active ? 'active' : 'inactive';
        if ($account->status === $status) {
            return false;
        }
        $from = $account->status;

        return DB::transaction(function () use ($account, $status, $from, $actor) {
            $dropsDefault = $status === 'inactive' && ($account->is_default || $account->default_slot !== null);
            $account->forceFill(['status' => $status] + ($dropsDefault ? ['is_default' => false, 'default_slot' => null] : []))->save();
            $this->audit($actor, 'courier_account.status_changed', $account, ['status' => ['from' => $from, 'to' => $status]]);
            if ($dropsDefault) {
                // An inactive account must not hold the courier's default slot.
                $this->audit($actor, 'courier_account.default_changed', $account, ['is_default' => false]);
            }

            return true;
        });
    }

    /** The settings schema for this account's courier. @return array<int, array<string, mixed>> */
    public function schema(CourierAccount|string $courier): array
    {
        $courier = $courier instanceof CourierAccount ? (string) $courier->courier : $courier;
        if (! CourierRegistry::has($courier)) {
            throw new CourierAccountException('No such account.');
        }

        return $this->schemas[$courier] ??= CourierRegistry::describe($courier)->settingsSchema();
    }

    /**
     * A JSON setting's value in one canonical compact form, or null when it is not valid JSON. Objects stay objects
     * (`{}` is not turned into `[]`).
     */
    public static function canonicalJson(string $value): ?string
    {
        try {
            $decoded = json_decode($value, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: null;
    }

    /** @return array{ok: bool, message: string} A `transport` flag in the courier's result means no answer arrived. */
    public function test(CourierAccount $account): array
    {
        $name = Couriers::name((string) $account->courier);

        try {
            $result = Context::scope(
                fn () => CourierRegistry::for($account)->testConnection(),
                ['account_id' => $account->getKey(), 'operation' => 'test_connection'],
            );
            $ok = (bool) ($result['ok'] ?? false);
            $message = ! empty($result['transport'])
                ? "No answer from {$name}."
                : Str::limit((string) ($result['message'] ?? ''), 157);
        } catch (ConnectionException) {
            $ok = false;
            $message = "No answer from {$name}.";
        } catch (Throwable) {
            $ok = false;
            $message = "Could not check {$name}.";
        }

        $account->forceFill(['last_check_at' => now(), 'last_check_ok' => $ok])->save();

        return ['ok' => $ok, 'message' => $message];
    }

    /**
     * @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor
     * @return TrackingNumberSeries the stored block
     */
    public function addLbcSeries(CourierAccount $account, string $first, string $last, ?array $actor = null): TrackingNumberSeries
    {
        if ($account->courier !== 'lbc') {
            throw new CourierAccountException('No such LBC account.');
        }

        $first = trim($first);
        $last = trim($last);
        $digits = '/^[1-9]\d{0,'.(TrackingNumberSeriesService::MAX_DIGITS - 1).'}$/';
        if (! preg_match($digits, $first) || ! preg_match($digits, $last)) {
            throw new CourierAccountException('The first and last numbers must be whole numbers of at most '.TrackingNumberSeriesService::MAX_DIGITS.' digits.');
        }

        try {
            return DB::transaction(function () use ($account, $first, $last, $actor) {
                $block = $this->series->addSeries($account, (int) $first, (int) $last);

                $this->audit($actor, 'tracking_series.added', $account, ['first_number' => $first, 'last_number' => $last]);

                return $block;
            });
        } catch (TrackingNumberSeriesException $e) {
            throw new CourierAccountException($e->getMessage(), 0, $e);
        }
    }

    /** @param  array{type: string, id: mixed, ip: ?string}  $actor */
    public function revealSecret(CourierAccount $account, string $key, array $actor): string
    {
        if (! $this->isSecret($account, $key)) {
            throw new CourierAccountException('Not a secret setting.');
        }
        $value = AccountSettings::for($account)->secret($key);
        if ($value === null) {
            throw new CourierAccountException('Not set.');
        }

        $this->audit($actor, 'courier_account.secret_viewed', $account, ['key' => $key]);

        return $value;
    }

    /** Pseudo key for the webhook address, revealed through the same endpoint as the secrets. */
    public const WEBHOOK_KEY = 'webhook_url';

    /**
     * The address a courier posts status updates to: APP_URL, the webhook path and the account's token. Null for a
     * courier without a per-account webhook, or while APP_URL is empty. With $masked the token part is dots, so the
     * result is safe to render.
     */
    public function webhookAddress(CourierAccount $account, bool $masked = false): ?string
    {
        $base = trim((string) config('app.url'));
        $token = $account->webhook_token;
        if ($base === '' || ! in_array($account->courier, ['quadx', 'spx'], true) || ! is_string($token) || $token === '') {
            return null;
        }
        $token = $masked ? str_repeat('•', 16) : $token;
        $path = $account->courier === 'quadx'
            ? route('webhooks.quadx', absolute: false).'?token='.$token
            : str_replace('TOKEN', $token, route('webhooks.spx.token', 'TOKEN', absolute: false));

        return rtrim($base, '/').$path;
    }

    /** @param  array{type: string, id: mixed, ip: ?string}  $actor */
    public function revealWebhookAddress(CourierAccount $account, array $actor): string
    {
        $url = $this->webhookAddress($account);
        if ($url === null) {
            throw new CourierAccountException('Not set.');
        }
        $this->audit($actor, 'courier_account.webhook_viewed', $account, []);

        return $url;
    }

    /** @return ?array{length: int, last4: string} */
    public function masked(CourierAccount $account, string $key): ?array
    {
        $value = AccountSettings::for($account)->secret($key);
        if ($value === null) {
            return null;
        }

        return ['length' => mb_strlen($value), 'last4' => mb_substr($value, -4)];
    }

    private function isCurrentSpxAppId(CourierAccount $account, string $key): bool
    {
        return $account->courier === 'spx' && $key === "spx_{$account->mode}_app_id";
    }

    /** @return array<string, mixed> */
    private function field(CourierAccount $account, string $key): array
    {
        if (! CourierRegistry::has((string) $account->courier)) {
            throw new CourierAccountException('No such account.');
        }
        $this->schemas[$account->courier] ??= CourierRegistry::describe($account->courier)->settingsSchema();
        $field = collect($this->schemas[$account->courier])->firstWhere('key', $key);
        if ($field === null) {
            throw new CourierAccountException("Unknown setting for {$account->courier}.");
        }

        return $field;
    }

    /** @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor */
    private function attribute(?array $actor, ?AuditEntry $entry): void
    {
        if ($entry !== null && $actor !== null && ($actor['type'] ?? null) === 'admin') {
            $entry->forceFill(['actor_type' => 'admin', 'actor_id' => (string) $actor['id'], 'ip' => $actor['ip'] ?? null])->save();
        }
    }

    /**
     * @param  array{type?: string, id?: mixed, ip?: ?string}|null  $actor
     * @param  array<string, mixed>  $changes
     */
    private function audit(?array $actor, string $action, CourierAccount $account, array $changes): void
    {
        $admin = $actor !== null && ($actor['type'] ?? null) === 'admin';

        AuditEntry::query()->create([
            'actor_type' => $admin ? 'admin' : 'system',
            'actor_id' => $admin ? (string) $actor['id'] : null,
            'ip' => $admin ? ($actor['ip'] ?? null) : null,
            'action' => $action,
            'subject_type' => 'courier_account',
            'subject_id' => $account->public_id,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }
}
