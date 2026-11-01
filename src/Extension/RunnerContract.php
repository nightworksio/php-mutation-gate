<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension;

use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Extension\Checks\Records;
use NightWorksIO\MutationGate\Extension\Checks\RunnerAnswers;
use NightWorksIO\MutationGate\Extension\Checks\RunnerCoverage;
use NightWorksIO\MutationGate\Extension\Checks\RunnerMutants;
use NightWorksIO\MutationGate\Extension\Checks\RunnerRequests;
use NightWorksIO\MutationGate\Extension\Checks\RunnerSurvivors;
use NightWorksIO\MutationGate\Port\Runner;

/**
 * What every runner answers (ADR-0001, ADR-0004), for the gate's own runners
 * and an extension's to be held to alike, over one library the runner's
 * author installs as a project of its own:
 *
 * - its code is `resources/contract/runner/src`, copied into the library's
 *   `src`, whose `Library` namespace its Composer autoloading maps;
 * - its tests are the files `LibraryTest` lists, written in the runner's own
 *   framework, each asserting what its case's docblock says;
 * - its runner is the runner under test, built in the library's root, with a
 *   limit on each mutant short enough that `LibraryChange::Drains` times out.
 *
 * `RunnerLibrary` says how the runner names each `LibraryChange`'s mutator.
 *
 * A runner keeps the contract where it answers an identity, the same each
 * time, its groups with the one that holds `src/Held.php`, and a run of no
 * test that takes time; names the files that define it from the project's
 * root, no ignore marker in code with none, and no project in `src`; reports
 * each change as `LibraryChange` says, with an id of its own in the gate's
 * spelling, how long it took where it measures that, a limit on the timed-out
 * one alone, the steps its time went to, and how far its kill went; reports
 * only the files asked for, each alike alone and beside another, and the held
 * file by its group alone; runs its survivor again, reproduces it, and gives
 * it as a static analyser checks it; and measures which tests run which line,
 * alike when read back, across two processes, and file by file.
 */
final readonly class RunnerContract
{
    /**
     * Every way a runner breaks the contract over the library, each said as a sentence; none where it keeps it.
     *
     * @return Listed<string>
     */
    public static function failures(Runner $runner, RunnerLibrary $library): Listed
    {
        $money = LibraryChange::Adds->file();
        $mutators = $library->mutators(
            LibraryChange::Adds,
            LibraryChange::Large,
            LibraryChange::Unused,
            LibraryChange::Drains,
        );
        $result = $runner->mutate(
            MutationRequest::of(Paths::of($money), WholeSuite::tests())
                ->narrowedTo(Paths::of($money), Narrowing::none()->toMutators($mutators)),
        );

        return Listed::of(...[
            ...RunnerAnswers::failures($runner),
            ...RunnerMutants::failures($result, $library),
            ...RunnerRequests::failures($runner, $library),
            ...RunnerSurvivors::failures($runner, Records::having($result, MutantStatus::Survived)),
            ...RunnerCoverage::failures($runner, $library),
        ]);
    }
}
