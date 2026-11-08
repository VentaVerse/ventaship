<?php

namespace Tests\Concerns;

use App\Api\Signature;
use App\Models\ApiKey;
use App\Models\ClientApp;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait SignsApiRequests
{
    /** @return array{app: ClientApp, key: string, secret: string} */
    protected function makeClient(?string $webhookUrl = null): array
    {
        $app = ClientApp::factory()->create(['name' => 'VentaCart Pro', 'webhook_url' => $webhookUrl]);
        $key = 'vsk_' . Str::random(40);
        $secret = Str::random(48);
        ApiKey::query()->create([
            'client_app_id' => $app->getKey(),
            'name' => 'Test',
            'key_prefix' => substr($key, 0, 8),
            'key_hash' => hash('sha256', $key),
            'signing_secret' => $secret,
        ]);

        return ['app' => $app, 'key' => $key, 'secret' => $secret];
    }

    /**
     * Header overrides win over the computed ones; an X-Timestamp override is the one that gets signed.
     *
     * @param  array{app: ClientApp, key: string, secret: string}|null  $client
     */
    protected function signedJson(string $method, string $uri, array $data = [], array $headers = [], ?array $client = null): TestResponse
    {
        $client ??= $this->makeClient();
        $method = strtoupper($method);
        $body = $data === [] ? '' : json_encode($data, JSON_THROW_ON_ERROR);
        $ts = (int) ($headers['X-Timestamp'] ?? time());

        $headers = array_merge([
            'Authorization' => 'Bearer ' . $client['key'],
            'X-Timestamp' => (string) $ts,
            'X-Signature' => Signature::sign($client['secret'], $ts, $method, $uri, $body),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $headers);

        return $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }
}
