<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\Config\KillControl;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * The unmutated control of each kill a test is named for (ADR-0014): its
 * run again in its order, with its file served unmutated as its mutant was,
 * allowed the standard mutant limit of the tests' time as the coverage map
 * timed them, so a test that fails only after the tests before it kills
 * nothing (ADR-0143). That is the tests its run started up to its last
 * killer where the runner recorded them, and otherwise every test its run
 * selected; a file's kills are first controlled by the longest of its
 * orders that starts with a kill's own, which vouches for every shorter one
 * (ADR-0089). A runner that replays each kill's order itself, before this,
 * and `flaky.killControls: alone`, have this control run the tests that
 * killed it instead. A kill whose own run left its filter out, as
 * too long to pass, is controlled as that run selected its tests, by the
 * test files it loaded, its limit that of those files' tests. A file's
 * kills are first controlled together, and where that control passes every
 * one of them stands (ADR-0033): those chosen by their tests' names by every
 * test that killed any of them, and those whose runs left their filter out
 * by every test file any of those runs loaded. Where it does not, each kill
 * is controlled on its own, once for each file and set of killers, and
 * stands only where its own passes; where that fails, runs out of the limit
 * or never runs, nothing tells the mutant from its original, and it is
 * unjudged, saying why. A kill no test is named for is controlled by its
 * covering tests instead (see UnnamedKillControls).
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

    /**
     * The controls of each of these mutants killed by a test named, each
     * limit within these bounds: each kill's run replayed in its order, or
     * its killers alone where the config or the runner says so.
     */
    public static function of(
        Mutants $mutants,
        Evidences $evidence,
        CoverageMap $map,
        HeldCovered $held,
        RunSelection $run,
        LimitBounds $bounds,
        KillControl $control = KillControl::Order,
        LongFilter $long = LongFilter::Files,
    ): self {
        if ($control === KillControl::Alone || $run->replaysKills()) {
            $replayed = $run->replaysKills() && $long === LongFilter::UntilKiller;

            $alone = KillersAlone::of($mutants, $evidence, $map, $run, $bounds, $replayed);

            return new self($alone->files(), $alone->kills());
        }

        $tests = [];

        foreach ($mutants as $mutant) {
            if ($mutant->status() === MutantStatus::Killed && count($mutant->killers()) > 0) {
                $tests[$mutant->id()->key()] = [
                    $mutant->location()->file(),
                    self::testsOf($mutant, $evidence->of($mutant->id()), $map, $held, $run),
                ];
            }
        }

        return new self(
            self::controlled(KillOrders::vouchingFor($tests, alone: false), $map, $bounds),
            self::controlled($tests, $map, $bounds),
        );
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

    /**
     * @param  array<string, array{Path, TestIds|RunTests}> $tests
     * @return array<string, Control>
     */
    private static function controlled(array $tests, CoverageMap $map, LimitBounds $bounds): array
    {
        $controls = [];

        foreach ($tests as $key => [$file, $of]) {
            $controls[$key] = Control::standard($file, $of, $map, $bounds);
        }

        return $controls;
    }

    /**
     * What replays a kill's run: the tests it started up to its last killer,
     * or all it selected and its killers, which the map may not place on the
     * mutant's lines, as on a signature a visibility mutant changes.
     */
    private static function testsOf(
        Mutant $mutant,
        Evidence $found,
        CoverageMap $map,
        HeldCovered $held,
        RunSelection $run,
    ): TestIds|RunTests {
        $order = $found->order();

        return $order instanceof TestIds ? $order : $run->of(
            Control::covering($mutant, $map, $held)->and($mutant->killers()),
            $map,
            $found->unfiltered(),
        );
    }
}
