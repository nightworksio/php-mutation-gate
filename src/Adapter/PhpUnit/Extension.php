<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function getenv;

use PHPUnit\Event\Facade as Events;
use PHPUnit\Runner\Extension\Extension as PhpUnitExtension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * The PHPUnit extension the gate starts PHPUnit with for a mutant (ADR-0023
 * decision 9): it records how each test ended to the file
 * `MUTATION_GATE_RESULTS` names, and does nothing where that names none.
 */
final readonly class Extension implements PhpUnitExtension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        Recorder::listening(getenv(Variable::Results->value), Events::instance());
    }
}
