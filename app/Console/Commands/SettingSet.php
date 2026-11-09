<?php

namespace App\Console\Commands;

use App\Models\AuditEntry;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

class SettingSet extends Command
{
    protected $signature = 'ventaship:setting-set {key} {value} {--json : Store the value decoded as JSON instead of as a string}';

    protected $description = 'Set one install setting (audited)';

    private const KEY_PATTERN = '/^[a-z0-9_]+(\.[a-z0-9_]+)*$/';

    /** audit_entries.subject_id holds the key: 64 characters. */
    private const KEY_MAX = 64;

    private const VALUE_MAX = 4096;

    public function handle(Settings $settings): int
    {
        $key = (string) $this->argument('key');
        $raw = (string) $this->argument('value');

        if (strlen($key) > self::KEY_MAX || preg_match(self::KEY_PATTERN, $key) !== 1) {
            $this->error('The key must be lowercase letters, digits and underscores, dot-separated (e.g. sender.name).');

            return self::FAILURE;
        }
        if (strlen($raw) > self::VALUE_MAX) {
            $this->error('The value is too long.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            try {
                $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->error('The value is not valid JSON.');

                return self::FAILURE;
            }
        } else {
            $value = $raw;
        }

        $from = DB::transaction(function () use ($settings, $key, $value) {
            $from = $settings->get($key);
            $settings->set($key, $value);

            AuditEntry::query()->create([
                'actor_type' => 'system',
                'action' => 'setting.changed',
                'subject_type' => 'setting',
                'subject_id' => $key,
                'changes' => ['value' => ['from' => $from, 'to' => $value]],
                'created_at' => now(),
            ]);

            return $from;
        });

        $this->info("{$key}: ".$this->show($from).' → '.$this->show($value));

        return self::SUCCESS;
    }

    private function show(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
