<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_map;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Config\PhpCalls;

use function preg_match;
use function sprintf;

/**
 * A config written as a `mutation-gate.php` (ADR-0002): `Gate::configure()`
 * and one call for each setting it holds, which reads back into the same
 * config.
 */
final readonly class Php
{
    /** The package whose builder classes a config's calls name. */
    private const string BUILDER = 'NightWorksIO\\MutationGate\\Config';

    /** The classes a config's calls name, in the order their `use` statements are written, each by its namespace. */
    private const array CLASSES = [
        'Badge' => self::BUILDER,
        'Baseline' => self::BUILDER,
        'Budget' => self::BUILDER,
        'Ci' => self::BUILDER,
        'Equivalence' => self::BUILDER,
        'Flaky' => self::BUILDER,
        'Floor' => self::BUILDER,
        'Gate' => self::BUILDER,
        'Ignore' => self::BUILDER,
        'Ignores' => self::BUILDER,
        'Load' => self::BUILDER,
        'Local' => self::BUILDER,
        'Option' => self::BUILDER,
        'Pest' => self::BUILDER,
        'Preset' => self::BUILDER,
        'Proofs' => self::BUILDER,
        'Reach' => self::BUILDER,
        'Report' => self::BUILDER,
        'Runner' => self::BUILDER,
        'Shards' => self::BUILDER,
        'Source' => self::BUILDER,
        'Tests' => self::BUILDER,
        'Timeouts' => self::BUILDER,
        'Tree' => self::BUILDER,
        'Uncovered' => self::BUILDER,
        'Glob' => 'NightWorksIO\\MutationGate\\Core\\File',
        'Withheld' => 'NightWorksIO\\MutationGate\\Core\\Runner',
    ];

    public static function render(PhpCalls $calls): string
    {
        $gate = array_map(
            static fn(array $call): string => self::call($call[0], $call[1]),
            $calls->gateCalls(),
        );
        $with = $calls->withCalls() === [] ? [] : [self::call('with', $calls->withCalls())];
        $code = implode('', [...$gate, ...$with]);
        $imports = '';

        foreach (self::CLASSES as $class => $namespace) {
            $used = $class === 'Gate' || preg_match(sprintf('/\\b%s::/', $class), $code) === 1;
            $imports = $used ? sprintf("%suse %s\\%s;\n", $imports, $namespace, $class) : $imports;
        }

        return sprintf("<?php\n\ndeclare(strict_types=1);\n\n%s\nreturn Gate::configure()%s;\n", $imports, $code);
    }

    /** @param list<string> $arguments */
    private static function call(string $method, array $arguments): string
    {
        $lines = array_map(static fn(string $argument): string => sprintf("\n        %s,", $argument), $arguments);

        return match (count($arguments)) {
            0 => sprintf("\n    ->%s()", $method),
            1 => sprintf("\n    ->%s(%s)", $method, $arguments[0]),
            default => sprintf("\n    ->%s(%s\n    )", $method, implode('', $lines)),
        };
    }
}
