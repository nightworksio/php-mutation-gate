<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

use function sprintf;
use function str_contains;
use function trim;

/**
 * One mutant, judged by PHPUnit: the tests that cover it run against it, in
 * one process stopped at its limit, and the extension's records and the
 * guard say how.
 *
 * - Where opcache could have served a cached original, or the wrapper never
 *   served the mutated file in any of the run's processes, it is unjudged:
 *   the run says nothing of the mutant (ADR-0004 decision 8).
 * - A test that fails or errors kills it, and so does a test that started and
 *   neither finished nor was skipped or marked incomplete, whose process died
 *   as it ran, and a `setUpBeforeClass` that fails or errors, which kills it
 *   by each test of its class the run selected.
 * - A run stopped at its limit timed out.
 * - A run that fails before any test started errored: the mutant broke
 *   PHPUnit itself, such as by a fatal error as its file loaded.
 * - A run that fails with no test failing is unjudged, with what PHPUnit
 *   said: PHPUnit failed it for something else, such as a warning the project
 *   fails on.
 * - A run that passes with no test run is unjudged, and so is a run with
 *   every test skipped or marked incomplete, with what PHPUnit said where it
 *   failed the run, and a run that fails before any test started or the
 *   mutated file ran, with what PHPUnit said.
 */
final readonly class MutantRun
{
    private const string NO_TEST_RAN = 'PHPUnit ran none of the %d tests that cover it: no id matched a test.';

    private const string SKIPPED = 'PHPUnit skipped, or marked incomplete, every test that covers it.';

    private const string SKIPPED_AND_FAILED
        = 'PHPUnit skipped, or marked incomplete, every test that covers it, and failed the run.';

    private const string STOPPED_UNSERVED
        = 'PHPUnit was stopped at the limit, and the mutated file never ran in its place.';

    private const string UNLISTABLE
        = 'Every test that covers it has a line break in its name, which PHPUnit cannot select by id.';

    private const string NEVER_SERVED
        = 'The mutated file never ran in its place: PHPUnit loaded the file some other way, such as another wrapper.';

    private const string CACHED = 'Opcache ran on the command line, so a cached original could have run in its place.';

    private const string NO_KILLER = 'PHPUnit failed the run, though no test that ran failed.';

    private const string SAID = "%s PHPUnit said:\n%s";

    private const string SAID_NOTHING = '%s PHPUnit said nothing.';

    /** What a line of the id file cannot hold. */
    private const string LINE_BREAK = "\n";

    public function __construct(private Project $project, private Shell $shell, private Invocation $invocation)
    {
    }

    public function judged(
        MadeMutant $made,
        TestIds $covering,
        MutationRequest $request,
        Seconds $limit,
    ): Mutant|CannotJudge {
        $listable = TestIds::none();

        foreach ($covering as $test) {
            $listable = str_contains($test->value(), self::LINE_BREAK) ? $listable : $listable->with($test);
        }

        if (count($listable) === 0) {
            return $this->mutant($made, MutantStatus::Unjudged, Unmeasured::duration())
                ->because(Reason::that(self::UNLISTABLE));
        }

        $files = MutantFiles::writtenFor($this->project, $made, $listable);

        if ($files instanceof CannotJudge) {
            return $files;
        }

        $ran = $this->shell->run($this->invocation->of($files, $request->judgedBy(), $limit, $request->withheld()));
        $recorded = Recorded::in($files->results(), $listable);
        $verdict = $this->verdict($ran, $recorded, Guard::in($files->guard()), count($listable));
        $status = $verdict instanceof Reason ? MutantStatus::Unjudged : $verdict;
        $mutant = $this->mutant($made, $status, $ran->duration());

        return match (true) {
            $verdict instanceof Reason => $mutant->because($verdict),
            $verdict === MutantStatus::TimedOut => $mutant->withLimit($limit),
            $verdict === MutantStatus::Killed => $mutant->killedBy($recorded->killers()),
            default => $mutant,
        };
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
