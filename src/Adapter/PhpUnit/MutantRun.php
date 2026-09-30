<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

use function sprintf;
use function str_contains;

/**
 * One mutant, judged by PHPUnit: the tests that cover it run against it, in
 * one process stopped at its limit, and the extension's records say how.
 *
 * - A test that fails or errors kills it, and so does a run that fails after
 *   a test ran.
 * - A run that fails before any test ran errored: the mutant broke PHPUnit
 *   itself, such as by a fatal error as its file loaded.
 * - A run stopped at its limit timed out.
 * - A run that passes with no test run is unjudged: the ids matched no test.
 */
final readonly class MutantRun
{
    private const string NO_TEST_RAN = 'PHPUnit ran none of the %d tests that cover it: no id matched a test.';

    private const string UNLISTABLE
        = 'Every test that covers it has a line break in its name, which PHPUnit cannot select by id.';

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

        return $this->interpreted($made, count($listable), $limit, $ran, Recorded::in($files->results()));
    }

    private function interpreted(MadeMutant $made, int $listed, Seconds $limit, Ran $ran, Recorded $recorded): Mutant
    {
        $took = $ran->took();

        return match (true) {
            $ran->ending() === Ending::Stopped
                => $this->mutant($made, MutantStatus::TimedOut, $took)->withLimit($limit),
            $recorded->ranAny() && $ran->ending() === Ending::Failed
                => $this->mutant($made, MutantStatus::Killed, $took)->killedBy($recorded->killers()),
            $recorded->ranAny() => $this->mutant($made, MutantStatus::Survived, $took),
            $ran->ending() === Ending::Failed => $this->mutant($made, MutantStatus::Errored, $took),
            default => $this->mutant($made, MutantStatus::Unjudged, $took)
                ->because(Reason::that(sprintf(self::NO_TEST_RAN, $listed))),
        };
    }

    private function mutant(MadeMutant $made, MutantStatus $status, Seconds|Unmeasured $duration): Mutant
    {
        return Mutant::of($made->id(), $made->id()->value(), $made->location(), $made->mutation(), $status, $duration);
    }
}
