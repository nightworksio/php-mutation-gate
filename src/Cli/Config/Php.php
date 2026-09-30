<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_filter;
use function array_map;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Config\PhpCalls;

use function sprintf;
use function str_contains;

/**
 * A config written as a `mutation-gate.php` (ADR-0002): `Gate::configure()`
 * and one call for each setting it holds, which reads back into the same
 * config.
 */
final readonly class Php
{
    /** The builder classes a config's calls name, in the order their `use` statements are written. */
    private const array CLASSES = [
        'Badge',
        'Baseline',
        'Budget',
        'Ci',
        'Equivalence',
        'Flaky',
        'Floor',
        'Gate',
        'Ignore',
        'Ignores',
        'Load',
        'Local',
        'Option',
        'Pest',
        'Preset',
        'Proofs',
        'Reach',
        'Report',
        'Runner',
        'Shards',
        'Source',
        'Tests',
        'Timeouts',
        'Tree',
        'Uncovered',
    ];

    public static function render(PhpCalls $calls): string
    {
        $gate = array_map(
            static fn(array $call): string => self::call($call[0], $call[1]),
            $calls->gateCalls(),
        );
        $with = $calls->withCalls() === [] ? [] : [self::call('with', $calls->withCalls())];
        $code = implode('', [...$gate, ...$with]);
        $used = array_filter(
            self::CLASSES,
            static fn(string $class): bool => $class === 'Gate' || str_contains($code, sprintf('%s::', $class)),
        );

        return sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\n%s\nreturn Gate::configure()%s;\n",
            implode('', array_map(self::import(...), $used)),
            $code,
        );
    }

    /** The `use` statement that imports one builder class. */
    private static function import(string $class): string
    {
        return sprintf("use NightWorksIO\\MutationGate\\Config\\%s;\n", $class);
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
