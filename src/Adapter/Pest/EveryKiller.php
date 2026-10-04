<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function is_string;

use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;

/**
 * A mutant's own process's arguments under a full kill matrix (ADR-0014,
 * decision 7): pest-plugin-mutate starts each with `--bail`, which stops it
 * at its first failing test, so the plugin drops it there, every covering
 * test runs, and each that fails is recorded. Every other run keeps its
 * arguments.
 */
final readonly class EveryKiller
{
    /** The option that stops a Pest run at its first failure. */
    private const string BAIL = '--bail';

    /**
     * @param  list<string> $arguments
     * @return list<string>
     */
    public static function of(array $arguments, string|false $matrix, string|false $mutated): array
    {
        if ($matrix !== MatrixKind::Full->value || ! is_string($mutated) || $mutated === '') {
            return $arguments;
        }

        $kept = [];

        foreach ($arguments as $argument) {
            $kept = $argument === self::BAIL ? $kept : [...$kept, $argument];
        }

        return $kept;
    }
}
