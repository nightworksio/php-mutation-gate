<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

/** How a CI's marker variable says the CI runs a job. */
enum Marking
{
    /** The variable is set to `true`. */
    case SaysTrue;
    /** The variable is set to anything but nothing. */
    case IsSet;
    /** No variable says so: the CI is never detected. */
    case Never;
}
