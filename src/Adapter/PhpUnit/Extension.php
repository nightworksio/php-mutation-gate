<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function getenv;
use function ini_get;

use NightWorksIO\MutationGate\Core\Runner\Opcache;
use PHPUnit\Event\Facade as Events;
use PHPUnit\Runner\Extension\Extension as PhpUnitExtension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * The PHPUnit extension the gate starts PHPUnit with for a mutant (ADR-0023
 * decision 9): it records how far each test got to the file
 * `MUTATION_GATE_RESULTS` names, and says in the file `MUTATION_GATE_GUARD`
 * names whether opcache could serve a cached original in place of the
 * mutated file. It does nothing where they name none.
 */
final readonly class Extension implements PhpUnitExtension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        Recorder::listening(getenv(Variable::Results->value), Events::instance());
        Guard::noting(getenv(Variable::Guard->value), Opcache::ofCommandLine(ini_get('opcache.enable_cli')));
    }
}
