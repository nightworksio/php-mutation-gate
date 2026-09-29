<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

/** What a file under the test directories is to a content key. */
enum Role: string
{
    /** A file of test cases, in the keys of the units it can judge. */
    case TestCase = 'test case';

    /** PHP that only declares, in the keys of the units whose tests name what it declares. */
    case Support = 'support';

    /** Anything else, which runs when it is loaded or is read by path, in every key. */
    case Loaded = 'loaded';
}
