<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\Config\KillControl;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\NotGiven;
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

            return self::alone($mutants, $evidence, $map, $run, $bounds, $replayed);
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
     * The controls of each of these kills, run by the tests that killed it,
     * alone. Under `pest.longFilter: until-killer`, a kill whose run left its
     * filter out and whose runner replays its order up to its last killer
     * (ADR-0138) is controlled by its killers too: that replay already ran
     * every test the run executed before them.
     */
    private static function alone(
        Mutants $mutants,
        Evidences $evidence,
        CoverageMap $map,
        RunSelection $run,
        LimitBounds $bounds,
        bool $replayed,
    ): self {
        $kills = [];

        foreach ($mutants as $mutant) {
            if ($mutant->status() === MutantStatus::Killed && count($mutant->killers()) > 0) {
                $found = $replayed ? Evidences::none() : $evidence;
                $kills[$mutant->id()->key()] = self::ownOf($mutant, $found, $map, $run, $bounds);
            }
        }

        return new self(self::forFiles($kills, $map, $run, $bounds), $kills);
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

    /** What replays a kill's run: the tests it started up to its last killer, or all it selected. */
    private static function testsOf(
        Mutant $mutant,
        Evidence $found,
        CoverageMap $map,
        HeldCovered $held,
        RunSelection $run,
    ): TestIds|RunTests {
        $order = $found->order();

        return $order instanceof TestIds
            ? $order
            : Control::judging($mutant, $map, $held, $run, $found->unfiltered());
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
     * chosen by their tests alone; every test file the file's other kills'
     * runs loaded where they left their filter out, selected as those runs
     * selected them; and a kill selected otherwise by itself.
     *
     * @param  array<string, Control> $kills by the mutant's gate id
     * @return array<string, Control>
     */
    private static function forFiles(array $kills, CoverageMap $map, RunSelection $run, LimitBounds $bounds): array
    {
        $killers = self::killersByBatch($kills);
        $loaded = self::loadedByFile($kills);
        $files = [];

        foreach ($kills as $key => $own) {
            $file = $own->file();
            $files[$key] = match (true) {
                $own->selected() instanceof NotGiven
                    => Control::standard($file, $killers[self::batchOf($own)], $map, $bounds),
                $own->selected() instanceof Paths => Control::standard(
                    $file,
                    $run->of($killers[self::batchOf($own)], $map, $loaded[$file->value()]),
                    $map,
                    $bounds,
                ),
                default => $own,
            };
        }

        return $files;
    }

    /**
     * Every test of each batch's kills: a file's kills chosen by their tests'
     * names, and apart from them its kills whose runs left their filter out.
     *
     * @param  array<string, Control> $kills by the mutant's gate id
     * @return array<string, TestIds> by the batch
     */
    private static function killersByBatch(array $kills): array
    {
        $killers = [];

        foreach ($kills as $own) {
            $batch = self::batchOf($own);
            $killers[$batch] = (array_key_exists($batch, $killers) ? $killers[$batch] : TestIds::none())
                ->and($own->tests());
        }

        return $killers;
    }

    /**
     * Every test file the runs of each file's kills loaded where they left their filter out.
     *
     * @param  array<string, Control> $kills by the mutant's gate id
     * @return array<string, Paths> by the file's path
     */
    private static function loadedByFile(array $kills): array
    {
        $loaded = [];

        foreach ($kills as $own) {
            $file = $own->file()->value();
            $known = array_key_exists($file, $loaded) ? $loaded[$file] : Paths::none();
            $selected = $own->selected();
            $loaded[$file] = $selected instanceof Paths ? $known->and($selected) : $known;
        }

        return $loaded;
    }

    /**
     * The batch a kill's control is first run in: its file's kills chosen by
     * their tests' names, or its file's kills whose runs left their filter
     * out; a kill selected otherwise is a batch of its own.
     */
    private static function batchOf(Control $own): string
    {
        $selected = $own->selected();

        return $selected instanceof NotGiven || $selected instanceof Paths
            ? JsonText::compact([$own->file()->value(), $selected instanceof Paths])
            : $own->key();
    }
}
