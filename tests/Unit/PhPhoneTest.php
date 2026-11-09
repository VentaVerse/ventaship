<?php

namespace Tests\Unit;

use App\Couriers\Support\PhPhone;
use PHPUnit\Framework\TestCase;

class PhPhoneTest extends TestCase
{
    public function test_groups_an_11_digit_09_number_and_leaves_anything_else_as_stored(): void
    {
        $this->assertSame('0917 621 0807', PhPhone::shown('09176210807'));
        $this->assertSame('0917 621 0807', PhPhone::shown('0917-621-0807'));
        $this->assertSame('+63 917 621 0807', PhPhone::shown('+63 917 621 0807'));
        $this->assertSame('028123456', PhPhone::shown('028123456'));
        $this->assertNull(PhPhone::shown(null));
        $this->assertNull(PhPhone::shown('  '));
    }
}
