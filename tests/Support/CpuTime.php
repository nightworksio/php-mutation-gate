<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;
use function getrusage;
use function is_array;
use function is_int;

use RuntimeException;

use function sprintf;

/** The CPU time this process has spent, which a busy machine does not add to while the process waits its turn. */
final class CpuTime
{
    /** Microseconds in a second, as `getrusage` counts the part of a second. */
    public const float MICROSECONDS = 1e6;

    /** The fields of the CPU time spent, each with how many of it make a second. */
    private const array SPENT = [
        'ru_utime.tv_sec' => 1.0,
        'ru_utime.tv_usec' => self::MICROSECONDS,
        'ru_stime.tv_sec' => 1.0,
        'ru_stime.tv_usec' => self::MICROSECONDS,
    ];

    /** The CPU seconds this process has spent, in user and system time. */
    public static function spent(): float
    {
        $usage = getrusage();
        $spent = 0.0;

        foreach (self::SPENT as $field => $per) {
            $value = is_array($usage) && array_key_exists($field, $usage) ? $usage[$field] : null;
            $spent += is_int($value) ? $value / $per : throw new RuntimeException(sprintf('getrusage() gave no %s.', $field));
        }

        return $spent;
    }
}
