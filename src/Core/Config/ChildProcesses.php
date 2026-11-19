<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * `pest.childProcesses`: whether a mutant is served to the PHP processes its tests start, as it is to the test's
 * own process (ADR-0150).
 */
enum ChildProcesses: string
{
    /** Every PHP process a mutant's test starts runs the mutant in its file's place. */
    case Serve = 'serve';

    /** A process a test starts runs the file as it is on disk. */
    case Off = 'off';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Serve => 'Pest::childProcessesServed()',
            self::Off => 'Pest::childProcessesOff()',
        };
    }
}
