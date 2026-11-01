<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;
use function str_starts_with;

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
        return sprintf('%s=%s', $this->value, $value);
    }

    /** The option an argument sets; none where it sets none of these, and is Testo's own. */
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
