<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * The unmutated control of each kill a test is named for (ADR-0014):
 * the tests that killed it, run with its file served unmutated as its
 * mutant was, allowed the standard mutant limit of their time as the
 * coverage map timed them. A kill whose own run left its filter out, as
 * too long to pass, is controlled as that run selected its tests, by the
 * test files it loaded, its limit that of those files' tests. A file's other
 * kills are first controlled together, by one control of every test that
 * killed any of them, and where it passes every one of them stands
 * (ADR-0033). Where it does not, each kill is controlled on its own, once for each file and set
 * of killers, and stands only where its own passes; where that fails,
 * runs out of the limit or never runs, nothing tells the mutant from
 * its original, and it is unjudged, saying why. A kill no test is named
 * for is controlled by its covering tests instead (see UnnamedKillControls).
 *
 * @internal
 */
final readonly class KillControls implements ControlJudge
{
    /** Why a kill is unjudged where its control fails. */
    public const string FAILS_UNMUTATED
        = 'Killed, but the tests that killed it fail as well with the file unmutated, served as its mutant was.';

    /** Why a kill is unjudged where its control runs out of the memory cap. */
    public const string OUT_OF_MEMORY
        = 'Killed, but the tests that killed it run out of the memory cap with the file unmutated too.';

    /** Why a kill is unjudged where its control runs out of its limit. */
    public const string RAN_OUT
        = 'Killed, but the tests that killed it run out of their limit with the file unmutated too.';

    /** Why a kill is unjudged where its control never ran. */
    public const string UNRUN
        = 'Killed, but no unmutated control of the tests that killed it ran, so nothing vouches for it: %s.';

    /**
     * @param array<string, Control> $files each kill's file's control, by the mutant's gate id
     * @param array<string, Control> $kills each kill's own control, by the mutant's gate id
     */
    private function __construct(private array $files, private array $kills)
    {
    }

    /** The controls of each of these mutants killed by a test named, each limit within these bounds. */
    public static function of(
        Mutants $mutants,
        Evidences $evidence,
        CoverageMap $map,
        RunSelection $run,
        LimitBounds $bounds,
    ): self {
        $kills = [];

        foreach ($mutants as $mutant) {
            if ($mutant->status() === MutantStatus::Killed && count($mutant->killers()) > 0) {
                $kills[$mutant->id()->key()] = self::ownOf($mutant, $evidence, $map, $run, $bounds);
            }
        }

        return new self(self::forFiles($kills, $map, $bounds), $kills);
    }

    /** The control of each file, every test that killed one of its mutants at once, each asked for once. */
    public function asked(): Controls
    {
        return Controls::of(...$this->files);
    }

    /**
     * The control of each kill whose file's control ran in these runs and did
     * not pass, each asked for once; none where it is that same control, whose
     * run is known, or where the file's control was among those left.
     */
    public function fallback(ControlRuns $runs, Controls $left): Controls
    {
        $fallback = [];

        foreach ($this->files as $key => $file) {
            $own = $this->kills[$key];

            if (! $left->has($file) && $own->key() !== $file->key() && $runs->of($file)->end() !== ControlEnd::Passed) {
                $fallback[] = $own;
            }
        }

        return Controls::of(...$fallback);
    }

    /**
     * The mutants, each kill standing where its file's control passed, and
     * otherwise judged by its own: standing where that passed, unjudged,
     * saying why, where it did not, or unjudged, the time budget having run
     * out before either control, where it is among those left.
     */
    public function applied(Mutants $mutants, ControlRuns $runs, Controls $left): Mutants
    {
        $applied = [];

        foreach ($mutants as $mutant) {
            $key = $mutant->id()->key();
            $applied[] = match (true) {
                ! array_key_exists($key, $this->kills) => $mutant,
                $runs->of($this->files[$key])->end() === ControlEnd::Passed => $mutant,
                $left->has($this->files[$key]), $left->has($this->kills[$key])
                    => $mutant->unjudged(OutOfTime::BeforeControlling),
                default => $this->judged($mutant, $runs->of($this->kills[$key]), $this->kills[$key]),
            };
        }

        return Mutants::of(...$applied);
    }

    /** A kill as what its control found judges it. */
    public function judged(Mutant $mutant, ControlRun $run, Control $control): Mutant
    {
        return KillReasons::of(self::FAILS_UNMUTATED, self::OUT_OF_MEMORY, self::RAN_OUT, self::UNRUN)
            ->judged($mutant, $run, $control->tests());
    }

    /** A kill's own control: its killers, or as its run selected them where that run left its filter out. */
    private static function ownOf(
        Mutant $mutant,
        Evidences $evidence,
        CoverageMap $map,
        RunSelection $run,
        LimitBounds $bounds,
    ): Control {
        $killers = $mutant->killers();
        $unfiltered = $evidence->of($mutant->id())->unfiltered();
        $tests = $unfiltered instanceof NotGiven ? $killers : $run->of($killers, $map, $unfiltered);

        return Control::standard($mutant->location()->file(), $tests, $map, $bounds);
    }

    /**
     * What vouches for each kill at once: every killer of its file's kills
     * chosen by their tests alone, and a kill selected otherwise by itself.
     *
     * @param  array<string, Control> $kills by the mutant's gate id
     * @return array<string, Control>
     */
    private static function forFiles(array $kills, CoverageMap $map, LimitBounds $bounds): array
    {
        $byFile = [];

        foreach ($kills as $own) {
            $named = $own->file()->value();
            $known = array_key_exists($named, $byFile) ? $byFile[$named] : TestIds::none();
            $byFile[$named] = $own->selected() instanceof NotGiven ? $known->and($own->tests()) : $known;
        }

        $files = [];

        foreach ($kills as $key => $own) {
            $files[$key] = $own->selected() instanceof NotGiven
                ? Control::standard($own->file(), $byFile[$own->file()->value()], $map, $bounds)
                : $own;
        }

        return $files;
    }

}
