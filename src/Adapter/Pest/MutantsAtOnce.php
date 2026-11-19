<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Core\WholeNumber;
use Pest\Mutate\Repositories\ConfigurationRepository;
use Pest\Mutate\Support\Configuration\CliConfiguration;
use Pest\Support\Container;

/**
 * How many mutants a mutation run runs at once, as the gate names it
 * (ADR-0120): the count pest-plugin-mutate takes from `--processes`, set in
 * its command-line configuration, which stands over the project's own, so
 * the run takes it whether pest-plugin-mutate is patched or not. Pest is
 * never handed the option, which it refuses. Where the gate names none,
 * pest-plugin-mutate runs as many as the machine has cores.
 *
 * @internal
 */
final readonly class MutantsAtOnce
{
    /** The count the gate set for this run, in the command-line configuration of pest-plugin-mutate. */
    public static function fromEnvironment(): void
    {
        $repository = Container::getInstance()->get(ConfigurationRepository::class);

        if ($repository instanceof ConfigurationRepository) {
            self::setIn($repository->cliConfiguration, getenv(GateVariable::AtOnce->value));
        }
    }

    /** This count, where it is one or more, in this command-line configuration. */
    public static function setIn(CliConfiguration $configuration, string|false $count): void
    {
        if (is_string($count) && WholeNumber::isDigits($count) && (int) $count > 0) {
            $configuration->processes((int) $count);
        }
    }
}
