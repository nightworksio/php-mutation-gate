<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

/** Why an `ignore` pattern of Infection's maps to no entry of the gate's `ignores.entries`. */
enum Unmapped
{
    /** No file the psr-4 or psr-0 autoload names holds its class. */
    case NoFile;

    /** It holds a wildcard, which names no one class. */
    case Wildcard;

    /** It is under a profile, whose mutators are Infection's to list. */
    case Profile;

    /** Why it maps to no entry, as the report says it. */
    public function said(): string
    {
        return match ($this) {
            self::NoFile => 'no file the psr-4 or psr-0 autoload names holds its class',
            self::Wildcard => 'a wildcard names no one class',
            self::Profile => 'a profile names mutators only Infection lists',
        };
    }
}
