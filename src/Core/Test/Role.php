<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

/** What a file is to the tests: to the reach of a change to support, and to a content key. */
enum Role: string
{
    /** A file of test cases. */
    case TestCase = 'test case';

    /** PHP under the test directories that only declares, which acts on the tests that name what it declares. */
    case Support = 'support';

    /** Anything else under the test directories, which runs when it is loaded or is read by path. */
    case Loaded = 'loaded';

    /** A file outside the test directories. */
    case Elsewhere = 'elsewhere';
}
