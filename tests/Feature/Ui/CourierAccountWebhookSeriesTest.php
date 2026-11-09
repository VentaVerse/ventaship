<?php

namespace Tests\Feature\Ui;

use App\Couriers\TrackingNumbers\TrackingNumberSeries;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
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

        return CourierAccount::factory()->create(['courier' => $courier, 'label' => 'Main', 'mode' => 'sandbox', 'webhook_token' => $token, 'webhook_token_hash' => hash('sha256', $token)]);
    }

    private function setBase(?string $url = self::BASE): void
    {
        config(['app.url' => $url ?? '']);
    }

    public function test_webhook_address_built_from_app_url(): void
    {
        $this->setBase();
        $q = $this->account('quadx');
        $s = $this->account('spx');

        $this->actingAs($this->admin())->get(route('accounts.show', $q->courier))->assertOk()
            ->assertSee('for="f-webhook-url"', false)->assertDontSee('Address for')
            ->assertSee('aria-label="Copy Webhook Address"', false)
            ->assertSee(self::BASE.'/webhooks/quadx?token=••••••••••••••••', false)
            ->assertDontSee('Public address');
        $this->get(route('accounts.show', $s->courier))->assertOk()
            ->assertSee('for="f-webhook-url"', false)->assertDontSee('Address for SPX')
            ->assertSee(self::BASE.'/webhooks/spx/••••••••••••••••', false);
    }

    public function test_no_webhook_section_without_app_url(): void
    {
        $this->setBase(null);
        $a = $this->account();

        $this->actingAs($this->admin())->get(route('accounts.show', $a->courier))->assertOk()
            ->assertDontSee('Webhook')->assertDontSee('Set address')->assertDontSee('token=');
        $this->postJson(route('accounts.reveal', [$a->courier, 'webhook_url']))->assertNotFound();
    }

    public function test_webhook_view_returns_full_url_and_audits(): void
    {
        $this->setBase();
        $a = $this->account('quadx');
        $s = $this->account('spx');
        $admin = $this->admin();

        $res = $this->actingAs($admin)->postJson(route('accounts.reveal', [$a->courier, 'webhook_url']))
            ->assertOk()->assertExactJson(['value' => self::BASE.'/webhooks/quadx?token='.$a->webhook_token]);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->postJson(route('accounts.reveal', [$s->courier, 'webhook_url']))
            ->assertExactJson(['value' => self::BASE.'/webhooks/spx/'.$s->webhook_token]);

        $entry = AuditEntry::query()->where('action', 'courier_account.webhook_viewed')->where('subject_id', $a->public_id)->sole();
        $this->assertSame([], $entry->changes);
        $this->assertSame((string) $admin->id, $entry->actor_id);
        $this->assertStringNotContainsString($a->webhook_token, json_encode(AuditEntry::query()->get()->toArray()));

        auth()->logout();
        $this->postJson(route('accounts.reveal', [$a->courier, 'webhook_url']))->assertUnauthorized();
    }

    public function test_webhook_reveal_throttled_and_absent_for_lbc(): void
    {
        $this->setBase();
        $l = $this->account('lbc');
        $this->actingAs($this->admin())->postJson(route('accounts.reveal', [$l->courier, 'webhook_url']))->assertNotFound();

        $a = $this->account('quadx');
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('accounts.reveal', [$a->courier, 'webhook_url']));
        }
        $this->postJson(route('accounts.reveal', [$a->courier, 'webhook_url']))->assertStatus(429);
    }

    public function test_page_never_contains_full_token(): void
    {
        $this->setBase();
        $a = $this->account('quadx');
        $s = $this->account('spx');

        foreach ([$a, $s] as $acct) {
            $html = $this->actingAs($this->admin())->get(route('accounts.show', $acct->courier))->assertOk()->getContent();
            $this->assertStringNotContainsString($acct->webhook_token, $html);
            $this->assertStringNotContainsString(substr($acct->webhook_token, 0, 8), $html);
        }
    }

    public function test_lbc_blocks_listed_and_added(): void
    {
        $l = $this->account('lbc');
        $this->actingAs($this->admin())->get(route('accounts.show', $l->courier))->assertOk()
            ->assertSee('No blocks')->assertSee('Add block');

        $this->post(route('accounts.series', $l->courier), ['first' => '1000', 'last' => '1099'])
            ->assertRedirect(route('accounts.show', $l->courier))->assertSessionHas('status', 'Added');
        $block = TrackingNumberSeries::query()->where('courier_account_id', $l->id)->sole();
        $block->update(['last_issued_number' => 1039]);

        $html = $this->get(route('accounts.show', $l->courier))->assertOk()->assertDontSee('No blocks')->getContent();
        $this->assertMatchesRegularExpression('/1000.*1099.*1040.*60/s', $html);
        $this->assertSame(1, AuditEntry::query()->where('action', 'tracking_series.added')->count());
    }

    public function test_series_validation_messages(): void
    {
        $l = $this->account('lbc');
        $this->actingAs($this->admin())->from(route('accounts.show', $l->courier))
            ->post(route('accounts.series', $l->courier), ['first' => 'abc', 'last' => '5'])
            ->assertRedirect(route('accounts.show', $l->courier))->assertSessionHasErrors(['first'])->assertSessionDoesntHaveErrors(['last']);

        $this->post(route('accounts.series', $l->courier), ['first' => '10', 'last' => '20']);
        $this->post(route('accounts.series', $l->courier), ['first' => '15', 'last' => '30'])->assertSessionHasErrors(['first'])->assertSessionDoesntHaveErrors(['last']);
        $this->post(route('accounts.series', $l->courier), [])->assertSessionHasErrors(['first', 'last']);

        $q = $this->account('quadx');
        $this->post(route('accounts.series', $q->courier), ['first' => '1', 'last' => '2'])->assertNotFound();
    }

    public function test_webhook_section_absent_for_lbc(): void
    {
        $this->setBase();
        $this->account('lbc');
        $this->account('quadx');
        $this->actingAs($this->admin())->get(route('accounts.show', 'lbc'))->assertOk()
            ->assertDontSee('Webhook')->assertDontSee('Public address');
        $this->get(route('accounts.show', 'quadx'))->assertOk()
            ->assertSee('Webhook')->assertDontSee('Add block')->assertDontSee('No blocks');
    }

    public function test_block_rows_count_released_numbers_and_match_status(): void
    {
        $l = $this->account('lbc');
        $svc = app(\App\Couriers\TrackingNumbers\TrackingNumberSeriesService::class);
        $svc->addSeries($l, 1000, 1009);
        $svc->addSeries($l, 2000, 2004);
        $issued = [];
        for ($i = 0; $i < 4; $i++) {
            $issued[] = $svc->reserve($l);
        }
        $svc->release($issued[1], 'Rejected: code 107');
        $svc->spend($issued[0], 'ok');

        $this->assertSame([
            ['first' => 1000, 'last' => 1009, 'next' => 1001, 'remaining' => 7],
            ['first' => 2000, 'last' => 2004, 'next' => 2000, 'remaining' => 5],
        ], $svc->blockRows($l));
        $status = $svc->status($l);
        $this->assertSame(12, $status['remaining']);
        $this->assertSame(1001, $status['next']);

        $html = $this->actingAs($this->admin())->get(route('accounts.show', $l->courier))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/1000.*1009.*1001.*7.*2000.*2004.*2000.*5/s', $html);
    }
}
