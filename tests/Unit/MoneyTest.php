<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_formats_decimal_strings_without_float_maths(): void
    {
        $this->assertSame('₱1,240.00', Money::peso('1240.00'));
        $this->assertSame('₱0.50', Money::peso('0.5'));
        $this->assertSame('₱1,234,567.89', Money::peso('1234567.89'));
        $this->assertSame('₱12.00', Money::peso('12'));
        $this->assertSame('₱90,071,992,547,409.93', Money::peso('90071992547409.93'));
        $this->assertSame('-₱15.20', Money::peso('-15.2'));
    }

    public function test_decimal_reads_a_courier_amount_as_an_exact_two_decimal_string(): void
    {
        $this->assertSame('55.00', Money::decimal(55));
        $this->assertSame('57.50', Money::decimal(57.5));
        $this->assertSame('0.10', Money::decimal(0.1));
        $this->assertSame('23.20', Money::decimal(23.2));
        $this->assertSame('0.00', Money::decimal(0));
        $this->assertSame('1290.12', Money::decimal('1290.129'));
        $this->assertSame('7.00', Money::decimal(' 007 '));
        $this->assertSame('9999999999.99', Money::decimal('9999999999.99'));

        foreach ([null, '', 'abc', '-1', -0.5, '1e3', true, [], INF, NAN, '12345678901', 1e12] as $bad) {
            $this->assertNull(Money::decimal($bad), var_export($bad, true));
        }
    }
}
