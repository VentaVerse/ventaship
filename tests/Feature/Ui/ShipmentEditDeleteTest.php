<?php

namespace Tests\Feature\Ui;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsShipments;
use Tests\TestCase;

class ShipmentEditDeleteTest extends TestCase
{
    use BuildsShipments, RefreshDatabase;

    private CourierAccount $account;

    private FakeCourier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = $this->fakeDefaultAccount();
        $this->fake = FakeCourier::make('quadx');
        CourierRegistry::fake('quadx', $this->fake);
        $this->actingAs(Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']));
    }

    private function form(array $over = [], string $token = ''): array
    {
        return array_replace_recursive([
            'form_token' => $token,
            'account_id' => $this->account->public_id,
            'service' => '',
            'client_reference' => '',
            'recipient' => ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'phone' => '0917 123 4567', 'email' => '',
                'address_1' => '1 Rizal St', 'address_2' => '', 'barangay' => 'Poblacion', 'city' => 'Makati', 'state' => 'Metro Manila', 'postcode' => '1210'],
            'item' => 'Blue Tee',
            'value' => '1000.00',
            'parcel' => ['weight_kg' => '1.2', 'type' => 'box', 'length_cm' => '', 'width_cm' => '', 'height_cm' => ''],
            'payment' => ['method' => 'prepaid', 'cod_amount' => '', 'declared_value' => '1000'],
            'pickup_address_id' => $this->pickupId(),
        ], $over);
    }

    /** A manual shipment created the way production does, then refused by the courier. */
    private function failedManual(): Shipment
    {
        $this->fake->refusesWith('Barangay not serviceable');
        $html = $this->get('/shipments/new')->getContent();
        preg_match('/name="form_token" value="([0-9a-f-]{36})"/', $html, $m);
        $this->post('/shipments', $this->form(['client_reference' => 'REF-1'], $m[1]));
        $s = Shipment::query()->sole();
        $this->assertSame('failed', $s->state);
        $this->fake = FakeCourier::make('quadx');
        CourierRegistry::fake('quadx', $this->fake);

        return $s;
    }

    private function editToken(Shipment $s): string
    {
        $html = $this->get(route('shipments.edit', $s->public_id))->assertOk()->getContent();
        preg_match('/name="form_token" value="([0-9a-f-]{36})"/', $html, $m);
        $this->assertNotEmpty($m);

        return $m[1];
    }

    public function test_edit_failed_manual_shipment_and_save(): void
    {
        $s = $this->failedManual();

        $this->get(route('shipments.edit', $s->public_id))->assertOk()
            ->assertSeeText(['Edit shipment', 'Save and book'])
            ->assertSee('value="Juan"', false)
            ->assertSee('value="REF-1"', false)
            ->assertSee('Blue Tee', false)
            ->assertSee('name="_method" value="PUT"', false);

        $this->put(route('shipments.update', $s->public_id), $this->form(['recipient' => ['first_name' => 'Pedro'], 'client_reference' => 'REF-1']))
            ->assertRedirect(route('shipments.show', $s->public_id))->assertSessionHas('status', 'Saved');

        $s->refresh();
        $this->assertSame('draft', $s->state);
        $this->assertSame('Pedro', $s->recipient['first_name']);
        $this->assertNull($s->error_message);
        $this->assertNull($s->error_code);
        $this->assertFalse($s->needs_attention);
        $this->assertSame('REF-1', $s->active_reference);
        $this->assertSame([], $this->fake->callsTo('createShipment'));
    }

    public function test_blank_reference_keeps_existing_and_taken_reference_is_refused(): void
    {
        $s = $this->failedManual();
        $this->put(route('shipments.update', $s->public_id), $this->form())->assertSessionHasNoErrors();
        $this->assertSame('REF-1', $s->refresh()->client_reference);

        Shipment::factory()->create(['source' => 'manual', 'state' => 'booked', 'client_app_id' => null, 'client_reference' => 'REF-2', 'active_reference' => 'REF-2', 'courier_account_id' => $this->account->id]);
        $this->from(route('shipments.edit', $s->public_id))->put(route('shipments.update', $s->public_id), $this->form(['client_reference' => 'REF-2']))
            ->assertSessionHasErrors(['client_reference' => 'Already in use.']);
        $this->assertSame('REF-1', $s->refresh()->client_reference);
    }

    public function test_save_and_book_books_synchronously(): void
    {
        $s = $this->failedManual();
        $this->fake->booksAs('QX-EDIT-1');
        $token = $this->editToken($s);

        $this->put(route('shipments.update', $s->public_id), $this->form(['book' => '1', 'client_reference' => 'REF-1'], $token))
            ->assertRedirect(route('shipments.show', $s->public_id))->assertSessionHas('booked', true);

        $this->assertSame('booked', $s->refresh()->state);
        $this->assertSame('QX-EDIT-1', $s->tracking_number);
        $this->assertCount(1, $this->fake->callsTo('createShipment'));

        // A second post of the same render books nothing more.
        $this->put(route('shipments.update', $s->public_id), $this->form(['book' => '1', 'client_reference' => 'REF-1'], $token))
            ->assertRedirect(route('shipments.show', $s->public_id));
        $this->assertCount(1, $this->fake->callsTo('createShipment'));
    }

    public function test_save_and_book_refusal_leaves_it_failed_and_editable(): void
    {
        $s = $this->failedManual();
        $this->fake->refusesWith('Still not serviceable');
        $token = $this->editToken($s);

        $this->put(route('shipments.update', $s->public_id), $this->form(['book' => '1', 'client_reference' => 'REF-1'], $token))
            ->assertSessionHas('status', 'Courier refused: Still not serviceable');
        $this->assertSame('failed', $s->refresh()->state);
        $this->get(route('shipments.edit', $s->public_id))->assertOk();
    }

    public function test_edit_keeps_stored_recipient_email(): void
    {
        $s = $this->failedManual();
        $s->forceFill(['recipient' => ['email' => 'juan@example.com'] + (array) $s->recipient])->save();

        $form = $this->form(['recipient' => ['first_name' => 'Pedro']]);
        unset($form['recipient']['email'], $form['recipient']['address_2']);
        $this->put(route('shipments.update', $s->public_id), $form)->assertSessionHasNoErrors();

        $s->refresh();
        $this->assertSame('Pedro', $s->recipient['first_name']);
        $this->assertSame('juan@example.com', $s->recipient['email']);
    }

    public function test_edit_refused_for_booked(): void
    {
        $s = Shipment::factory()->create(['source' => 'manual', 'state' => 'booked', 'courier_account_id' => $this->account->id, 'recipient' => ['first_name' => 'Maria']]);

        $this->get(route('shipments.edit', $s->public_id))
            ->assertRedirect(route('shipments.show', $s->public_id))
            ->assertSessionHasErrors(['shipment' => 'Only unbooked manual shipments can be changed.']);

        $this->put(route('shipments.update', $s->public_id), $this->form())
            ->assertRedirect(route('shipments.show', $s->public_id))
            ->assertSessionHasErrors(['shipment' => 'Only unbooked manual shipments can be changed.']);
        $this->assertSame('Maria', $s->refresh()->recipient['first_name']);
    }

    public function test_edit_refused_for_api_draft(): void
    {
        $s = Shipment::factory()->create(['source' => 'api', 'state' => 'draft', 'courier_account_id' => $this->account->id, 'recipient' => ['first_name' => 'Maria']]);

        $this->get(route('shipments.edit', $s->public_id))->assertSessionHasErrors('shipment');
        $this->put(route('shipments.update', $s->public_id), $this->form())->assertSessionHasErrors('shipment');
        $this->assertSame('Maria', $s->refresh()->recipient['first_name']);
    }

    public function test_edit_refused_for_purged(): void
    {
        $s = Shipment::factory()->create(['source' => 'manual', 'state' => 'failed', 'courier_account_id' => $this->account->id, 'recipient' => null, 'pii_purged_at' => now()]);

        $this->get(route('shipments.edit', $s->public_id))->assertSessionHasErrors('shipment');
        $this->put(route('shipments.update', $s->public_id), $this->form())->assertSessionHasErrors('shipment');
        $this->delete(route('shipments.destroy', $s->public_id))->assertSessionHasErrors('shipment');
        $this->assertNull($s->refresh()->recipient);
    }

    public function test_delete_failed_manual_shipment(): void
    {
        $s = $this->failedManual();
        DB::table('shipment_events')->insert(['shipment_id' => $s->id, 'source' => 'system', 'courier_status' => 'x', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('labels/x.pdf', 'pdf');
        DB::table('shipment_labels')->insert(['shipment_id' => $s->id, 'format' => 'pdf', 'path' => 'labels/x.pdf', 'bytes' => 1, 'sha256' => str_repeat('a', 64), 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->delete(route('shipments.destroy', $s->public_id))
            ->assertRedirect(route('fulfillment'))->assertSessionHas('status', 'Deleted');

        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame(0, DB::table('shipment_events')->count());
        $this->assertSame(0, DB::table('shipment_labels')->count());
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing('labels/x.pdf');

        // The reference is free again.
        $this->post('/shipments', $this->form(['client_reference' => 'REF-1'], $this->newFormToken()));
        $this->assertSame('REF-1', Shipment::query()->sole()->client_reference);
    }

    public function test_delete_refused_for_booked_and_api(): void
    {
        $booked = Shipment::factory()->create(['source' => 'manual', 'state' => 'booked', 'courier_account_id' => $this->account->id]);
        $api = Shipment::factory()->create(['source' => 'api', 'state' => 'draft', 'courier_account_id' => $this->account->id]);

        foreach ([$booked, $api] as $s) {
            $this->delete(route('shipments.destroy', $s->public_id))
                ->assertRedirect(route('shipments.show', $s->public_id))
                ->assertSessionHasErrors(['shipment' => 'Only unbooked manual shipments can be changed.']);
        }

        $this->assertSame(2, Shipment::query()->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'shipment.deleted')->count());
    }

    public function test_delete_is_audited_without_pii(): void
    {
        $s = $this->failedManual();

        $this->delete(route('shipments.destroy', $s->public_id));

        $audit = AuditEntry::query()->where('action', 'shipment.deleted')->sole();
        $this->assertSame('admin', $audit->actor_type);
        $this->assertSame((string) auth()->id(), $audit->actor_id);
        $this->assertSame('shipment', $audit->subject_type);
        $this->assertSame($s->public_id, $audit->subject_id);
        $this->assertSame(['courier_ref' => $s->courier_ref], $audit->changes);
        $this->assertStringNotContainsString('Juan', json_encode($audit->toArray()));
    }

    public function test_show_page_delete_confirm_and_form(): void
    {
        $s = $this->failedManual();

        $html = $this->get(route('shipments.show', $s->public_id))->getContent();
        $this->assertStringContainsString('Delete shipment '.$s->client_reference.'?', $html);
        $this->assertMatchesRegularExpression('#<form id="sd-delete" method="POST" action="[^"]*/shipments/'.$s->public_id.'"[^>]*>.*?name="_method" value="DELETE"#s', $html);
    }

    public function test_guest_cannot_edit_or_delete(): void
    {
        $s = $this->failedManual();
        auth()->logout();
        $this->get(route('shipments.edit', $s->public_id))->assertRedirect(route('login'));
        $this->delete(route('shipments.destroy', $s->public_id))->assertRedirect(route('login'));
        $this->assertSame(1, Shipment::query()->count());
    }

    private function newFormToken(): string
    {
        preg_match('/name="form_token" value="([0-9a-f-]{36})"/', $this->get('/shipments/new')->getContent(), $m);

        return $m[1];
    }

    public function test_update_refused_for_queued_and_booking_and_changes_nothing(): void
    {
        foreach (['queued', 'booking'] as $state) {
            $s = Shipment::factory()->create(['source' => 'manual', 'state' => $state, 'courier_account_id' => $this->account->id, 'recipient' => ['first_name' => 'Maria']]);

            try {
                app(\App\Shipments\ShipmentCreator::class)->update($s, $this->form(['client_reference' => 'X-'.$state]) + ['client_reference' => 'X-'.$state]);
                $this->fail('update should be refused');
            } catch (\App\Api\ApiError $e) {
                $this->assertSame('not_editable', $e->errorCode);
                $this->assertSame('Only unbooked manual shipments can be changed.', $e->getMessage());
            }
            $this->assertSame($state, $s->refresh()->state);
            $this->assertSame('Maria', $s->recipient['first_name']);
        }
    }

    public function test_state_flipped_after_guard_is_refused_by_update_and_destroy(): void
    {
        $s = $this->failedManual();
        // The guard passes on the stale model, then the row is booked underneath.
        $stale = Shipment::query()->find($s->id);
        Shipment::query()->whereKey($s->id)->update(['state' => 'queued']);

        $this->assertTrue($stale->isEditable());
        $this->expectException(\App\Api\ApiError::class);
        try {
            app(\App\Shipments\ShipmentCreator::class)->update($stale, ['account_id' => $this->account->public_id, 'items' => []]);
        } finally {
            $this->assertSame('queued', $s->refresh()->state);
        }
    }

    public function test_destroy_refused_when_state_flipped_after_guard(): void
    {
        $s = $this->failedManual();
        Shipment::retrieved(function (Shipment $m) use ($s) {
            static $done = false;
            if (! $done && $m->id === $s->id) {
                $done = true;
                Shipment::query()->whereKey($s->id)->update(['state' => 'queued']);
            }
        });

        $this->delete(route('shipments.destroy', $s->public_id))->assertSessionHasErrors('shipment');
        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'shipment.deleted')->count());
    }

    public function test_save_and_book_refused_saves_edits_and_shows_error(): void
    {
        $s = $this->failedManual();
        $this->fake->refusesWith('Nope');
        $token = $this->editToken($s);

        $this->put(route('shipments.update', $s->public_id), $this->form(['book' => '1', 'client_reference' => 'REF-1', 'recipient' => ['first_name' => 'Pedro']], $token));

        $s->refresh();
        $this->assertSame('failed', $s->state);
        $this->assertSame('Nope', $s->error_message);
        $this->assertSame('Pedro', $s->recipient['first_name']);
        $this->get(route('shipments.show', $s->public_id))->assertSee('Nope');
    }

    public function test_second_claim_on_a_held_manual_reference_fails_cleanly(): void
    {
        $s = $this->failedManual();
        // The reference lock is held elsewhere: block() times out.
        $held = \Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
        $held->shouldReceive('block')->andThrow(new \Illuminate\Contracts\Cache\LockTimeoutException);
        \Illuminate\Support\Facades\Cache::shouldReceive('lock')->with('shipment-ref:manual:REF-9', 10)->andReturn($held);

        try {
            app(\App\Shipments\ShipmentCreator::class)->update($s, ['account_id' => $this->account->public_id, 'client_reference' => 'REF-9', 'items' => []]);
            $this->fail('expected 409');
        } catch (\App\Api\ApiError $e) {
            $this->assertSame('active_shipment_exists', $e->errorCode);
        }
        $this->assertSame('REF-1', $s->refresh()->client_reference);
    }

    public function test_save_is_audited_with_field_names_only(): void
    {
        $s = $this->failedManual();

        $this->put(route('shipments.update', $s->public_id), $this->form(['recipient' => ['first_name' => 'Pedro'], 'client_reference' => 'REF-1']))
            ->assertSessionHas('status', 'Saved');

        $audit = AuditEntry::query()->where('action', 'shipment.updated')->sole();
        $this->assertSame('admin', $audit->actor_type);
        $this->assertSame((string) auth()->id(), $audit->actor_id);
        $this->assertSame('127.0.0.1', $audit->ip);
        $this->assertSame('shipment', $audit->subject_type);
        $this->assertSame($s->public_id, $audit->subject_id);
        $this->assertContains('recipient', $audit->changes);
        $this->assertContains('state', $audit->changes);
        $this->assertTrue(array_is_list($audit->changes));
        $json = json_encode($audit->toArray());
        foreach (['Pedro', 'Juan', 'Dela Cruz', '0917', 'Rizal'] as $pii) {
            $this->assertStringNotContainsString($pii, $json);
        }
        $this->assertSame(0, AuditEntry::query()->where('action', 'shipment.book_requested')->count());
    }

    public function test_save_and_book_is_audited(): void
    {
        $s = $this->failedManual();
        $this->fake->booksAs('QX-EDIT-2');

        $this->put(route('shipments.update', $s->public_id), $this->form(['book' => '1', 'client_reference' => 'REF-1', 'recipient' => ['first_name' => 'Pedro']], $this->editToken($s)))
            ->assertSessionHas('booked', true);

        $this->assertSame(['shipment.created', 'shipment.updated', 'shipment.book_requested'], AuditEntry::query()->where('subject_id', $s->public_id)->orderBy('id')->pluck('action')->all());
        $booked = AuditEntry::query()->where('action', 'shipment.book_requested')->sole();
        $this->assertSame('admin', $booked->actor_type);
        $this->assertSame((string) auth()->id(), $booked->actor_id);
        $this->assertSame('shipment', $booked->subject_type);
        $json = json_encode(AuditEntry::query()->get()->toArray());
        $this->assertStringNotContainsString('Pedro', $json);
        $this->assertStringNotContainsString('Juan', $json);
    }

    public function test_save_and_book_after_another_tab_booked_it_says_already_booked(): void
    {
        $s = $this->failedManual();
        $token = $this->editToken($s);
        // Another tab books it between this request's save and its submit.
        Shipment::updated(function (Shipment $m) use ($s) {
            static $done = false;
            if (! $done && $m->id === $s->id && $m->state === 'draft') {
                $done = true;
                Shipment::query()->whereKey($s->id)->toBase()->update(['state' => 'booked']);
            }
        });

        $this->put(route('shipments.update', $s->public_id), $this->form(['book' => '1', 'client_reference' => 'REF-1'], $token))
            ->assertRedirect(route('shipments.show', $s->public_id))
            ->assertSessionHas('status', 'Already booked')
            ->assertSessionHasNoErrors();
        $this->assertSame([], $this->fake->callsTo('createShipment'));
    }

    public function test_inactive_account_stays_selected_and_is_never_switched(): void
    {
        $s = $this->failedManual();
        $other = CourierAccount::factory()->create(['courier' => 'quadx', 'label' => 'Aaa backup', 'status' => 'active']);
        $this->account->forceFill(['status' => 'disabled'])->save();

        $html = $this->get(route('shipments.edit', $s->public_id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#type="radio" name="account_id" value="'.$this->account->public_id.'"[^>]*checked.*?Inactive#s', $html);
        $this->assertDoesNotMatchRegularExpression('#value="'.$other->public_id.'"[^>]*checked#', $html);

        // Save keeps the account.
        $this->put(route('shipments.update', $s->public_id), $this->form(['client_reference' => 'REF-1', 'recipient' => ['first_name' => 'Pedro']]))
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Saved');
        $s->refresh();
        $this->assertSame($this->account->id, $s->courier_account_id);
        $this->assertSame('Pedro', $s->recipient['first_name']);

        // Save and book shows the server's refusal and books nothing.
        $this->from(route('shipments.edit', $s->public_id))
            ->put(route('shipments.update', $s->public_id), $this->form(['book' => '1', 'client_reference' => 'REF-1'], $this->editToken($s)))
            ->assertRedirect(route('shipments.edit', $s->public_id))
            ->assertSessionHasErrors(['account_id' => 'Not ready to book.']);
        $this->assertSame('draft', $s->refresh()->state);
        $this->assertSame($this->account->id, $s->courier_account_id);
        $this->assertSame([], $this->fake->callsTo('createShipment'));

        // A new shipment cannot pick the inactive account.
        $this->assertStringNotContainsString('value="'.$this->account->public_id.'"', $this->get('/shipments/new')->getContent());
    }
}
