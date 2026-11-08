<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\AuditEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminCreate extends Command
{
    protected $signature = 'ventaship:admin {email} {--name= : Display name (defaults to the part of the email before the @)} {--reset : Set a new password for an existing admin}';

    protected $description = 'Create an admin, or with --reset set a new password for one (audited)';

    private const MIN_PASSWORD = 12;

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            $this->error('The email is not valid.');

            return self::FAILURE;
        }

        $existing = Admin::query()->where('email', $email)->first();
        $reset = (bool) $this->option('reset');

        if ($reset && ! $existing) {
            $this->error("No admin with the email {$email}.");

            return self::FAILURE;
        }
        if (! $reset && $existing) {
            $this->error("An admin with the email {$email} already exists. Use --reset to change the password.");

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');
        if (strlen($password) < self::MIN_PASSWORD) {
            $this->error('The password must be at least '.self::MIN_PASSWORD.' characters.');

            return self::FAILURE;
        }
        if ($password !== $confirmation) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $admin = DB::transaction(function () use ($existing, $reset, $email, $password) {
            if ($reset) {
                $existing->forceFill(['password' => $password, 'remember_token' => null])->save();
                $admin = $existing;
            } else {
                $name = trim((string) $this->option('name')) ?: Str::before($email, '@');
                $admin = Admin::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            }

            AuditEntry::query()->create([
                'actor_type' => 'system',
                'action' => $reset ? 'admin.password_reset' : 'admin.created',
                'subject_type' => 'admin',
                'subject_id' => (string) $admin->id,
                'changes' => ['email' => $email],
                'created_at' => now(),
            ]);

            return $admin;
        });

        $this->info(($reset ? 'Password changed for ' : 'Admin created: ').$admin->email);

        return self::SUCCESS;
    }
}
