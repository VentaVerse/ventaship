<?php

namespace App\View;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * Which validation errors this response already shows under their own field. A page's content renders before its
 * layout, so by the time the layout's flash runs every inline error has been marked; the flash then lists only the
 * rest (errors about no rendered field, such as an expired form or a refused action), so nothing is shown twice and
 * nothing vanishes.
 */
final class InlineErrors
{
    private const ATTRIBUTE = '_inline_error_keys';

    public static function mark(string $key): void
    {
        $request = request();
        $keys = (array) $request->attributes->get(self::ATTRIBUTE, []);
        $keys[$key] = true;
        $request->attributes->set(self::ATTRIBUTE, $keys);
    }

    public static function shown(string $key): bool
    {
        return isset(((array) request()->attributes->get(self::ATTRIBUTE, []))[$key]);
    }

    /** @return list<string> the default bag's messages whose key is shown under no field, in their order */
    public static function rest(mixed $errors): array
    {
        $bag = $errors instanceof ViewErrorBag ? $errors->getBag('default') : ($errors instanceof MessageBag ? $errors : null);
        if ($bag === null) {
            return [];
        }

        $out = [];
        foreach ($bag->messages() as $key => $messages) {
            if (self::shown((string) $key)) {
                continue;
            }
            foreach ($messages as $message) {
                $out[] = $message;
            }
        }

        return $out;
    }
}
