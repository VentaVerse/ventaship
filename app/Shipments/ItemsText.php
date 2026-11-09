<?php

namespace App\Shipments;

/**
 * A shipment's items as one line of text, the way the Add / Edit form's single Item field holds them: one item of
 * quantity 1 is just its name ("Assorted goods"); otherwise each is "{quantity} × {name}" ("2 × Shirt, 1 × Cap").
 */
final class ItemsText
{
    public static function join(mixed $items, ?int $max = null): string
    {
        $rows = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $name = is_array($item) && is_scalar($item['name'] ?? null) ? trim((string) $item['name']) : '';
            if ($name === '') {
                continue;
            }
            $quantity = is_numeric($item['quantity'] ?? null) ? max(1, (int) $item['quantity']) : 1;
            $rows[] = [$name, $quantity];
        }

        $text = count($rows) === 1 && $rows[0][1] === 1
            ? $rows[0][0]
            : implode(', ', array_map(fn (array $r) => "{$r[1]} × {$r[0]}", $rows));

        return $max !== null && mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
