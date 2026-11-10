<?php

namespace App\Support;

/**
 * The released version, read from the VERSION file at the repo root once per process. MAJOR.MINOR.PATCH with an
 * optional pre-release suffix (1.1.0-beta.1). It changes only on a release (see CONTRIBUTING.md).
 */
final class AppVersion
{
    public const PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$/';

    private static ?string $cached = null;

    public static function current(): string
    {
        return self::$cached ??= self::read(base_path('VERSION'));
    }

    /** A missing, empty or malformed file reads as "0.0.0" rather than taking a page down. */
    public static function read(string $path): string
    {
        $raw = is_file($path) ? trim((string) file_get_contents($path)) : '';

        return preg_match(self::PATTERN, $raw) === 1 ? $raw : '0.0.0';
    }

    /** For tests. */
    public static function flush(): void
    {
        self::$cached = null;
    }
}
