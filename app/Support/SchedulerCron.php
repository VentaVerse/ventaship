<?php

namespace App\Support;

use App\Console\Commands\ScheduleHeartbeat;
use Carbon\CarbonImmutable;
use Throwable;

/** The one cron line a host needs, and when the scheduler last ran. */
final class SchedulerCron
{
    /** Every minute. */
    public const SCHEDULE = '* * * * *';

    /** The scheduler is healthy while its last run is this recent. */
    public const FRESH_SECONDS = 180;

    public static function command(?string $binary = null): string
    {
        return 'cd '.base_path().' && '.self::phpBinary($binary).' artisan schedule:run >> /dev/null 2>&1';
    }

    public static function line(?string $binary = null): string
    {
        return self::SCHEDULE.' '.self::command($binary);
    }

    /**
     * The PHP CLI binary for cron. Under php-fpm PHP_BINARY is the fpm daemon (e.g. /usr/sbin/php-fpm8.5), which
     * cannot run artisan: then the CLI binary of the same version next to it (/usr/bin/php8.5 on HestiaCP's
     * packages) when it can be seen, else plain `php` from cron's PATH.
     */
    public static function phpBinary(?string $binary = null): string
    {
        $binary ??= PHP_BINARY;
        if ($binary !== '' && ! str_contains(strtolower(basename($binary)), 'fpm')) {
            return $binary;
        }

        $versioned = PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        try {
            if (@is_file($versioned) && @is_executable($versioned)) { // open_basedir may hide it: then `php`
                return $versioned;
            }
        } catch (Throwable) {
            // falls through
        }

        return 'php';
    }

    public static function lastRun(Settings $settings): ?CarbonImmutable
    {
        $value = $settings->get(ScheduleHeartbeat::KEY);
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{0: string, 1: string} label, badge tone */
    public static function lastRunBadge(?CarbonImmutable $at): array
    {
        if ($at === null) {
            return ['Never', 'attn'];
        }

        $seconds = max(0, (int) $at->diffInSeconds(now(), true));
        $minutes = intdiv($seconds, 60);
        $label = match (true) {
            $seconds < 60 => 'Just now',
            $minutes < 60 => "{$minutes} min ago",
            default => $at->diffForHumans(),
        };

        return [$label, $seconds < self::FRESH_SECONDS ? 'good' : 'attn'];
    }
}
