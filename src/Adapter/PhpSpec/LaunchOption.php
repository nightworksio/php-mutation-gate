<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;
use function str_starts_with;

/**
 * What the gate tells its PhpSpec launcher on the command line, ahead of
 * PhpSpec's own arguments, which the launcher takes off before PhpSpec
 * reads them: the file of the examples a mutant's run selects, the group or
 * filter that judges the unit, the spec files a coverage run runs, and the
 * files a coverage run and a listing write.
 *
 * @internal
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
        return sprintf('%s=%s', $this->value, $value);
    }

    /** The option an argument sets; none where it sets none of these, and is PhpSpec's own. */
    public static function setBy(string $argument): self|NotGiven
    {
        foreach (self::cases() as $option) {
            if (str_starts_with($argument, $option->with(''))) {
                return $option;
            }
        }

        return NotGiven::value();
    }

    /** The value an argument that sets this option gives it. */
    public function valueIn(string $argument): string
    {
        return mb_substr($argument, mb_strlen($this->with('')));
    }
}
