<?php

namespace Tests\Feature\Console;

use App\Models\Admin;
use App\Models\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_admin_with_hashed_password(): void
    {
        $this->artisan('ventaship:admin', ['email' => 'Ana@Example.com', '--name' => 'Ana'])
            ->expectsQuestion('Password', 'a-long-password-1')
            ->expectsQuestion('Confirm password', 'a-long-password-1')
            ->assertSuccessful();

        $admin = Admin::query()->sole();
        $this->assertSame('ana@example.com', $admin->email);
        $this->assertSame('Ana', $admin->name);
        $this->assertNotSame('a-long-password-1', $admin->password);
        $this->assertTrue(Hash::check('a-long-password-1', $admin->password));

        $audit = AuditEntry::query()->sole();
        $this->assertSame('admin.created', $audit->action);
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame('admin', $audit->subject_type);
        $this->assertSame((string) $admin->id, (string) $audit->subject_id);
        $this->assertStringNotContainsString('a-long-password-1', json_encode($audit->changes));
    }

    public function test_rejects_short_password(): void
    {
        $this->artisan('ventaship:admin', ['email' => 'ana@example.com'])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();

        $this->assertSame(0, Admin::query()->count());
    }

    public function test_rejects_mismatched_confirmation(): void
    {
        $this->artisan('ventaship:admin', ['email' => 'ana@example.com'])
            ->expectsQuestion('Password', 'a-long-password-1')
            ->expectsQuestion('Confirm password', 'a-long-password-2')
            ->assertFailed();

        $this->assertSame(0, Admin::query()->count());
    }

    public function test_rejects_existing_email_without_reset(): void
    {
        Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'old-password-123']);

        $this->artisan('ventaship:admin', ['email' => 'ana@example.com'])->assertFailed();

        $this->assertTrue(Hash::check('old-password-123', Admin::query()->sole()->password));
    }

    public function test_reset_changes_the_password(): void
    {
        Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'old-password-123']);

        $this->artisan('ventaship:admin', ['email' => 'ana@example.com', '--reset' => true])
            ->expectsQuestion('Password', 'new-password-1234')
            ->expectsQuestion('Confirm password', 'new-password-1234')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('new-password-1234', Admin::query()->sole()->password));
        $this->assertSame('admin.password_reset', AuditEntry::query()->sole()->action);
    }

    public function test_reset_of_unknown_email_fails(): void
    {
        $this->artisan('ventaship:admin', ['email' => 'nobody@example.com', '--reset' => true])->assertFailed();
    }

    public function test_sets_a_username(): void
    {
        $this->artisan('ventaship:admin', ['email' => 'ana@example.com', '--username' => ' Ana '])
            ->expectsQuestion('Password', 'a-long-password-1')
            ->expectsQuestion('Confirm password', 'a-long-password-1')
            ->assertSuccessful();

        $this->assertSame('ana', \App\Models\Admin::query()->value('username'));
    }

    public function test_rejects_bad_or_taken_username(): void
    {
        \App\Models\Admin::query()->create(['name' => 'B', 'username' => 'ben', 'email' => 'ben@example.com', 'password' => 'x']);

        $this->artisan('ventaship:admin', ['email' => 'ana@example.com', '--username' => 'a b'])->assertFailed();
        $this->artisan('ventaship:admin', ['email' => 'ana@example.com', '--username' => 'ben'])->assertFailed();
        $this->assertSame(1, \App\Models\Admin::query()->count());
    }
}
