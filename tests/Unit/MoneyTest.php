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
}
