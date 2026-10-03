<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** How PHP writes an ini switch that is on, in any case: the number it reads as on, or a word it names. */
enum OnSwitch: string
{
    case One = '1';
    case On = 'on';
    case Yes = 'yes';
    case True = 'true';
}
