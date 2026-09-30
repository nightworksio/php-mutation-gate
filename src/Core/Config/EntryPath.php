<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** Whether a `reports` entry names a path for its reporter (ADR-0009, ADR-0016). */
enum EntryPath
{
    /** A report written to a file, which the entry must name. */
    case Required;

    /** A report written where the entry may name, and to its own place where it names none. */
    case Optional;

    /** A report printed or sent, which writes no file the entry could name. */
    case Refused;
}
