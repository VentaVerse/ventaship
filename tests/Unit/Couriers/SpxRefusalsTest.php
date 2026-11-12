<?php

namespace Tests\Unit\Couriers;

use App\Couriers\Spx\SpxRefusals;
use App\Couriers\Support\CourierRefusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SpxRefusalsTest extends TestCase
{
    /** @return array<string, array{0: ?int, 1: string, 2: array<string, mixed>, 3: string, 4: string}> */
    public static function answers(): array
    {
        return [
            'too long, as SPX words it on create' => [13101,
                'check rule|7 |ruleName| MaxLengthExtremumRule failed, 35.000000 exceed max value 25.000000 order {"order_id":"VS000042"}', [],
                'Too long for SPX', 'Length is 35 cm. SPX takes up to 25 cm.'],
            'too wide on the fee check' => [13051, 'MaxWidthExtremumRule failed, 60 exceed max value 45', [],
                'Too wide for SPX', 'Width is 60 cm. SPX takes up to 45 cm.'],
            'too tall (the height sum rule)' => [13051, 'HeightSumExtremumRule failed, 60 exceed max value 30', [],
                'Too tall for SPX', 'Height is 60 cm. SPX takes up to 30 cm.'],
            'too heavy, with the typed weight' => [13051, 'parcel weight should less than or equal to 10 kg', ['weight_kg' => '12.50'],
                'Too heavy for SPX', 'Weight is 12.5 kg. SPX takes up to 10 kg.'],
            'too heavy, weight unknown' => [13051, 'parcel weight should less than or equal to 10.000 kg', [],
                'Too heavy for SPX', 'SPX takes up to 10 kg.'],
            'a minimum' => [13051, 'MinLengthExtremumRule failed, 0.5 less than min value 1.000000', [],
                'Too short for SPX', 'Length is 0.5 cm. SPX takes at least 1 cm.'],
            'an outer code with the typed side' => [10084, 'Parcel info error, length exceed max value.', ['length_cm' => 35.0],
                'Too long for SPX', 'Length is 35 cm.'],
            'an outer code alone' => [10085, 'Parcel info error, width exceed max value.', [],
                'Too wide for SPX', 'Check the width.'],
            'weight and volumetric weight' => [10083, '', [], 'Too heavy for SPX', 'Check the weight and size.'],
            'outside the region' => [10033, 'Sorry,the address you selected is not within the limits of the region', [],
                "Outside SPX's area", 'Check the delivery address.'],
            'too far' => [10021, '', [], 'Too far for SPX', 'The delivery address is too far from the pickup address.'],
            'phone format' => [11001, 'deliver_phone format invalid', [], 'Phone number not accepted by SPX', 'Check the phone number.'],
            'COD without a bank account (fee check)' => [12054, 'A bank account is needed to create COD orders. Please reach out to SPX PIC for support. ', [],
                'COD not set up with SPX', 'Add a bank account with SPX to send COD parcels.'],
            'unmapped' => [10099, 'Receiver name too long.', [], "SPX couldn't book this shipment", 'Check the details and try again.'],
            'nothing said' => [null, '', [], "SPX couldn't book this shipment", 'Check the details and try again.'],
        ];
    }

    #[DataProvider('answers')]
    public function test_spx_answers_read_plainly(?int $code, string $text, array $context, string $title, string $detail): void
    {
        $refusal = SpxRefusals::explain($code, $text, $context);

        $this->assertSame($title, $refusal->title);
        $this->assertSame($detail, $refusal->detail);
        $this->assertStringNotContainsString('Rule', $refusal->message());
        $this->assertStringNotContainsString('.000', $refusal->message());
    }

    public function test_the_stored_message_joins_title_and_detail(): void
    {
        $this->assertSame('Too long for SPX. Length is 35 cm. SPX takes up to 25 cm.',
            SpxRefusals::explain(13051, 'MaxLengthExtremumRule failed, 35.000000 exceed max value 25.000000')->message());
        $this->assertSame('Pickup time has passed.', (new CourierRefusal('Pickup time has passed'))->message());
    }

    public function test_only_answers_about_the_shipment_are_refusals(): void
    {
        foreach ([10084, 10033, 11001, 12054, 13051, 13101] as $code) {
            $this->assertTrue(SpxRefusals::isRefusal($code), (string) $code);
        }
        foreach ([null, 0, 1001, 12052, 99001, 99004, 10020, 10076, 13103] as $code) {
            $this->assertFalse(SpxRefusals::isRefusal($code), (string) $code);
        }
    }

    public function test_numbers_drop_trailing_zeros(): void
    {
        $this->assertSame('35', CourierRefusal::number('35.000000'));
        $this->assertSame('12.5', CourierRefusal::number('12.50'));
        $this->assertSame('0.5', CourierRefusal::number('0.500'));
        $this->assertSame('100', CourierRefusal::number('100'));
        $this->assertSame('0', CourierRefusal::number('0.000'));
        $this->assertSame('abc', CourierRefusal::number('abc'));
    }
}
