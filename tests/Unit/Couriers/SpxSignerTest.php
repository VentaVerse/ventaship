<?php

namespace Tests\Unit\Couriers;

use App\Couriers\Spx\SpxClient;
use PHPUnit\Framework\TestCase;

/**
 * SpxClient::sign() proven byte-exact against the verified test vector from SPX's integration brief. None of these
 * values are real credentials: they are the document's own published test vector. sign() touches neither the
 * database nor any facade, so this is a plain PHPUnit test.
 */
class SpxSignerTest extends TestCase
{
    public function test_sign_matches_the_verified_integration_brief_test_vector(): void
    {
        $appId = 100000;
        $timestamp = 1677918414;
        $randomNum = 926611981;
        $payload = '{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}';
        $appSecret = 'H25HY53GO4BA2GQ';

        $expected = '97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d';

        $this->assertSame($expected, SpxClient::sign($appId, $timestamp, $randomNum, $payload, $appSecret));
    }

    public function test_sign_is_sensitive_to_every_component_of_the_original_value(): void
    {
        $appId = 100000;
        $timestamp = 1677918414;
        $randomNum = 926611981;
        $payload = '{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}';
        $appSecret = 'H25HY53GO4BA2GQ';
        $baseline = SpxClient::sign($appId, $timestamp, $randomNum, $payload, $appSecret);

        $this->assertNotSame($baseline, SpxClient::sign($appId + 1, $timestamp, $randomNum, $payload, $appSecret));
        $this->assertNotSame($baseline, SpxClient::sign($appId, $timestamp + 1, $randomNum, $payload, $appSecret));
        $this->assertNotSame($baseline, SpxClient::sign($appId, $timestamp, $randomNum + 1, $payload, $appSecret));
        $this->assertNotSame($baseline, SpxClient::sign($appId, $timestamp, $randomNum, $payload . ' ', $appSecret));
        $this->assertNotSame($baseline, SpxClient::sign($appId, $timestamp, $randomNum, $payload, 'a-different-secret'));
    }
}
