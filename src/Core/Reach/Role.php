<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

/** What a file is to changed test support. */
enum Role
{
    /** A file of test cases. */
    case TestFile;

    /** Test support: PHP under a test directory that is no file of test cases. */
    case Support;

    /** Anything else. */
    case Other;
}
