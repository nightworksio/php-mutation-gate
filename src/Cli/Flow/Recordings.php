<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Heat\FunctionSpans;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\NotRecorded;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Recording;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

use function sprintf;

/**
 * The proof each unit a run judged leaves under its key, or why it leaves
 * none: a unit that did not run to the end, one with no key, and one the
 * run left some mutators out of, which carries their last results and so is
 * of no single run (ADR-0007; ADR-0025). Each proof names the functions of
 * its unit's file that hold its mutants (ADR-0100).
 *
 * @internal
 */
final readonly class Recordings
{
    /** Why a unit the run left some mutators out of leaves no proof. */
    private const string PRUNED = '%s left some mutators out, carrying their last results, so it leaves no proof.';

    /**
     * Each unit the shards ran, with the proof it leaves under its key, or why it leaves none.
     *
     * @return list<array{UnitResult, Proof|NotRecorded}>
     */
    public static function of(Plan $plan, UnitResults $fresh, Run $run, Directory $project): array
    {
        $recordings = [];
        $pruned = $plan->considered()->pruned()->files();

        foreach ($fresh as $result) {
            $path = $result->unit()->path();
            $recording = $pruned->has($path)
                ? NotRecorded::because(sprintf(self::PRUNED, $path->value()))
                : Recording::of(
                    $plan->keys()->keyOf($path),
                    $path,
                    $result->mutants(),
                    $result->flaky(),
                    $run,
                    self::inputsOf($plan, $result),
                );
            $recordings[] = [
                $result,
                $recording instanceof Proof
                    ? $recording->withFunctions(self::functionsOf($result, $project))
                    : $recording,
            ];
        }

        return $recordings;
    }

    /** The functions of a unit's file that hold its mutants, as the file reads now; none where it cannot be read. */
    private static function functionsOf(UnitResult $result, Directory $project): FunctionSpans
    {
        $contents = $project->read($result->unit()->path());
        $lines = [];

        foreach ($result->mutants() as $mutant) {
            $lines[] = $mutant->location()->start();
        }

        return $contents instanceof Contents ? FunctionSpans::in($contents)->holding(...$lines) : FunctionSpans::none();
    }

    /**
     * What a proof of a unit records of its inputs: its share of the plan's
     * digests, with each test file that killed one of its mutants, where the
     * plan names the test.
     */
    private static function inputsOf(Plan $plan, UnitResult $result): Inputs|Undigested
    {
        $digests = $plan->digests();
        $killers = [];

        foreach ($result->mutants() as $mutant) {
            $killers = $mutant->status() === MutantStatus::Killed ? [...$killers, ...$mutant->killers()] : $killers;
        }

        $files = self::filesOf(TestIds::of(...$killers), $plan->names());

        return $digests instanceof Digests ? $digests->inputsOf($result->unit()->path(), $files) : $digests;
    }

    /** The test files these tests are in, where their names say. */
    private static function filesOf(TestIds $tests, TestNames|CannotJudge $names): Paths
    {
        $files = Paths::none();

        foreach ($tests as $test) {
            $named = $names instanceof TestNames ? $names->testOf($test) : $test;
            $files = $named instanceof TestName ? $files->with($named->file()) : $files;
        }

        return $files;
    }
}
