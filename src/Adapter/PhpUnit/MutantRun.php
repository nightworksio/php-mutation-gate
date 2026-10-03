<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;
use NightWorksIO\MutationGate\Core\Runner\Exhaustion;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PrematureEnd;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

use function sprintf;
use function trim;

/**
 * One mutant, judged by PHPUnit: the tests that cover it run against it, in
 * one process stopped at its limit, and the extension's records and the
 * guard say how. They are selected by their ids, or by their test files
 * where PHPUnit cannot read an id back, which runs the other tests of those
 * files too: one of those kills where it fails, though the kill is credited
 * only to tests that cover it, and says nothing where it passes. Where a
 * test is in no file found, the mutant is unjudged without a run.
 *
 * - Where opcache could have served a cached original, or the wrapper never
 *   served the mutated file in any of the run's processes, it is unjudged:
 *   the run says nothing of the mutant (ADR-0004 decision 8).
 * - A test that fails or errors kills it, and so does a test that started and
 *   neither finished nor was skipped or marked incomplete, whose process died
 *   as it ran, and a `setUpBeforeClass` that fails or errors, which kills it
 *   by each test of its class the run selected.
 * - A run stopped at its limit timed out.
 * - A run that killed it, or errored, whose output holds PHP's fatal error
 *   for exactly the memory cap is out of memory, with the cap; under a cap,
 *   so is one where PHPUnit says its process ended mid-test and PHP's errors
 *   were visibly hidden, as PHPUnit says it hid them or as the project's
 *   config shows them nowhere, with no limit known, which memory triage
 *   never counts as a kill (ADR-0004, decision 9).
 * - A run that fails before any test started errored: the mutant broke
 *   PHPUnit itself, such as by a fatal error as its file loaded.
 * - A run that fails with no test failing is unjudged, with what PHPUnit
 *   said: PHPUnit failed it for something else, such as a warning the project
 *   fails on.
 * - A run that passes with no test run, as the selection matched none, is
 *   unjudged, and so is a run with every test skipped or marked incomplete,
 *   with what PHPUnit said where it failed the run, and a run that fails
 *   before any test started or the mutated file ran, with what PHPUnit said.
 */
