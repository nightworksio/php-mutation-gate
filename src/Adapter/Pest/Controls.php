<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_combine;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;

use Closure;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\KillReasons;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

/**
 * The doubtful kills of a narrowed run, each with the control of its run
 * again with every test file (see Control): the tests that cover it, as
 * Pest's filter selects them, or the tests that judge the run where that
 * filter is too long to pass, as Pest then runs them. They are read off the
 * run's coverage before the run again replaces it. A kill the run again
 * confirms stands only where its control passes; otherwise its tests fail
 * with the file unmutated as well, so they cannot tell the mutant from the
 * original, and it is unjudged.
 */
final readonly class Controls
{
    /** Why a kill confirmed again is unjudged where its control fails. */
    public const string FAILS_UNMUTATED
        = 'Killed, but its covering tests fail as well with the file unmutated, served as its mutant was.';

    /** Why a kill confirmed again is unjudged where its control never ran. */
    private const string UNRUN
        = 'Killed, but no unmutated control of its covering tests ran, so nothing vouches for it: %s.';

    /** Why a kill confirmed again is unjudged where its control runs out of its time. */
    private const string RAN_OUT = 'Killed, but its covering tests run out of their time with the file unmutated too.';

    /** Why a kill confirmed again is unjudged where its control runs out of the memory cap. */
    private const string OUT_OF_MEMORY
        = 'Killed, but its covering tests run out of the memory cap with the file unmutated too.';

    /** Why a kill confirmed again is unjudged where its control cannot be read off the run's coverage. */
    private const string UNREAD = 'Killed, but its covering tests cannot be told, so nothing vouches for the kill: %s';

    /**
     * @param array<string, Control> $controls each doubtful kill's control, by its gate id
     * @param array<string, TestIds> $covering each doubtful kill's covering tests, by its gate id
     * @param string                 $unread   why no kill has a control, where the coverage cannot be read
     */
    private function __construct(
        private Mutants $doubtful,
        private array $controls,
        private array $covering,
        private string $unread = '',
    ) {
    }

    /**
     * The doubtful kills' controls, the coverage read only where there are
     * any; none where it cannot be read, and then every kill confirmed again
     * is unjudged, saying why.
     *
     * @param Closure(): (Covering|CannotJudge) $coverage the coverage the run's mutants were selected by
     */
    public static function of(
        Project $project,
        Mutants $doubtful,
        WholeSuite|Group|Filter $judgedBy,
        Closure $coverage,
    ): self {
        if (count($doubtful) === 0) {
            return new self($doubtful, [], []);
        }

        $covering = $coverage();

        if ($covering instanceof CannotJudge) {
            return new self($doubtful, [], [], sprintf(self::UNREAD, $covering->why()));
        }

        $controls = [];
        $tests = [];

        foreach ($doubtful as $mutant) {
            $location = $mutant->location();
            $file = DiskPath::of($project->absolute($location->file()));
            $tests[$mutant->id()->key()] = $covering->testsCovering($file, $location->start(), $location->last());
            $selection = Selection::of($tests[$mutant->id()->key()]);
            $controls[$mutant->id()->key()] = Control::of(
                $location->file(),
                Paths::none(),
                $selection->fits() ? $selection->filter() : $judgedBy,
            );
        }

        return new self($doubtful, $controls, $tests);
    }

    public function doubtful(): Mutants
    {
        return $this->doubtful;
    }

    /**
     * The mutants run again, each kill standing where its control passed,
     * its tests failing without it where the control failed, and unjudged
     * otherwise, saying why.
     */
    public function applied(
        Mutants $again,
        AloneRuns $runs,
        MutationRequest $request,
        Seconds|Unlimited $left,
        string $results,
    ): Mutants {
        $killed = [];

        foreach ($again as $mutant) {
            $key = $mutant->id()->key();
            $killed += $mutant->status() === MutantStatus::Killed && array_key_exists($key, $this->controls)
                ? [$key => $this->controls[$key]]
                : [];
        }

        $ended = $killed === []
            ? []
            : array_combine(array_keys($killed), $runs->ended(array_values($killed), $request, $left, $results));

        return Mutants::of(...array_map(fn(Mutant $mutant): Mutant => $this->judged($mutant, $ended), [...$again]));
    }

    /**
     * A mutant run again as its control judges it: anything but a kill as it
     * was found, and a kill by how its control ended.
     *
     * @param array<string, ControlRun> $ended how each kill's control ended, by its gate id
     */
    private function judged(Mutant $mutant, array $ended): Mutant
    {
        $key = $mutant->id()->key();
        $run = array_key_exists($key, $ended) ? $ended[$key] : ControlRun::unrun(ControlRuns::NOT_RUN);
        $covering = array_key_exists($key, $this->covering) ? $this->covering[$key] : TestIds::none();

        return match (true) {
            $mutant->status() !== MutantStatus::Killed => $mutant,
            $this->unread !== '' => Interpretation::unjudged($mutant, Reason::that($this->unread)),
            default => KillReasons::of(self::FAILS_UNMUTATED, self::OUT_OF_MEMORY, self::RAN_OUT, self::UNRUN)
                ->judged($mutant, $run, $covering),
        };
    }
}
