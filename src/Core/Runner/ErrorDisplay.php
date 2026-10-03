<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function mb_strtolower;

/**
 * Where PHP prints a raised error, as it reads a `display_errors` value: a
 * word naming where, a switch that is on, which prints on standard output, or
 * else a number, where 0 prints nowhere, 2 on standard error and any other on
 * standard output. Text that is none of these, such as `off` or nothing,
 * reads as 0.
 */
enum ErrorDisplay
{
    case Stdout;
    case Stderr;
    case Nowhere;

    /** The number PHP reads as printing on standard error. */
    private const int STDERR = 2;

    /** Where PHP prints an error under this `display_errors` value. */
    public static function read(string $value): self
    {
        $lower = mb_strtolower($value);
        $word = DisplayWord::tryFrom($lower);

        return match (true) {
            $word instanceof DisplayWord => $word->display(),
            OnSwitch::tryFrom($lower) instanceof OnSwitch => self::Stdout,
            default => self::numbered((int) $value),
        };
    }

    /** Where PHP prints an error under a `display_errors` it reads as a number. */
    private static function numbered(int $number): self
    {
        return match ($number) {
            0 => self::Nowhere,
            self::STDERR => self::Stderr,
            default => self::Stdout,
        };
    }
}
