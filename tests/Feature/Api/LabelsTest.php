<?php

namespace Tests\Feature\Api;

use App\Couriers\CourierRegistry;
use App\Couriers\FakeCourier;
use App\Models\ClientApp;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Models\ShipmentLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SignsApiRequests;
use Tests\TestCase;
use ZipArchive;

class LabelsTest extends TestCase
{
    use RefreshDatabase, SignsApiRequests;

    private const URL = 'https://quadx-awb.s3.ap-southeast-1.amazonaws.com/staging/QX-1-zebra.pdf?X-Amz-Signature=ab';

    private array $client;

    private CourierAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->client = $this->makeClient();
        $this->account = CourierAccount::factory()->create(['courier' => 'quadx', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/tmp/*')) ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_single_label_fetched_once_then_served_from_disk(): void
    {
        $pdf = $this->pdf('a');
        Http::fake([self::URL => Http::response($pdf, 200)]);
        $fake = FakeCourier::make('quadx')->labelAt(self::URL);
        CourierRegistry::fake('quadx', $fake);
        $s = $this->booked('QX-1');

        $first = $this->get("/api/v1/shipments/{$s->public_id}/label")->assertOk();
        $second = $this->get("/api/v1/shipments/{$s->public_id}/label")->assertOk();

        $this->assertStringStartsWith('application/pdf', $first->headers->get('Content-Type'));
        $this->assertSame($pdf, $first->getContent());
        $this->assertSame($pdf, $second->getContent());
        Http::assertSentCount(1);
        $this->assertCount(1, $fake->callsTo('printLabel'));
        Storage::disk('local')->assertExists('labels/quadx/QX-1.pdf');
        $row = ShipmentLabel::query()->where('shipment_id', $s->id)->sole();
        $this->assertSame(strlen($pdf), $row->bytes);
        $this->assertSame(hash('sha256', $pdf), $row->sha256);
        $this->assertNotNull($row->fetched_at);
    }

    public function test_label_not_ready_409(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make('quadx'));
        $queued = Shipment::factory()->create($this->attrs(['state' => 'queued', 'tracking_number' => null]));

        $this->get("/api/v1/shipments/{$queued->public_id}/label")
            ->assertStatus(409)->assertJsonPath('code', 'label_not_ready');
    }

    public function test_foreign_shipment_label_is_404(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->label($this->pdf('a')));
        $other = Shipment::factory()->create($this->attrs(['client_app_id' => ClientApp::factory()->create()->id, 'state' => 'booked', 'tracking_number' => 'QX-9']));

        $this->get("/api/v1/shipments/{$other->public_id}/label")->assertNotFound();
    }

    public function test_link_on_an_unknown_host_is_refused_and_never_fetched(): void
    {
        Http::fake();
        Log::spy();
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->labelAt('https://evil.example/QX-1.pdf'));
        $s = $this->booked('QX-1');

        $this->get("/api/v1/shipments/{$s->public_id}/label")
            ->assertStatus(502)->assertJsonPath('code', 'courier_unavailable')->assertJsonPath('retryable', false);

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'allow-list'))->once();
        $this->assertSame(0, ShipmentLabel::query()->count());
    }

    #[DataProvider('badLinks')]
    public function test_non_https_userinfo_and_lookalike_hosts_are_refused(string $url): void
    {
        Http::fake();
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->labelAt($url));
        $s = $this->booked('QX-1');

        $this->get("/api/v1/shipments/{$s->public_id}/label")->assertStatus(502)->assertJsonPath('retryable', false);
        Http::assertNothingSent();
    }

    public static function badLinks(): array
    {
        return [
            'http' => ['http://api.quadx.xyz/a.pdf'],
            'lookalike' => ['https://evilquadx.xyz/a.pdf'],
            'suffix trick' => ['https://api.quadx.xyz.evil.example/a.pdf'],
            'userinfo' => ['https://api.quadx.xyz@evil.example/a.pdf'],
            'other bucket' => ['https://other.s3.ap-southeast-1.amazonaws.com/a.pdf'],
            'port' => ['https://api.quadx.xyz:8443/a.pdf'],
        ];
    }

    public function test_spx_refusal_from_the_port_is_not_retryable(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->labelAnswer(['success' => false, 'unsafe' => true, 'error' => 'x']));
        $s = $this->booked('QX-1');

        $this->get("/api/v1/shipments/{$s->public_id}/label")->assertStatus(502)->assertJsonPath('retryable', false);
    }

    public function test_bytes_that_are_not_a_pdf_are_refused(): void
    {
        Http::fake([self::URL => Http::response('<html>nope</html>', 200)]);
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->labelAt(self::URL));
        $s = $this->booked('QX-1');

        $this->get("/api/v1/shipments/{$s->public_id}/label")
            ->assertStatus(502)->assertJsonPath('code', 'courier_unavailable')->assertJsonPath('retryable', true);
        Storage::disk('local')->assertMissing('labels/quadx/QX-1.pdf');
        $this->assertSame(0, ShipmentLabel::query()->count());
    }

    public function test_download_failure_is_a_retryable_502(): void
    {
        Http::fake([self::URL => Http::response('', 500)]);
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->labelAt(self::URL));
        $s = $this->booked('QX-1');

        $this->get("/api/v1/shipments/{$s->public_id}/label")->assertStatus(502)->assertJsonPath('retryable', true);
    }

    public function test_merge_two_pdfs(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->label($this->pdf('a')));
        $one = $this->booked('QX-1');
        $two = $this->booked('QX-2');
        $unbooked = Shipment::factory()->create($this->attrs(['state' => 'queued', 'tracking_number' => null]));
        $foreign = Shipment::factory()->create($this->attrs(['client_app_id' => ClientApp::factory()->create()->id, 'state' => 'booked', 'tracking_number' => 'QX-7']));

        $res = $this->post('/api/v1/labels', ['shipment_ids' => [$one->public_id, $unbooked->public_id, $two->public_id, $foreign->public_id, $this->unknownId()]])
            ->assertOk();

        $this->assertStringStartsWith('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $res->getContent());
        $this->assertSame(2, $this->pageCount($res->getContent()));
        $this->assertSame(
            implode(',', [$unbooked->public_id, $foreign->public_id, $this->unknownId()]),
            $res->headers->get('Ventaship-Skipped'),
        );
        $this->assertSame([], glob(storage_path('app/tmp/*')) ?: []);
    }

    public function test_no_skipped_header_when_everything_prints(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->label($this->pdf('a')));
        $one = $this->booked('QX-1');
        $two = $this->booked('QX-2');

        $res = $this->post('/api/v1/labels', ['shipment_ids' => [$one->public_id, $two->public_id]])->assertOk();

        $this->assertFalse($res->headers->has('Ventaship-Skipped'));
    }

    public function test_zip_fallback_when_pdfunite_missing(): void
    {
        config(['couriers.pdfunite' => '/nonexistent']);
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->label($this->pdf('a')));
        $one = $this->booked('QX-1');
        $two = $this->booked('QX-2');

        $res = $this->post('/api/v1/labels', ['shipment_ids' => [$one->public_id, $two->public_id]])->assertOk();

        $this->assertStringStartsWith('application/zip', $res->headers->get('Content-Type'));
        $file = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($file, $res->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file));
        $names = [$zip->getNameIndex(0), $zip->getNameIndex(1)];
        $zip->close();
        unlink($file);
        sort($names);
        $expected = [$one->public_id.'.pdf', $two->public_id.'.pdf'];
        sort($expected);
        $this->assertSame($expected, $names);
        $this->assertSame([], glob(storage_path('app/tmp/*')) ?: []);
    }

    public function test_zip_fallback_when_pdfunite_fails(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->label('%PDF-1.4 not really a pdf'));
        $one = $this->booked('QX-1');
        $two = $this->booked('QX-2');

        $res = $this->post('/api/v1/labels', ['shipment_ids' => [$one->public_id, $two->public_id]])->assertOk();

        $this->assertStringStartsWith('application/zip', $res->headers->get('Content-Type'));
        $this->assertSame([], glob(storage_path('app/tmp/*')) ?: []);
    }

    public function test_nothing_printable_is_409(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make('quadx'));
        $queued = Shipment::factory()->create($this->attrs(['state' => 'queued', 'tracking_number' => null]));

        $this->post('/api/v1/labels', ['shipment_ids' => [$queued->public_id, $this->unknownId()]])
            ->assertStatus(409)->assertJsonPath('code', 'label_not_ready');
    }

    public function test_validation_and_limit(): void
    {
        $this->post('/api/v1/labels', ['shipment_ids' => []])->assertStatus(422);
        $this->post('/api/v1/labels', [])->assertStatus(422);
        $ids = array_map(fn ($i) => 'shp_'.str_pad((string) $i, 26, '0', STR_PAD_LEFT), range(1, 51));
        $this->post('/api/v1/labels', ['shipment_ids' => $ids])->assertStatus(422);
        $this->post('/api/v1/labels', ['shipment_ids' => [$this->unknownId(), $this->unknownId()]])->assertStatus(422);
    }

    public function test_labels_endpoint_is_throttled(): void
    {
        CourierRegistry::fake('quadx', FakeCourier::make('quadx'));
        for ($i = 0; $i < 10; $i++) {
            $this->post('/api/v1/labels', ['shipment_ids' => [$this->unknownId()]])->assertStatus(409);
        }

        $this->post('/api/v1/labels', ['shipment_ids' => [$this->unknownId()]])->assertStatus(429);
    }

    private function unknownId(): string
    {
        return 'shp_'.str_repeat('0', 26);
    }

    public function test_redirect_from_an_allowed_host_is_a_failure_and_nothing_is_stored(): void
    {
        Http::fake([self::URL => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->labelAt(self::URL));
        $s = $this->booked('QX-1');

        $this->get("/api/v1/shipments/{$s->public_id}/label")->assertStatus(502)->assertJsonPath('code', 'courier_unavailable');
        Http::assertSentCount(1);
        Storage::disk('local')->assertMissing('labels/quadx/QX-1.pdf');
        $this->assertSame(0, ShipmentLabel::query()->count());
    }

    public function test_batch_502_is_not_retryable_when_every_failure_is_permanent(): void
    {
        Http::fake();
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->labelAt('https://evil.example/a.pdf'));
        $s = $this->booked('QX-1');

        $this->post('/api/v1/labels', ['shipment_ids' => [$s->public_id]])
            ->assertStatus(502)->assertJsonPath('retryable', false);
    }

    public function test_batch_502_is_retryable_when_a_failure_is_retryable(): void
    {
        Http::fake([self::URL => Http::response('', 500)]);
        CourierRegistry::fake('quadx', FakeCourier::make('quadx')->labelAt(self::URL));
        $s = $this->booked('QX-1');

        $this->post('/api/v1/labels', ['shipment_ids' => [$s->public_id]])
            ->assertStatus(502)->assertJsonPath('retryable', true);
    }

    public function test_ids_must_have_the_shipment_id_format(): void
    {
        foreach (['shp_a,shp_b', "shp_x\r\nX-Evil: 1", 'abc', 'SHP_'.str_repeat('0', 26), $this->unknownId().'x'] as $bad) {
            $this->post('/api/v1/labels', ['shipment_ids' => [$bad]])
                ->assertStatus(422)->assertJsonPath('code', 'invalid_request');
        }
    }

    private function booked(string $tracking): Shipment
    {
        return Shipment::factory()->create($this->attrs(['state' => 'booked', 'tracking_number' => $tracking]));
    }

    private function attrs(array $over = []): array
    {
        $ref = 'R'.uniqid();

        return $over + [
            'client_app_id' => $this->client['app']->id,
            'courier_account_id' => $this->account->id,
            'client_reference' => $ref,
            'active_reference' => $ref,
        ];
    }

    private function pdf(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/pdf/label-{$name}.pdf"));
    }

    private function pageCount(string $pdf): int
    {
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($file, $pdf);
        $out = (string) shell_exec('pdfinfo '.escapeshellarg($file).' 2>/dev/null');
        unlink($file);

        return preg_match('/Pages:\s+(\d+)/', $out, $m) ? (int) $m[1] : 0;
    }

    public function get($uri, array $headers = [])
    {
        return $this->signedJson('GET', $uri, [], $headers + ['X-Timestamp' => $this->uniqueTimestamp()], $this->client);
    }

    public function post($uri, array $data = [], array $headers = [])
    {
        return $this->signedJson('POST', $uri, $data, $headers + ['X-Timestamp' => $this->uniqueTimestamp()], $this->client);
    }
}
