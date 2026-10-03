<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * A word PHP reads in a `display_errors` value, in any case, that names where
 * an error is printed; a switch that is on prints on standard output too.
 */
enum DisplayWord: string
{
    case Stdout = 'stdout';
    case Stderr = 'stderr';

    /** Where PHP prints an error under this word. */
    public function display(): ErrorDisplay
    {
        return $this === self::Stderr ? ErrorDisplay::Stderr : ErrorDisplay::Stdout;
    }
}
