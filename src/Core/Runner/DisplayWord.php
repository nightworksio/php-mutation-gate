<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** A word PHP reads in a `display_errors` value, in any case, rather than a number. */
enum DisplayWord: string
{
    case On = 'on';
    case Yes = 'yes';
    case True = 'true';
    case Stdout = 'stdout';
    case Stderr = 'stderr';

    /** Where PHP prints an error under this word. */
    public function display(): ErrorDisplay
    {
        return $this === self::Stderr ? ErrorDisplay::Stderr : ErrorDisplay::Stdout;
    }
}
