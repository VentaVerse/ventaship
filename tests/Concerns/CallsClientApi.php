<?php

namespace Tests\Concerns;

use App\Models\ClientApp;
use App\Platforms\PlatformApps;
use Illuminate\Testing\TestResponse;

trait CallsClientApi
{
    /** @return array{app: ClientApp, key: string} key is the Sanctum token as a client sends it */
    protected function makeClient(?string $webhookUrl = null): array
    {
        $app = ClientApp::factory()->create(['name' => 'VentaCart Pro', 'webhook_url' => $webhookUrl]);
        $issued = app(PlatformApps::class)->issueKey($app, 'Test');

        return ['app' => $app, 'key' => $issued['plaintext_key']];
    }

    /**
     * A JSON call with the client's bearer token. Header overrides win over the defaults.
     *
     * @param  array{app: ClientApp, key: string}|null  $client
     */
    protected function apiJson(string $method, string $uri, array $data = [], array $headers = [], ?array $client = null): TestResponse
    {
        $client ??= $this->makeClient();
        $method = strtoupper($method);
        $body = $data === [] ? '' : json_encode($data, JSON_THROW_ON_ERROR);

        $headers = array_merge([
            'Authorization' => 'Bearer '.$client['key'],
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $headers);

        return $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }
}
