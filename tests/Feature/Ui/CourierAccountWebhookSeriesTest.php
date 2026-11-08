<?php

namespace Tests\Feature\Ui;

use App\Couriers\TrackingNumbers\TrackingNumberSeries;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierAccountWebhookSeriesTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://ship.example.ph';

    private ?Admin $admin = null;

    private function admin(): Admin
    {
        return $this->admin ??= Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
    }

    private function account(string $courier = 'quadx'): CourierAccount
    {
        $token = bin2hex(random_bytes(16));

        return CourierAccount::factory()->create(['courier' => $courier, 'label' => 'Main', 'mode' => 'staging', 'webhook_token' => $token, 'webhook_token_hash' => hash('sha256', $token)]);
    }

    private function setBase(?string $url = self::BASE): void
    {
        app(Settings::class)->set('webhooks.public_base_url', $url);
    }

    public function test_webhook_address_built_from_public_base(): void
    {
        $this->setBase();
        $q = $this->account('quadx');
        $s = $this->account('spx');

        $this->actingAs($this->admin())->get(route('accounts.show', $q))->assertOk()
            ->assertSee('Address for QuadX')
            ->assertSee(self::BASE.'/webhooks/quadx?token=••••••••••••••••', false)
            ->assertSee(self::BASE);
        $this->get(route('accounts.show', $s))->assertOk()
            ->assertSee('Address for SPX')
            ->assertSee(self::BASE.'/webhooks/spx/••••••••••••••••', false);
    }

    public function test_webhook_not_set_shows_badge_and_set_address_link(): void
    {
        $this->setBase(null);
        $a = $this->account();

        $this->actingAs($this->admin())->get(route('accounts.show', $a))->assertOk()
            ->assertSee('Not set')->assertSee('Set address')
            ->assertSee(route('settings.edit', ['tab' => 'couriers']), false)
            ->assertDontSee('/webhooks/quadx')
            ->assertDontSee('/webhooks/');
        $this->postJson(route('accounts.reveal', [$a, 'webhook_url']))->assertNotFound();
    }

    public function test_webhook_view_returns_full_url_and_audits(): void
    {
        $this->setBase();
        $a = $this->account('quadx');
        $s = $this->account('spx');
        $admin = $this->admin();

        $res = $this->actingAs($admin)->postJson(route('accounts.reveal', [$a, 'webhook_url']))
            ->assertOk()->assertExactJson(['value' => self::BASE.'/webhooks/quadx?token='.$a->webhook_token]);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->postJson(route('accounts.reveal', [$s, 'webhook_url']))
            ->assertExactJson(['value' => self::BASE.'/webhooks/spx/'.$s->webhook_token]);

        $entry = AuditEntry::query()->where('action', 'courier_account.webhook_viewed')->where('subject_id', $a->public_id)->sole();
        $this->assertSame([], $entry->changes);
        $this->assertSame((string) $admin->id, $entry->actor_id);
        $this->assertStringNotContainsString($a->webhook_token, json_encode(AuditEntry::query()->get()->toArray()));

        auth()->logout();
        $this->postJson(route('accounts.reveal', [$a, 'webhook_url']))->assertUnauthorized();
    }

    public function test_webhook_reveal_throttled_and_absent_for_lbc(): void
    {
        $this->setBase();
        $l = $this->account('lbc');
        $this->actingAs($this->admin())->postJson(route('accounts.reveal', [$l, 'webhook_url']))->assertNotFound();

        $a = $this->account('quadx');
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('accounts.reveal', [$a, 'webhook_url']));
        }
        $this->postJson(route('accounts.reveal', [$a, 'webhook_url']))->assertStatus(429);
    }

    public function test_page_never_contains_full_token(): void
    {
        $this->setBase();
        $a = $this->account('quadx');
        $s = $this->account('spx');

        foreach ([$a, $s] as $acct) {
            $html = $this->actingAs($this->admin())->get(route('accounts.show', $acct))->assertOk()->getContent();
            $this->assertStringNotContainsString($acct->webhook_token, $html);
            $this->assertStringNotContainsString(substr($acct->webhook_token, 0, 8), $html);
        }
    }

    public function test_lbc_blocks_listed_and_added(): void
    {
        $l = $this->account('lbc');
        $this->actingAs($this->admin())->get(route('accounts.show', $l))->assertOk()
            ->assertSee('No blocks')->assertSee('Add block');

        $this->post(route('accounts.series', $l), ['first' => '1000', 'last' => '1099'])
            ->assertRedirect(route('accounts.show', $l))->assertSessionHas('status', 'Added');
        $block = TrackingNumberSeries::query()->where('courier_account_id', $l->id)->sole();
        $block->update(['last_issued_number' => 1039]);

        $html = $this->get(route('accounts.show', $l))->assertOk()->assertDontSee('No blocks')->getContent();
        $this->assertMatchesRegularExpression('/1000.*1099.*1040.*60/s', $html);
        $this->assertSame(1, AuditEntry::query()->where('action', 'tracking_series.added')->count());
    }

    public function test_series_validation_messages(): void
    {
        $l = $this->account('lbc');
        $this->actingAs($this->admin())->from(route('accounts.show', $l))
            ->post(route('accounts.series', $l), ['first' => 'abc', 'last' => '5'])
            ->assertRedirect(route('accounts.show', $l))->assertSessionHasErrors(['first', 'last']);

        $this->post(route('accounts.series', $l), ['first' => '10', 'last' => '20']);
        $this->post(route('accounts.series', $l), ['first' => '15', 'last' => '30'])->assertSessionHasErrors(['first', 'last']);
        $this->post(route('accounts.series', $l), [])->assertSessionHasErrors(['first', 'last']);

        $q = $this->account('quadx');
        $this->post(route('accounts.series', $q), ['first' => '1', 'last' => '2'])->assertNotFound();
    }

    public function test_webhook_section_absent_for_lbc(): void
    {
        $this->setBase();
        $this->actingAs($this->admin())->get(route('accounts.show', $this->account('lbc')))->assertOk()
            ->assertDontSee('Webhook')->assertDontSee('Public address');
        $this->get(route('accounts.show', $this->account('quadx')))->assertOk()
            ->assertSee('Webhook')->assertDontSee('Add block')->assertDontSee('No blocks');
    }
}
