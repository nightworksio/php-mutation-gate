<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;
use function getenv;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;

use function putenv;
use function sprintf;

/**
 * The variables the gate sets for a run, cleared before a test reads them and
 * put back after it. In a mutant's own run they name that run's results file,
 * which a test writing through them would otherwise write its own records to.
 */
final class GateEnvironment
{
    /** @var array<string, string|false> what each variable held before it was cleared, by its name */
    private static array $held = [];

    /** @return list<string> every variable the gate or Pest sets for a mutant's own run, by its name */
    public static function names(): array
    {
        return [
            ...array_map(static fn(GateVariable $variable): string => $variable->value, GateVariable::cases()),
            Recorder::MUTANT,
            Recorder::MUTATED,
        ];
    }

    public static function clear(): void
    {
        foreach (self::names() as $name) {
            self::$held[$name] = getenv($name);
            putenv($name);
        }
    }

    public static function restore(): void
    {
        foreach (self::$held as $name => $value) {
            putenv($value === false ? $name : sprintf('%s=%s', $name, $value));
        }

        self::$held = [];
    }
}
