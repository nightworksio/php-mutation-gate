<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension;

/**
 * Each test file a runner's author writes for the runner contract's library, in the runner's own framework, by
 * where it is from the library's root, less the ending `RunnerLibrary` gives every test file. Between them they
 * leave `LibraryChange::Unused` run by no test.
 */
enum LibraryTest: string
{
    /**
     * Two tests of `Money`: that `add(2, 3)` is 5, which kills `LibraryChange::Adds`, and that `isLarge(500)` is
     * true and `isLarge(1)` false, under which `LibraryChange::Large` survives.
     */
    case Money = 'tests/Money';

    /** One test of `Money`: that `drain(3)` is 0, which `LibraryChange::Drains` never lets end. */
    case Drain = 'tests/Drain';

    /**
     * One test of `Held`, in the group `holds:src/Held.php`: that `double(0)` is 0, under which
     * `LibraryChange::Held` survives.
     */
    case Held = 'tests/Held';

    /** One test of `Held`, in no group: that `double(4)` is 8, which kills `LibraryChange::Held`. */
    case Double = 'tests/Double';
}
