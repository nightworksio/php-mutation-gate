<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

use function array_map;

use NightWorksIO\MutationGate\Core\Native\Launched;

/**
 * What the gate tells its Testo launcher on the command line, ahead of
 * Testo's own arguments, which the launcher keeps from Testo: the file of
 * the tests a mutant's run selects, the group or filter that judges the
 * unit, the test files a coverage run runs, whether the run stops after the
 * first test that fails, and the files a coverage run's times and a listing
 * are written to.
 */
enum LaunchOption: string
{
    case Tests = '--mutation-gate-tests';
    case Group = '--mutation-gate-group';
    case Filter = '--mutation-gate-filter';
    case File = '--mutation-gate-file';
    case Stop = '--mutation-gate-stop';
    case Times = '--mutation-gate-times';
    case Listing = '--mutation-gate-listing';

    /** What sets the option: its name, `=` and its value. */
    public function with(string $value): string
    {
        return Launched::setting($this->value, $value);
    }

    /** @return list<string> every option's name */
    public static function names(): array
    {
        return array_map(static fn(self $option): string => $option->value, self::cases());
    }
}
