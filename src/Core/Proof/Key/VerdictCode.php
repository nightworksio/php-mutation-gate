<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_map;
use function array_merge;

use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Glob;

/**
 * The gate's own code a proof's result depends on (ADR-0084): what makes a
 * unit's mutants, runs them and reads what each run left into its record,
 * in sets ({@see VerdictSet}). A key reads the sets every run shares and
 * those of the runner it runs, so a change to the gate outside them, such as
 * its commands, reports, stores or doctor, moves no key.
 * {@see OutsideTheVerdict} holds every other file of the gate, and a file in
 * neither, or in both, fails the gate's own unit tests.
 *
 * @internal
 */
final readonly class VerdictCode
{
    /** What a verdict means beyond the code the sets hold: raised by hand when that changes. */
    public const int SEMANTICS = 1;

    /** The Composer packages of the gate's own mutators, whose code every key reads. */
    public const string PLUGINS = 'nightworksio/mutation-gate-';

    /** The code of each of the gate's mutator packages, from its root. */
    private const string PLUGIN_CODE = 'src/**';

    /** The code of one of the gate's mutator packages, from its root. */
    public static function pluginCode(): Glob
    {
        return Glob::of(self::PLUGIN_CODE);
    }

    /**
     * The globs a run of this runner reads: every run's sets, and the
     * runner's own; every built-in runner's where it is none of them, as an
     * extension's.
     *
     * @return list<Glob>
     */
    public static function of(string $runner): array
    {
        $sets = [
            VerdictSet::Records,
            VerdictSet::Mutators,
            VerdictSet::Processes,
            VerdictSet::Analysers,
            VerdictSet::Running,
        ];
        $builtin = BuiltinRunner::tryFrom($runner);
        $own = $builtin instanceof BuiltinRunner
            ? self::ofBuiltin($builtin)
            : array_merge(...array_map(self::ofBuiltin(...), BuiltinRunner::cases()));

        return array_merge(...array_map(static fn(VerdictSet $set): array => $set->globs(), [...$sets, ...$own]));
    }

    /** @return list<VerdictSet> the sets a built-in runner reads besides every run's */
    private static function ofBuiltin(BuiltinRunner $runner): array
    {
        return match ($runner) {
            BuiltinRunner::Pest => [VerdictSet::Pest],
            BuiltinRunner::Infection => [VerdictSet::Infection],
            BuiltinRunner::PhpUnit => [VerdictSet::PhpUnit, VerdictSet::Native],
            BuiltinRunner::PhpSpec => [VerdictSet::PhpSpec, VerdictSet::Native],
            BuiltinRunner::Codeception => [VerdictSet::Codeception, VerdictSet::Native],
            BuiltinRunner::Testo => [VerdictSet::Testo, VerdictSet::Native],
        };
    }
}
