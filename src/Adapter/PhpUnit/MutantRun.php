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

/**
 * One mutant, judged by PHPUnit: the tests that cover it run against it, in
 * one process stopped at its limit, and the extension's records and the
 * guard say how.
 *
 * - Where opcache could have served a cached original, or the wrapper never
 *   served the mutated file in any of the run's processes, it is unjudged:
 *   the run says nothing of the mutant (ADR-0004 decision 8).
 * - A test that fails or errors kills it, and so does a test that started and
 *   never finished, whose process died as it ran.
 * - A run stopped at its limit timed out.
 * - A run that fails before any test started errored: the mutant broke
 *   PHPUnit itself, such as by a fatal error as its file loaded.
 * - A run that fails with no test failing is unjudged, with what PHPUnit
 *   said: PHPUnit failed it for something else, such as a warning the project
 *   fails on.
 * - A run that passes with no test run, or with every test skipped, is
 *   unjudged.
 */
final readonly class MutantRun
{
    private const string NO_TEST_RAN = 'PHPUnit ran none of the %d tests that cover it: no id matched a test.';

    private const string SKIPPED = 'PHPUnit skipped every test that covers it.';

    private const string UNLISTABLE
        = 'Every test that covers it has a line break in its name, which PHPUnit cannot select by id.';

    private const string NEVER_SERVED
        = 'The mutated file never ran in its place: PHPUnit loaded the file some other way, such as another wrapper.';

    private const string CACHED = 'Opcache ran on the command line, so a cached original could have run in its place.';

    private const string NO_KILLER = "PHPUnit failed the run, though no test that ran failed. PHPUnit said:\n%s";

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
        $recorded = Recorded::in($files->results());
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
            $ran->succeeded() && ! $recorded->ranAny() => Reason::that(sprintf(self::NO_TEST_RAN, $listed)),
            $ran->succeeded() && $recorded->skippedEach() => Reason::that(self::SKIPPED),
            ! $guard->served() => Reason::that(self::NEVER_SERVED),
            default => $this->judgement($ran, $recorded),
        };
    }

    /** How a run that served the mutated file judged it. */
    private function judgement(Ran $ran, Recorded $recorded): MutantStatus|Reason
    {
        return match (true) {
            $ran->wasStopped() => MutantStatus::TimedOut,
            count($recorded->killers()) > 0 => MutantStatus::Killed,
            $ran->succeeded() => MutantStatus::Survived,
            ! $recorded->ranAny() => MutantStatus::Errored,
            default => Reason::that(sprintf(self::NO_KILLER, $ran->output())),
        };
    }

    private function mutant(MadeMutant $made, MutantStatus $status, Seconds|Unmeasured $duration): Mutant
    {
        return Mutant::of($made->id(), $made->id()->value(), $made->location(), $made->mutation(), $status, $duration);
    }
}
