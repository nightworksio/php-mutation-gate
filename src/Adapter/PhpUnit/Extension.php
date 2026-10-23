<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function file_put_contents;
use function getenv;
use function ini_get;
use function is_string;

use NightWorksIO\MutationGate\Core\Native\Guard;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use PHPUnit\Event\Facade as Events;
use PHPUnit\Runner\Extension\Extension as PhpUnitExtension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * The PHPUnit extension the gate starts PHPUnit with for a mutant
 * (ADR-0023): it records how far each test got to the file
 * `MUTATION_GATE_RESULTS` names, and says in the file `MUTATION_GATE_GUARD`
 * names whether opcache could serve a cached original in place of the
 * mutated file. It does nothing where they name none.
 */
final readonly class Extension implements PhpUnitExtension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        Recorder::listening(getenv(Variable::Results->value), Events::instance());
        self::noting(getenv(Variable::Guard->value), Opcache::ofCommandLine(ini_get(Opcache::CLI)));
    }

    /** Says in the guard file, where one is named, that opcache could serve a cached original. */
    public static function noting(string|false $file, Opcache $opcache): void
    {
        $note = Guard::noting($opcache);

        if (is_string($file) && $file !== '' && is_string($note)) {
            file_put_contents($file, $note, FILE_APPEND | LOCK_EX);
        }
    }
}
