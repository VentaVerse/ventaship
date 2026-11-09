<?php

namespace Tests\Unit;

use App\Shipments\ItemsText;
use PHPUnit\Framework\TestCase;

class ItemsTextTest extends TestCase
{
    public function test_one_item_of_one_is_just_its_name(): void
    {
        $this->assertSame('Assorted goods', ItemsText::join([['name' => 'Assorted goods', 'quantity' => 1]]));
    }

    public function test_several_items_or_a_quantity_over_one_are_prefixed(): void
    {
        $this->assertSame('2 × Shirt, 1 × Cap', ItemsText::join([['name' => 'Shirt', 'quantity' => 2], ['name' => 'Cap', 'quantity' => '1']]));
        $this->assertSame('3 × Mug', ItemsText::join([['name' => ' Mug ', 'quantity' => 3]]));
    }

    public function test_odd_input_is_tolerated(): void
    {
        $this->assertSame('', ItemsText::join([]));
        $this->assertSame('', ItemsText::join(null));
        $this->assertSame('Tee', ItemsText::join([['name' => 'Tee'], 'junk', ['quantity' => 2], ['name' => '', 'quantity' => 1]]));
    }

    public function test_cut_to_a_maximum_length(): void
    {
        $text = ItemsText::join([['name' => str_repeat('a', 200), 'quantity' => 2], ['name' => str_repeat('b', 200), 'quantity' => 2]], 255);
        $this->assertSame(255, mb_strlen($text));
        $this->assertStringEndsWith('…', $text);
    }
}
