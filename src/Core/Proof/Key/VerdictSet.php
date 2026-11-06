<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_map;

use NightWorksIO\MutationGate\Core\File\Glob;

/**
 * One set of the gate's own code a proof's result depends on (ADR-0084), by
 * its globs from the gate's root.
 *
 * @internal
 */
enum VerdictSet
{
    /** What a run records of each mutant, and the values it records them in. */
    case Records;
    /** The engine that makes mutants for a native runner and registered mutators, and the opening it reads after. */
    case Mutators;
    /** How the gate starts, watches and ends the processes a run runs. */
    case Processes;
    /** The static analysers and opcache, whose findings a record holds. */
    case Analysers;
    /** The flows that build a shard's run, run it and check what it found. */
    case Running;
    /** What every native runner shares. */
    case Native;
    /** Pest, its patch and plugin, the PHPUnit issue reader the plugin loads and the package files the patch reads. */
    case Pest;
    /** Infection and its patch, with the package files the patch reads. */
    case Infection;
    /** PHPUnit. */
    case PhpUnit;
    /** PhpSpec. */
    case PhpSpec;
    /** Codeception. */
    case Codeception;
    /** Testo. */
    case Testo;

    /** @return list<Glob> */
    public function globs(): array
    {
        $globs = match ($this) {
            self::Records => [
                'src/Core/Mutant/**',
                'src/Core/Native/**',
                'src/Core/Runner/**',
                'src/Core/Control/**',
                'src/Core/Coverage/**',
                'src/Core/Test/**',
                'src/Core/Hold/**',
                'src/Core/Analysis/**',
                'src/Core/Matrix/**',
                'src/Core/Order/**',
                'src/Core/Pruning/**',
                'src/Core/Removal/**',
                'src/Core/Assertion/**',
                'src/Core/Unit/**',
                'src/Core/Config/**',
                'src/Core/Php/**',
                'src/Core/File/**',
                'src/Core/Format/**',
                'src/Core/Time/**',
                'src/Core/CannotJudge.php',
                'src/Core/NotGiven.php',
                'src/Core/WholeNumber.php',
                'src/Attribute/**',
                'src/Port/Runner.php',
                'src/Port/Processes.php',
                'src/Port/StaticChecker.php',
            ],
            self::Mutators => [
                'src/Mutator/**',
                'src/Core/Hint/Change.php',
            ],
            self::Processes => [
                'src/Adapter/Process/**',
                'src/Adapter/Runtime/**',
            ],
            self::Analysers => [
                'src/Adapter/PhpStan/**',
                'src/Adapter/Psalm/**',
                'src/Adapter/Mago/**',
                'src/Adapter/Opcache/**',
            ],
            self::Running => [
                'src/Cli/Flow/Running.php',
                'src/Cli/Flow/PartitionedRuns.php',
                'src/Cli/Flow/PathGroup.php',
                'src/Cli/Flow/PathGroups.php',
                'src/Cli/Flow/Ruling.php',
                'src/Cli/Flow/RunRequest.php',
                'src/Cli/Flow/Invoking.php',
                'src/Cli/Flow/PreChecking.php',
                'src/Cli/Flow/SurvivorChecking.php',
                'src/Cli/Flow/HeldCoverage.php',
                'src/Cli/Flow/StartUpTiming.php',
                'src/Cli/Flow/Wiring.php',
                'src/Cli/Flow/Invoked.php',
                'src/Cli/Flow/SurvivorBatch.php',
                'src/Cli/Flow/Confirmed.php',
                'src/Cli/Flow/Controlled.php',
                'src/Cli/Flow/Equivalents.php',
                'src/Cli/Flow/StaticEquivalence.php',
                'src/Cli/Flow/Mutated.php',
                'src/Cli/Flow/NarrowRunHolding.php',
                'src/Cli/Flow/Batched.php',
                'src/Cli/Flow/Unbatched.php',
                'src/Cli/Flow/DoomWatch.php',
                'src/Cli/Flow/Checked.php',
                'src/Cli/Flow/LeftSurvivors.php',
                'src/Cli/Flow/CheckRoom.php',
                'src/Cli/Flow/Cores.php',
                'src/Cli/Flow/ProjectMemoryLimit.php',
                'src/Cli/Flow/WarmedUp.php',
                'src/Cli/Flow/AnalyserWarmUp.php',
                'src/Cli/Flow/Dependents.php',
                'src/Cli/Flow/Suite.php',
                'src/Cli/Flow/SuiteCoverage.php',
                'src/Cli/Flow/TestsOfTheSuite.php',
                'src/Adapter/Project/**',
                'src/Adapter/Filesystem/MutatedFiles.php',
                'src/Core/Cost/StartUpSamples.php',
            ],
            self::Native => [
                'src/Adapter/Native/**',
            ],
            self::Pest => [
                'src/Adapter/Pest/**',
                'src/Cli/PestPatch.php',
                'src/Cli/PestPlugin.php',
                'src/Adapter/PhpUnit/IssueKillers.php',
                'src/Core/Composer/PackageFiles.php',
                'src/Core/Composer/PackageSource.php',
                'src/Adapter/Filesystem/PackageDirectory.php',
                'bin/mutation-gate-pest-*',
            ],
            self::Infection => [
                'src/Adapter/Infection/**',
                'src/Cli/InfectionPatch.php',
                'src/Core/Composer/PackageFiles.php',
                'src/Core/Composer/PackageSource.php',
                'src/Adapter/Filesystem/PackageDirectory.php',
            ],
            self::PhpUnit => [
                'src/Adapter/PhpUnit/**',
                'bin/mutation-gate-worker',
            ],
            self::PhpSpec => [
                'src/Adapter/PhpSpec/**',
                'bin/mutation-gate-phpspec',
                'src/Core/Composer/Package.php',
            ],
            self::Codeception => [
                'src/Adapter/Codeception/**',
            ],
            self::Testo => [
                'src/Adapter/Testo/**',
                'bin/mutation-gate-testo',
            ],
        };

        return array_map(Glob::of(...), $globs);
    }
}
