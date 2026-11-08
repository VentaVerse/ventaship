<?php

namespace Tests\Feature\Couriers;

use App\Couriers\Lbc\LbcPickupErrors;
use App\Couriers\Lbc\LbcPickupResult;
use Tests\Concerns\LbcFixtures;
use Tests\TestCase;

/**
 * LBC's pickup answers reduced to accepted, rejected or unknown, and its
 * response codes in plain words.
 */
class LbcPickupResultTest extends TestCase
{
    use LbcFixtures;

    public function test_success_xml_is_accepted_with_the_echoed_number(): void
    {
        $result = LbcPickupResult::fromResponse(200, self::pickupAccepted('10000000000001'));

        $this->assertSame(LbcPickupResult::ACCEPTED, $result->outcome);
        $this->assertTrue($result->isSuccess);
        $this->assertSame('100', $result->code());
        $this->assertSame('10000000000001', $result->trackingNo());
        $this->assertStringContainsString('Saved by LBC (code 100)', $result->summary());
    }

    public function test_success_json_from_the_portal_sample_is_accepted(): void
    {
        $body = json_encode(['IsSuccess' => true, 'MessageList' => [['Code' => '100', 'Message' => 'Successfully saved.', 'TrackingNo' => '1234567894444']]]);

        $result = LbcPickupResult::fromResponse(200, $body);

        $this->assertSame(LbcPickupResult::ACCEPTED, $result->outcome);
        $this->assertSame('1234567894444', $result->trackingNo());
    }

    public function test_a_field_validation_code_is_a_definite_rejection(): void
    {
        $result = LbcPickupResult::fromResponse(200, self::pickupRejected('107', 'Shipper Account No. is not valid'));

        $this->assertSame(LbcPickupResult::REJECTED, $result->outcome);
        $this->assertFalse($result->isSuccess);
        $this->assertSame(
            'Shipper account number is missing or not valid (code 107): Shipper Account No. is not valid',
            $result->summary()
        );
    }

    public function test_namespaced_responses_are_read(): void
    {
        $body = '<ReturnEntity xmlns="http://schemas.example.com/lbc"><IsSuccess>false</IsSuccess><MessageList>'
            . '<ResponseMessageEntity><Code>127</Code><Message>Consignee Send SMS is required</Message></ResponseMessageEntity>'
            . '</MessageList></ReturnEntity>';

        $this->assertSame(LbcPickupResult::REJECTED, LbcPickupResult::fromResponse(200, $body)->outcome);
    }

    /** Anything LBC may have saved keeps the number spent. */
    public function test_doubtful_answers_are_unknown(): void
    {
        $cases = [
            'duplicate booking' => [200, self::pickupRejected('1007', 'Duplicate booking request')],
            'insert exception' => [200, self::pickupRejected('1005', 'Data insert exception.')],
            'unreadable body' => [200, 'Service Unavailable'],
            'empty body' => [502, ''],
            'success flag without code 100' => [200, '<ReturnEntity><IsSuccess>true</IsSuccess></ReturnEntity>'],
            'server error with success body' => [500, self::pickupAccepted('10000000000001')],
        ];

        foreach ($cases as $label => [$status, $body]) {
            $this->assertSame(LbcPickupResult::UNKNOWN, LbcPickupResult::fromResponse($status, $body)->outcome, $label);
        }

        $this->assertSame(LbcPickupResult::UNKNOWN, LbcPickupResult::transportFailure('timed out')->outcome);
    }

    public function test_external_entities_are_never_expanded(): void
    {
        $body = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            . '<ReturnEntity><IsSuccess>false</IsSuccess><MessageList><ResponseMessageEntity>'
            . '<Code>140</Code><Message>&x;</Message></ResponseMessageEntity></MessageList></ReturnEntity>';

        $result = LbcPickupResult::fromResponse(200, $body);

        $this->assertStringNotContainsString('root:', $result->summary());
    }

    public function test_every_documented_code_has_a_readable_message(): void
    {
        $documented = array_merge(range(101, 115), range(117, 128), range(130, 152));

        foreach ($documented as $code) {
            $this->assertTrue(LbcPickupErrors::isValidationCode((string) $code), "code {$code}");
            $this->assertStringContainsString('is missing or not valid', LbcPickupErrors::describe((string) $code));
        }

        $this->assertFalse(LbcPickupErrors::isValidationCode('116'));
        $this->assertFalse(LbcPickupErrors::isValidationCode('129'));
        $this->assertSame('Destination code is missing or not valid (code 151).', LbcPickupErrors::describe('151'));
        $this->assertSame('Client is missing or not valid (code 152).', LbcPickupErrors::describe('152'));
        $this->assertSame('Invalid key (code 1000).', LbcPickupErrors::describe('1000'));
        $this->assertSame('Unknown LBC response code (code 999).', LbcPickupErrors::describe('999'));
    }
}
