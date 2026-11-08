<?php

namespace Tests\Feature\Platforms;

use App\Models\ApiToken;
use App\Models\AuditEntry;
use App\Models\ClientApp;
use App\Models\OutgoingEvent;
use App\Platforms\KeyNotViewableException;
use App\Platforms\PlatformAppException;
use App\Platforms\PlatformApps;
use App\Webhooks\Outbound\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PlatformAppsServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = ['type' => 'admin', 'id' => 7, 'ip' => '10.0.0.9'];

    private PlatformApps $apps;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->apps = app(PlatformApps::class);
    }

    private function bearerGet(string $uri, string $token): TestResponse
    {
        return $this->call('GET', $uri, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    /** Every audit row's columns as one string, for leak checks. */
    private function auditDump(): string
    {
        return json_encode(DB::table('audit_entries')->get()->all(), JSON_UNESCAPED_SLASHES);
    }

    public function test_create_stores_encrypted_webhook_secret_and_audits(): void
    {
        $app = $this->apps->create('Seller Center', 'https://shop.test/hook', self::ADMIN);

        $this->assertSame('active', $app->status);
        $this->assertSame('https://shop.test/hook', $app->webhook_url);
        $this->assertStringStartsWith('whsec_', $app->webhook_secret);
        $this->assertNotSame($app->webhook_secret, $app->getRawOriginal('webhook_secret'));

        $row = AuditEntry::query()->sole();
        $this->assertSame('client_app.created', $row->action);
        $this->assertSame('admin', $row->actor_type);
        $this->assertSame('7', $row->actor_id);
        $this->assertSame('10.0.0.9', $row->ip);
        $this->assertSame('client_app', $row->subject_type);
        $this->assertSame($app->public_id, $row->subject_id);
        $this->assertStringNotContainsString($app->webhook_secret, $this->auditDump());
    }

    public function test_create_refuses_bad_name_or_url(): void
    {
        foreach ([['', null], ['Shop', 'ftp://x.test/hook'], ['Shop', 'not a url']] as [$name, $url]) {
            try {
                $this->apps->create($name, $url, null);
                $this->fail('expected a refusal');
            } catch (PlatformAppException) {
            }
        }
        $this->assertSame(0, ClientApp::count());
        $this->assertSame(0, AuditEntry::count());
    }

    public function test_update_changes_fields_and_audits_without_url(): void
    {
        $app = $this->apps->create('Shop', null, null);

        $this->apps->update($app, [
            'name' => 'Shop PH',
            'webhook_url' => 'http://shop.test/hook?token=abc123',
            'status' => 'disabled',
            'webhook_events' => ['shipment.booked'],
        ], self::ADMIN);

        $app->refresh();
        $this->assertSame('Shop PH', $app->name);
        $this->assertSame('http://shop.test/hook?token=abc123', $app->webhook_url);
        $this->assertSame('disabled', $app->status);
        $this->assertSame(['shipment.booked'], $app->webhook_events);

        $row = AuditEntry::query()->where('action', 'client_app.updated')->sole();
        $this->assertSame('admin', $row->actor_type);
        $this->assertSame(['from' => 'active', 'to' => 'disabled'], $row->changes['status']);
        $this->assertStringNotContainsString('abc123', $this->auditDump());

        $this->apps->update($app, ['webhook_url' => '', 'webhook_events' => []], null);
        $app->refresh();
        $this->assertNull($app->webhook_url);
        $this->assertNull($app->webhook_events);

        foreach ([['status' => 'paused'], ['webhook_url' => 'ftp://x.test'], ['name' => ' '], ['webhook_events' => ['nope']], ['webhook_events' => [['shipment.booked']]], ['webhook_events' => [7]], ['webhook_events' => 'shipment.booked']] as $bad) {
            try {
                $this->apps->update($app, $bad, null);
                $this->fail('expected a refusal for '.json_encode($bad));
            } catch (PlatformAppException) {
            }
        }
    }

    public function test_issued_and_revealed_key_authenticates_against_the_api(): void
    {
        $app = $this->apps->create('Shop', null, null);
        $issued = $this->apps->issueKey($app, 'Prod', self::ADMIN);

        $key = $issued['key'];
        $this->assertSame($key->id.'|', substr($issued['plaintext_key'], 0, strlen($key->id.'|')));
        [, $secret] = explode('|', $issued['plaintext_key'], 2);
        $this->assertMatchesRegularExpression('/^vsk_[A-Za-z0-9]{48}$/', $secret);
        $this->assertTrue($key->tokenable->is($app));
        $this->assertSame(hash('sha256', $secret), $key->token);
        $this->assertSame(substr($secret, 0, 8), $key->prefix);
        $raw = json_encode($key->fresh()->getRawOriginal());
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertArrayNotHasKey('token_encrypted', $key->fresh()->toArray());
        $this->assertArrayNotHasKey('token', $key->fresh()->toArray());

        $revealed = $this->apps->revealKey($key->fresh(), self::ADMIN);
        $this->assertSame($issued['plaintext_key'], $revealed);

        $this->bearerGet('/api/v1/ping', $revealed)->assertOk();

        $issuedRow = AuditEntry::query()->where('action', 'api_key.issued')->sole();
        $this->assertSame($key->prefix, $issuedRow->changes['key_prefix']);
        $viewed = AuditEntry::query()->where('action', 'api_key.viewed')->sole();
        $this->assertSame(['key_prefix' => $key->prefix], $viewed->changes);
        $this->assertSame('admin', $viewed->actor_type);
        $this->assertSame($app->public_id, $viewed->subject_id);

        $this->assertTrue($this->apps->revokeKey($key, self::ADMIN));
        $this->assertFalse($this->apps->revokeKey($key, self::ADMIN));
        $this->assertSame(1, AuditEntry::query()->where('action', 'api_key.revoked')->count());
        $this->assertNull(ApiToken::query()->find($key->id));

        try {
            $this->apps->revealKey($key, self::ADMIN);
            $this->fail('a revoked key was revealed');
        } catch (PlatformAppException) {
        }
        $this->bearerGet('/api/v1/ping', $revealed)->assertUnauthorized();

        $dump = $this->auditDump();
        $this->assertStringNotContainsString($secret, $dump);
        $this->assertSame(1, AuditEntry::query()->where('action', 'api_key.viewed')->count());
    }

    public function test_key_moved_without_an_encrypted_copy_is_not_viewable(): void
    {
        $app = $this->apps->create('Shop', null, null);
        $plain = 'vsk_'.Str::random(40);
        $old = $app->tokens()->create([
            'name' => 'Old', 'prefix' => substr($plain, 0, 8), 'token' => hash('sha256', $plain), 'abilities' => ['*'],
        ]);

        try {
            $this->apps->revealKey($old->fresh(), self::ADMIN);
            $this->fail('expected a refusal');
        } catch (KeyNotViewableException $e) {
            $this->assertInstanceOf(PlatformAppException::class, $e);
        }
        $this->bearerGet('/api/v1/ping', $plain)->assertOk();
    }

    public function test_moved_key_with_an_encrypted_copy_reveals_the_bare_key(): void
    {
        $app = $this->apps->create('Shop', null, null);
        $plain = 'vsk_'.Str::random(40);
        $old = $app->tokens()->create([
            'name' => 'Old', 'prefix' => substr($plain, 0, 8), 'token' => hash('sha256', $plain), 'abilities' => ['*'],
            'token_encrypted' => $plain,
        ]);

        $this->assertSame($plain, $this->apps->revealKey($old->fresh(), self::ADMIN));
    }

    public function test_reveal_refuses_an_undecryptable_or_mismatched_key_cleanly(): void
    {
        $app = $this->apps->create('Shop', null, null);
        $key = $this->apps->issueKey($app, 'Prod', null)['key'];
        $other = $this->apps->issueKey($app, 'Other', null);

        // Another token's value stored against this one: refused, never served.
        DB::table('personal_access_tokens')->where('id', $key->id)
            ->update(['token_encrypted' => DB::table('personal_access_tokens')->where('id', $other['key']->id)->value('token_encrypted')]);
        try {
            $this->apps->revealKey($key->fresh(), self::ADMIN);
            $this->fail('a mismatched key was revealed');
        } catch (PlatformAppException) {
        }

        DB::table('personal_access_tokens')->where('id', $key->id)->update(['token_encrypted' => 'garbage']);
        $this->expectException(PlatformAppException::class);
        $this->apps->revealKey($key->fresh(), self::ADMIN);
    }

    public function test_issue_key_refuses_blank_name(): void
    {
        $app = $this->apps->create('Shop', null, null);
        $this->expectException(PlatformAppException::class);
        $this->apps->issueKey($app, '  ', null);
    }

    public function test_webhook_secret_reveal_and_regenerate(): void
    {
        $app = $this->apps->create('Shop', 'https://shop.test/hook', null);
        $first = $app->webhook_secret;

        $this->assertSame($first, $this->apps->revealWebhookSecret($app, self::ADMIN));
        $this->apps->regenerateWebhookSecret($app, self::ADMIN);
        $second = $app->fresh()->webhook_secret;
        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith('whsec_', $second);

        $this->assertSame(1, AuditEntry::query()->where('action', 'client_app.webhook_secret_viewed')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'client_app.webhook_secret_regenerated')->count());
        $dump = $this->auditDump();
        $this->assertStringNotContainsString($first, $dump);
        $this->assertStringNotContainsString($second, $dump);

        // The next delivery is signed with the new secret.
        Http::fake(['shop.test/*' => Http::response('ok', 200)]);
        $this->assertTrue($this->apps->ping($app->fresh())['ok']);
        Http::assertSent(function (Request $r) use ($second) {
            $ts = $r->header('X-Timestamp')[0];

            return $r->header('X-Signature')[0] === 'sha256='.hash_hmac('sha256', $ts.'.'.$r->body(), $second);
        });
    }

    public function test_ping_delivers_synchronously(): void
    {
        Http::fake(['shop.test/*' => Http::response('ok', 200)]);
        $app = $this->apps->create('Shop', 'https://shop.test/hook', null);

        $r = $this->apps->ping($app);

        $this->assertSame(['ok' => true, 'status' => 200], ['ok' => $r['ok'], 'status' => $r['status']]);
        $this->assertNotSame('', $r['message']);
        $this->assertSame('delivered', OutgoingEvent::query()->sole()->status);
        Http::assertSentCount(1);
    }

    public function test_ping_reports_http_failure(): void
    {
        Http::fake(['shop.test/*' => Http::response('nope', 503)]);
        $app = $this->apps->create('Shop', 'https://shop.test/hook', null);

        $this->assertSame(['ok' => false, 'status' => 503, 'message' => 'Failed · HTTP 503'], $this->apps->ping($app));
    }

    public function test_ping_reports_no_answer(): void
    {
        Http::fake(['shop.test/*' => fn () => throw new ConnectionException('Connection refused for https://shop.test/hook?token=zzz')]);
        $app = $this->apps->create('Shop', 'https://shop.test/hook', null);

        $this->assertSame(['ok' => false, 'status' => null, 'message' => 'No answer from the platform.'], $this->apps->ping($app));

        $e = OutgoingEvent::query()->sole();
        $e->update(['status' => 'failed', 'next_attempt_at' => null]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertSame(['ok' => false, 'message' => 'No answer from the platform.'], $this->apps->resend($e->fresh(), null));

        $errors = DB::table('outgoing_attempts')->pluck('error')->implode('|').'|'.DB::table('outgoing_events')->pluck('last_error')->implode('|');
        $this->assertStringContainsString('shop.test/hook', $errors);
        $this->assertStringNotContainsString('zzz', $errors);
    }

    public function test_resend_held_behind_an_earlier_event_is_not_ok(): void
    {
        Http::fake(['shop.test/*' => Http::response('ok', 200)]);
        $app = $this->apps->create('Shop', 'https://shop.test/hook', null);
        $s = \App\Models\Shipment::factory()->create(['client_app_id' => $app->id]);
        $first = DB::transaction(fn () => Outbox::record($app, 'shipment.booked', ['event' => null], $s->fresh()));
        $second = DB::transaction(fn () => Outbox::record($app, 'shipment.status_changed', ['event' => null], $s->fresh()));
        $first->update(['attempts' => 1, 'next_attempt_at' => now()->addMinute()]);
        $second->update(['status' => 'failed', 'next_attempt_at' => null]);

        $this->assertSame(['ok' => false, 'message' => 'Waiting for an earlier event.'], $this->apps->resend($second->fresh(), null));
        Http::assertNothingSent();
    }

    public function test_reveal_rechecks_revocation_against_the_database(): void
    {
        $app = $this->apps->create('Shop', null, null);
        $key = $this->apps->issueKey($app, 'Prod', null)['key'];
        $stale = $key->fresh();
        DB::table('personal_access_tokens')->where('id', $key->id)->delete();

        $this->expectException(PlatformAppException::class);
        $this->apps->revealKey($stale, self::ADMIN);
    }

    public function test_ping_without_url_or_disabled_sends_nothing_and_never_throws(): void
    {
        Http::fake();
        $app = $this->apps->create('Shop', null, null);
        $r = $this->apps->ping($app);
        $this->assertFalse($r['ok']);
        $this->assertNull($r['status']);

        $app->update(['webhook_url' => 'https://shop.test/hook', 'status' => 'disabled']);
        $this->assertFalse($this->apps->ping($app->fresh())['ok']);

        Http::assertNothingSent();
        $this->assertSame(0, OutgoingEvent::count());
    }

    public function test_ping_never_throws_on_unexpected_errors(): void
    {
        Log::spy();
        $app = $this->apps->create('Shop', 'https://shop.test/hook', null);
        DB::table('client_apps')->where('id', $app->id)->update(['webhook_secret' => 'not-a-ciphertext']);

        $r = $this->apps->ping($app->fresh());

        $this->assertFalse($r['ok']);
        $this->assertNull($r['status']);
        $this->assertSame('The ping could not be sent.', $r['message']);
        Log::shouldHaveReceived('warning')->once()->with('Ping could not be sent', \Mockery::on(
            fn (array $ctx) => array_keys($ctx) === ['client_app', 'error'] && $ctx['client_app'] === $app->public_id
                && class_exists($ctx['error'])
        ));
    }

    public function test_resend_delivers_a_failed_event_synchronously_and_audits(): void
    {
        Http::fake(['shop.test/*' => Http::response('ok', 200)]);
        $app = $this->apps->create('Shop', 'https://shop.test/hook', null);
        $e = DB::transaction(fn () => Outbox::record($app, 'ping', ['message' => 'ping']));
        $e->update(['status' => 'failed', 'attempts' => 9, 'next_attempt_at' => null, 'last_error' => 'HTTP 500']);

        $r = $this->apps->resend($e, self::ADMIN);

        $this->assertTrue($r['ok']);
        $this->assertSame('delivered', $e->fresh()->status);
        $row = AuditEntry::query()->where('action', 'outgoing_event.resent')->sole();
        $this->assertSame($e->public_id, $row->subject_id);
        $this->assertSame('admin', $row->actor_type);
    }

    public function test_resend_refuses_pending_event_and_reports_failures(): void
    {
        Http::fake(['shop.test/*' => Http::response('bad', 500)]);
        $app = $this->apps->create('Shop', 'https://shop.test/hook', null);
        $e = DB::transaction(fn () => Outbox::record($app, 'ping', ['message' => 'ping']));

        $r = $this->apps->resend($e, null);
        $this->assertFalse($r['ok']);
        $this->assertSame(0, AuditEntry::query()->where('action', 'outgoing_event.resent')->count());
        Http::assertNothingSent();

        $e->update(['status' => 'delivered']);
        $this->assertSame(['ok' => false, 'message' => 'Failed · HTTP 500'], $this->apps->resend($e->fresh(), null));
    }

    public function test_masked(): void
    {
        $this->assertSame(['length' => 12, 'last4' => '9abc'], $this->apps->masked('012345679abc'));
        $this->assertSame(['length' => 8, 'last4' => '5678'], $this->apps->masked('12345678'));
        $this->assertSame(['length' => 7, 'last4' => ''], $this->apps->masked('1234567'));
        $this->assertSame(['length' => 0, 'last4' => ''], $this->apps->masked(''));
    }
}
