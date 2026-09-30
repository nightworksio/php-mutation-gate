<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function is_int;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * How a static analyser exits once it finished an analysis, Mago and PHPStan
 * alike: with nothing found, or with something. Any other exit, or none,
 * means it did not finish, and its report cannot be trusted.
 */
enum AnalysisExit: int
{
    case NothingFound = 0;
    case SomethingFound = 1;

    public static function finished(int|NotGiven $exit): bool
    {
        return is_int($exit) && self::tryFrom($exit) instanceof self;
    }
}
