<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\AuditEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminCreate extends Command
{
    protected $signature = 'ventaship:admin {email} {--name= : Display name (defaults to the part of the email before the @)} {--username= : Sign-in username (letters, digits, . _ -; 3-64 characters)} {--reset : Set a new password for an existing admin}';

    protected $description = 'Create an admin, or with --reset set a new password for one (audited)';

    private const MIN_PASSWORD = 12;

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            $this->error('The email is not valid.');

            return self::FAILURE;
        }

        $username = $this->option('username') === null ? null : Str::lower(trim((string) $this->option('username')));
        if ($username !== null && preg_match('/^[a-z0-9._-]{3,64}\z/', $username) !== 1) {
            $this->error('The username must be 3-64 letters, digits, dots, dashes or underscores.');

            return self::FAILURE;
        }

        $existing = Admin::query()->where('email', $email)->first();

        if ($username !== null && Admin::query()->where('username', $username)->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists()) {
            $this->error("The username {$username} is already taken.");

            return self::FAILURE;
        }
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

        $admin = DB::transaction(function () use ($existing, $reset, $email, $password, $username) {
            if ($reset) {
                $existing->forceFill(['password' => $password, 'remember_token' => null] + ($username !== null ? ['username' => $username] : []))->save();
                $admin = $existing;
            } else {
                $name = trim((string) $this->option('name')) ?: Str::before($email, '@');
                $admin = Admin::query()->create(['name' => $name, 'username' => $username, 'email' => $email, 'password' => $password]);
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