final readonly class MutantRun
{
    private const string NO_TEST_RAN = 'PHPUnit ran none of the %d tests that cover it: the selection matched no test.';

    private const string SKIPPED = 'PHPUnit skipped, or marked incomplete, every test that covers it.';

    private const string SKIPPED_AND_FAILED
        = 'PHPUnit skipped, or marked incomplete, every test that covers it, and failed the run.';

    private const string STOPPED_UNSERVED
        = 'PHPUnit was stopped at the limit, and the mutated file never ran in its place.';

    private const string UNPLACED
        = 'A test that covers it has a line break in its name, and no test file found holds every test that covers it.';

    private const string NEVER_SERVED
        = 'The mutated file never ran in its place: PHPUnit loaded the file some other way, such as another wrapper.';

    private const string CACHED = 'Opcache ran on the command line, so a cached original could have run in its place.';

    private const string NO_KILLER = 'PHPUnit failed the run, though no test that ran failed.';

    private const string SAID = "%s PHPUnit said:\n%s";

    private const string SAID_NOTHING = '%s PHPUnit said nothing.';

    public function __construct(
        private Project $project,
        private Shell $shell,
        private Invocation $invocation,
        private TestFiles $tests,
        private MemoryScan $scan,
        private ErrorDisplay|NotGiven $display,
    ) {
    }

    public function judged(
        MadeMutant $made,
        TestIds $covering,
        MutationRequest $request,
        Seconds $limit,
    ): Mutant|CannotJudge {
        $files = $this->written($made, $covering);

        if (! $files instanceof MutantFiles) {
            return $files;
        }

        $command = $this->invocation->of($files, $request->judgedBy(), $limit, $request->withheld());
        $ran = $this->shell->run($this->scan->onto($command));
        $recorded = Recorded::in($files->results(), $covering);
        $verdict = $this->verdict($ran, $recorded, Guard::in($files->guard()), count($covering));
        $cap = $request->memory();
        $status = $verdict instanceof Reason ? MutantStatus::Unjudged : $this->weighed($verdict, $ran, $cap);
        $mutant = $this->mutant($made, $status, $ran->duration());

        return match (true) {
            $verdict instanceof Reason => $mutant->because($verdict),
            $status === MutantStatus::TimedOut => $mutant->withLimit($limit),
            $status === MutantStatus::OutOfMemory && Exhaustion::isOf(Exhaustion::in($ran->output()), $cap)
                => $mutant->withLimit($cap),
            $status === MutantStatus::Killed => $mutant->killedBy($recorded->credited()),
            default => $mutant,
        };
    }

    /**
     * What PHPUnit reads for the mutant, selecting its tests by their ids, or
     * by their files where an id cannot be read back; or the mutant unjudged,
     * where a test to be selected by its file is in none found.
     */
    private function written(MadeMutant $made, TestIds $covering): MutantFiles|Mutant|CannotJudge
    {
        if (Selection::of($covering) === Selection::Ids) {
            return MutantFiles::selectingTests($this->project, $made, $covering);
        }

        $classes = $this->tests->declaring($covering);
        $unplaced = Reason::that(self::UNPLACED);

        return $classes->placeEach($covering)
            ? MutantFiles::selectingFiles($this->project, $made, $classes->files())
            : $this->mutant($made, MutantStatus::Unjudged, Unmeasured::duration())->because($unplaced);
    }

    /** How the run judged the mutant, or why it judged nothing. */
    private function verdict(Ran $ran, Recorded $recorded, Guard $guard, int $listed): MutantStatus|Reason
    {
        return match (true) {
            $guard->cached() => Reason::that(self::CACHED),
            $ran->wasStopped() && $guard->served() => MutantStatus::TimedOut,
            $ran->wasStopped() => Reason::that(self::STOPPED_UNSERVED),
            $ran->succeeded() && ! $recorded->ranAny() => Reason::that(sprintf(self::NO_TEST_RAN, $listed)),
            $recorded->skippedEach() => $this->skipped($ran),
            ! $recorded->ranAny() && ! $guard->served() => $this->said(self::NO_KILLER, $ran),
            ! $guard->served() => Reason::that(self::NEVER_SERVED),
            default => $this->judgement($ran, $recorded),
        };
    }

    /** How a run that finished, and served the mutated file, judged it. */
    private function judgement(Ran $ran, Recorded $recorded): MutantStatus|Reason
    {
        return match (true) {
            count($recorded->killers()) > 0 => MutantStatus::Killed,
            $ran->succeeded() => MutantStatus::Survived,
            ! $recorded->ranAny() => MutantStatus::Errored,
            default => $this->said(self::NO_KILLER, $ran),
        };
    }

    /**
     * What a run that killed the mutant, or errored, comes to where the
     * memory cap stopped it: out of memory; any other as it was judged.
     */
    private function weighed(MutantStatus $status, Ran $ran, MemoryCap $cap): MutantStatus
    {
        $output = $ran->output();
        $died = $status === MutantStatus::Killed || $status === MutantStatus::Errored;
        $capped = Exhaustion::isOf(Exhaustion::in($output), $cap)
            || ($cap->caps() && PrematureEnd::hidingIn($output, $this->display === ErrorDisplay::Nowhere));

        return $died && $capped ? MutantStatus::OutOfMemory : $status;
    }

    /** Why a run whose every test was set aside judged nothing, with what PHPUnit said where it failed the run. */
    private function skipped(Ran $ran): Reason
    {
        return $ran->succeeded() ? Reason::that(self::SKIPPED) : $this->said(self::SKIPPED_AND_FAILED, $ran);
    }

    /** Why a run judged nothing, with what PHPUnit said, where it said anything. */
    private function said(string $why, Ran $ran): Reason
    {
        $output = trim($ran->output());

        return Reason::that($output === '' ? sprintf(self::SAID_NOTHING, $why) : sprintf(self::SAID, $why, $output));
    }

    private function mutant(MadeMutant $made, MutantStatus $status, Seconds|Unmeasured $duration): Mutant
    {
        return Mutant::of($made->id(), $made->id()->value(), $made->location(), $made->mutation(), $status, $duration);
    }
}
