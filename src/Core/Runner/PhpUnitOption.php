<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** An option of PHPUnit's command line that more than one runner's adapter writes or reads. */
enum PhpUnitOption: string
{
    /** Leaves PHPUnit's result cache as it was. */
    case DoNotCacheResult = '--do-not-cache-result';

    /** Writes the coverage map as PHP, to the file named after `=`. */
    case CoveragePhp = '--coverage-php';
}
