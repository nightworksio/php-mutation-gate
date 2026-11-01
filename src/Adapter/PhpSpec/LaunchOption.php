<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function array_map;

use NightWorksIO\MutationGate\Core\Native\Launched;

/**
 * What the gate tells its PhpSpec launcher on the command line, ahead of
 * PhpSpec's own arguments, which the launcher takes off before PhpSpec
 * reads them: the file of the examples a mutant's run selects, the group or
 * filter that judges the unit, the spec files a coverage run runs, and the
 * files a coverage run and a listing write.
 */
enum LaunchOption: string
{
    case Examples = '--mutation-gate-examples';
    case Group = '--mutation-gate-group';
    case Filter = '--mutation-gate-filter';
    case Spec = '--mutation-gate-spec';
    case Coverage = '--mutation-gate-coverage';
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
