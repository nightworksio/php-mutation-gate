<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Runner\Program;

/** The runners this package builds in, by the name a config chooses each by. */
enum BuiltinRunner: string
{
    case Pest = 'pest';

    case Infection = 'infection';

    /** The gate's own PHPUnit runner, on the gate's own mutants (ADR-0023). */
    case PhpUnit = 'phpunit';

    /** The gate's own PhpSpec runner, on the gate's own mutants (ADR-0027). */
    case PhpSpec = 'phpspec';

    /** The gate's own Codeception runner, on the gate's own mutants (ADR-0027). */
    case Codeception = 'codeception';

    /** The gate's own Testo runner, on the gate's own mutants (ADR-0027). */
    case Testo = 'testo';

    public function named(): Name
    {
        return Name::of($this->value);
    }

    /** The package whose being installed chooses it under zero-config (ADR-0002). */
    public function package(): Package
    {
        return match ($this) {
            self::Pest => Package::PestMutate,
            self::Infection => Package::Infection,
            self::PhpUnit => Package::PhpUnit,
            self::PhpSpec => Package::PhpSpec,
            self::Codeception => Package::Codeception,
            self::Testo => Package::Testo,
        };
    }

    /** The program it runs, which names it wherever the gate speaks of it. */
    public function program(): Program
    {
        return match ($this) {
            self::Pest => Program::Pest,
            self::Infection => Program::Infection,
            self::PhpUnit => Program::PhpUnit,
            self::PhpSpec => Program::PhpSpec,
            self::Codeception => Program::Codeception,
            self::Testo => Program::Testo,
        };
    }

    /** Whether it makes its mutants with its own mutators, as Pest and Infection do, rather than the gate's. */
    public function makesItsOwnMutants(): bool
    {
        return $this === self::Pest || $this === self::Infection;
    }
}
