<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `reach.changedTests`: what a changed test file reaches (ADR-0065). */
enum ChangedTests: string
{
    /** The units its changed tests run, by the coverage map's ids; the units all its tests run where more changed. */
    case Narrow = 'narrow';

    /** The units its tests run, as the runner selects the file to judge each. */
    case File = 'file';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Narrow => 'Reach::narrowChangedTests()',
            self::File => 'Reach::wholeChangedTestFiles()',
        };
    }
}
