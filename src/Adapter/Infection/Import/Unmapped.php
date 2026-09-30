<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

/** Why an `ignore` pattern of Infection's maps to no entry of the gate's `ignores.entries`. */
enum Unmapped
{
    /** It is a pattern over source, which no entry of the gate's can say. */
    case OverSource;

    /** No file the psr-4 or psr-0 autoload names holds its class. */
    case NoFile;

    /** It holds a wildcard other than one over a whole namespace, which names no one class or namespace. */
    case Wildcard;

    /** No directory the psr-4 or psr-0 autoload names holds the namespace its wildcard covers. */
    case NoDirectory;

    /** It is under a profile, and Infection, which lists each profile's mutators, is not installed. */
    case NoInfection;

    /** It is under a profile Infection does not have. */
    case NoProfile;

    /** Why it maps to no entry, as the report says it. */
    public function said(): string
    {
        return match ($this) {
            self::OverSource => 'the gate has no equivalent of a pattern over source',
            self::NoFile => 'no file the psr-4 or psr-0 autoload names holds its class',
            self::NoDirectory => 'no directory the psr-4 or psr-0 autoload names holds its namespace',
            self::Wildcard => 'a wildcard names no one class or namespace',
            self::NoInfection => 'Infection, which lists each profile\'s mutators, is not installed',
            self::NoProfile => 'Infection has no such profile',
        };
    }
}
