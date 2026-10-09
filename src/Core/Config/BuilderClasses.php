<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function in_array;
use function sprintf;
use function str_starts_with;

/**
 * The classes a `mutation-gate.php` builds the gate with (ADR-0002): every
 * class of the builder's package, and the values of the core it names. None
 * of them reads a file, so a config that names only these reads none by
 * naming them.
 */
final readonly class BuilderClasses
{
    /** The package whose builder classes a config's calls name. */
    public const string BUILDER = 'NightWorksIO\\MutationGate\\Config';

    /** The classes a config's calls name, in the order their `use` statements are written, each by its namespace. */
    public const array CLASSES = [
        'Badge' => self::BUILDER,
        'Baseline' => self::BUILDER,
        'Budget' => self::BUILDER,
        'Ci' => self::BUILDER,
        'Coverage' => self::BUILDER,
        'Equivalence' => self::BUILDER,
        'Flaky' => self::BUILDER,
        'Floor' => self::BUILDER,
        'Gate' => self::BUILDER,
        'Ignore' => self::BUILDER,
        'Ignores' => self::BUILDER,
        'Load' => self::BUILDER,
        'Local' => self::BUILDER,
        'Mutators' => self::BUILDER,
        'Option' => self::BUILDER,
        'Pest' => self::BUILDER,
        'Pipeline' => self::BUILDER,
        'Preset' => self::BUILDER,
        'Proofs' => self::BUILDER,
        'Pruning' => self::BUILDER,
        'Reach' => self::BUILDER,
        'Report' => self::BUILDER,
        'Runner' => self::BUILDER,
        'Shards' => self::BUILDER,
        'Source' => self::BUILDER,
        'Survivors' => self::BUILDER,
        'StaticCheck' => self::BUILDER,
        'Tests' => self::BUILDER,
        'Timeouts' => self::BUILDER,
        'Tree' => self::BUILDER,
        'Uncovered' => self::BUILDER,
        'Glob' => 'NightWorksIO\\MutationGate\\Core\\File',
        'MemoryCap' => 'NightWorksIO\\MutationGate\\Core\\Runner',
        'MemoryUnit' => 'NightWorksIO\\MutationGate\\Core\\Runner',
        'Withheld' => 'NightWorksIO\\MutationGate\\Core\\Runner',
        'Workers' => 'NightWorksIO\\MutationGate\\Core\\Runner',
    ];

    /** Whether a class, by its full name, is one a config builds the gate with. */
    public static function holds(string $class): bool
    {
        $named = [];

        foreach (self::CLASSES as $short => $namespace) {
            $named[] = sprintf('%s\\%s', $namespace, $short);
        }

        return str_starts_with($class, sprintf('%s\\', self::BUILDER)) || in_array($class, $named, strict: true);
    }
}
