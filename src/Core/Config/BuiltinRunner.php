<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** The runners this package builds in, by the name a config chooses each by. */
enum BuiltinRunner: string
{
    case Pest = 'pest';

    case Infection = 'infection';

    /** The gate's own PHPUnit runner, on the gate's own mutants (ADR-0023). */
    case PhpUnit = 'phpunit';

    public function named(): Name
    {
        return Name::of($this->value);
    }

    /** Whether it makes its mutants with its own mutators, as Pest and Infection do, rather than the gate's. */
    public function makesItsOwnMutants(): bool
    {
        return $this !== self::PhpUnit;
    }
}
