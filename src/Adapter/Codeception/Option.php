<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Codeception;

/**
 * What the gate tells its Codeception extension, as the extension's own
 * settings under Codeception's `extensions.config`: the file of the tests a
 * mutant's run selects, the group or filter that judges the unit, the test
 * files a coverage run runs, the files a coverage run and a listing write,
 * and the project's vendor directory, whose files a coverage map leaves out.
 */
enum Option: string
{
    case Selected = 'selected';
    case Group = 'in-group';
    case Filter = 'matching';
    case Files = 'declared-in';
    case Coverage = 'coverage-map';
    case Listing = 'listing-file';
    case Vendor = 'vendor-directory';
}
