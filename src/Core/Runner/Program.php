<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * A program the gate starts to run a project's tests, named as its own
 * project names it wherever the gate speaks of it: whose coverage run failed,
 * and whose packages Composer does not list. Each is also a runner a config
 * chooses by name (`BuiltinRunner`), and PHPUnit runs Infection's coverage
 * too. An analyser is chosen apart, by `BuiltinAnalyser`.
 *
 * @internal
 */
enum Program
{
    case Pest;
    case Infection;
    case PhpUnit;
    case PhpSpec;
    case Codeception;

    /** What a person calls it: Pest, Infection, PHPUnit, PhpSpec or Codeception. */
    public function title(): string
    {
        return match ($this) {
            self::Pest => 'Pest',
            self::Infection => 'Infection',
            self::PhpUnit => 'PHPUnit',
            self::PhpSpec => 'PhpSpec',
            self::Codeception => 'Codeception',
        };
    }
}
